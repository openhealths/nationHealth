<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Classes\eHealth\EHealth;

use App\Repositories\CarePlanRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Session;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use Throwable;

class PersonCarePlans extends BasePatientComponent
{
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
    }

    /**
     * Load care plans from the database.
     */
    public function loadCarePlans(): void
    {
        $this->carePlans = app(CarePlanRepository::class)->getByPersonId($this->personId, [
            'name' => $this->filterName,
            'status' => $this->filterStatus,
            'encounter_id' => $this->filterEncounterId,
        ]);
    }

    public function sync(): void
    {
        // Sync patient's declarations first to support auto-activation logic
        try {
            $decResponse = EHealth::declaration()->getMany(
                ['person_id' => $this->uuid],
                groupByEntities: true
            );
            $decValidated = $decResponse->validate();
            app(\App\Repositories\DeclarationRepository::class)->storeMany($decValidated, legalEntity());
        } catch (\Throwable $exception) {
            \Illuminate\Support\Facades\Log::warning('PersonCarePlans: failed to sync declarations for patient during care plan sync', [
                'patient_uuid' => $this->uuid,
                'error' => $exception->getMessage()
            ]);
        }

        try {
            $response = EHealth::carePlan()->getBySearchParams(
                $this->uuid,
                [] // Removed managing_organization_id to see all plans
            );

            \Illuminate\Support\Facades\Log::info('PersonCarePlans: sync response', [
                'count' => count($response->getData()),
                'patient_uuid' => $this->uuid
            ]);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while synchronizing care plans');

            return;
        }

        try {
            $validatedData = $response->validate();
            app(CarePlanRepository::class)->syncCarePlans($validatedData, $this->personId);
        } catch (Throwable $exception) {
            \Illuminate\Support\Facades\Log::error('PersonCarePlans: sync error', [
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString()
            ]);
            $this->handleDatabaseErrors($exception, 'Error while synchronizing care plans');

            return;
        }

        Session::flash('success', __('patients.sync_success') ?? 'Синхронізація успішна');
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
