<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Classes\eHealth\EHealth;
use App\Enums\JobStatus;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Jobs\CarePlanFullSync;
use App\Models\LegalEntity;
use App\Repositories\CarePlanRepository;
use App\Traits\HandlesSyncBatch;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Session;
use Throwable;

class PersonCarePlans extends BasePatientComponent
{
    use HandlesSyncBatch;

    public string $syncStatus = '';

    public $carePlans = [];

    public string $filterName = '';
    public string $filterEncounterId = '';
    public string $filterStatus = '';
    public string $filterStartDateRange = '';
    public string $filterEndDateRange = '';
    public string $filterIsPartOf = '';
    public string $filterIncludes = '';
    public bool $showAdditionalParams = false;

    /**
     * Initialize component with care plans for the specific patient.
     */
    protected function initializeComponent(): void
    {
        $this->loadCarePlans();

        try {
            $basics = app(\App\Services\Dictionary\DictionaryManager::class)->basics();
            $this->dictionaries['care_plan_categories'] = $basics->byName('eHealth/care_plan_categories')
                ?->asCodeDescription()
                ?->toArray() ?? [];
        } catch (\Exception $exception) {
            \Illuminate\Support\Facades\Log::warning('PersonCarePlans: failed to load dictionaries: ' . $exception->getMessage());
        }
        
        $status = legalEntity()->getEntityStatus(LegalEntity::ENTITY_CARE_PLAN);
        $this->syncStatus = $status instanceof JobStatus ? $status->value : ($status ?? '');
    }

    protected function getSyncStatus(string $entityType): ?string
    {
        return $this->syncStatus ?: null;
    }

    protected function getBatchName(string $entityType): string
    {
        return CarePlanFullSync::BATCH_NAME;
    }

    protected function getJobClass(string $entityType): string
    {
        return CarePlanFullSync::class;
    }

    protected function getEntityConstant(string $entityType): string
    {
        return LegalEntity::ENTITY_CARE_PLAN;
    }

    protected function onSyncStatusChanged(string $entityType, JobStatus $status): void
    {
        $this->syncStatus = $status->value;
    }

    /**
     * Load care plans from the database.
     */
    public function loadCarePlans(): void
    {
        $this->carePlans = app(CarePlanRepository::class)->getByPersonId($this->personId);
    }

    public function sync(): void
    {
        if ($this->cannotStartSync('care_plan')) {
            return;
        }

        if ($this->shouldResumeSync('care_plan')) {
            $this->handleResumeLogic('care_plan');
            return;
        }

        try {
            $response = EHealth::carePlan()->getBySearchParams(
                $this->uuid,
                ['managing_organization_id' => legalEntity()->uuid]
            );
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error while synchronizing care plans');
            return;
        }

        try {
            $validatedData = $response->validate();
            app(CarePlanRepository::class)->syncCarePlans($validatedData, $this->personId);
        } catch (Throwable $exception) {
            $this->logDatabaseErrors($exception, 'Error while synchronizing care plans');
            Session::flash('error', __('patients.messages.care_plan_sync_database_error') ?? 'Помилка збереження планів лікування');
            return;
        }

        if ($response->isNotLast()) {
            $this->dispatchRemainingPages('care_plan');
        } else {
            legalEntity()->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_CARE_PLAN);
            Session::flash('success', __('patients.sync_success') ?? 'Синхронізація успішна');
        }

        $this->loadCarePlans();
    }

    public function search(): void
    {
        $this->loadCarePlans();
    }

    public function resetFilters(): void
    {
        $this->reset([
            'filterName',
            'filterEncounterId',
            'filterStatus',
            'filterStartDateRange',
            'filterEndDateRange',
            'filterIsPartOf',
            'filterIncludes',
        ]);
    }

    public function render(): View
    {
        return view('livewire.person.records.care-plans');
    }
}
