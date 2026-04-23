<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\JobStatus;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Jobs\EpisodeFullSync;
use App\Models\LegalEntity;
use App\Repositories\MedicalEvents\Repository;
use App\Traits\BatchLegalEntityQueries;
use App\Traits\HandlesSyncBatch;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Session;
use Throwable;

class PatientEpisodes extends BasePatientComponent
{
    use BatchLegalEntityQueries;
    use HandlesSyncBatch;

    public array $episodes = [];

    public string $syncStatus = '';

    public string $filterPeriodDateRange = '';

    public string $filterCode = '';

    public string $filterStatus = '';

    public bool $showAdditionalParams = false;

    protected array $dictionaryNames = ['eHealth/ICD10_AM/condition_codes', 'eHealth/ICPC2/condition_codes'];

    protected function initializeComponent(): void
    {
        $this->getDictionary();
    }

    protected function getSyncStatus(string $entityType): ?string
    {
        return $this->syncStatus ?: null;
    }

    protected function getBatchName(string $entityType): string
    {
        return EpisodeFullSync::BATCH_NAME;
    }

    protected function getJobClass(string $entityType): string
    {
        return EpisodeFullSync::class;
    }

    protected function getEntityConstant(string $entityType): string
    {
        return LegalEntity::ENTITY_EPISODE;
    }

    protected function onSyncStatusChanged(string $entityType, JobStatus $status): void
    {
        $this->syncStatus = $status->value;
    }

    public function sync(): void
    {
        if ($this->cannotStartSync('episode')) {
            return;
        }

        if ($this->shouldResumeSync('episode')) {
            $this->handleResumeLogic('episode');

            return;
        }

        try {
            $response = EHealth::encounter()->getBySearchParams(
                $this->uuid,
                ['managing_organization_id' => legalEntity()->uuid]
            );
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error while synchronizing encounters');

            return;
        }

        try {
            $validatedData = $response->validate();
            Repository::episode()->sync($this->personId, $validatedData);
        } catch (Throwable $exception) {
            $this->logDatabaseErrors($exception, 'Error while synchronizing encounters');
            Session::flash('error', __('patients.messages.encounter_sync_database_error'));

            return;
        }

        if ($response->isNotLast()) {
            $this->dispatchRemainingPages('episode');
        } else {
            legalEntity()->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_EPISODE);
            Session::flash('success', __('patients.messages.encounters_synced_successfully'));
        }

        $this->episodes = Arr::toCamelCase($this->formatDatesForDisplay($validatedData));
    }

    public function search(): void
    {
        // todo: add period params after change in frontend
        $params = array_filter([
            'code' => $this->filterCode ?: null,
            'status' => $this->filterStatus ?: null,
            'managing_organization_id' => legalEntity()->uuid
        ]);

        try {
            $response = EHealth::episode()->getBySearchParams($this->uuid, $params);
            $this->episodes = Arr::toCamelCase($this->formatDatesForDisplay($response->validate()));
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error while searching encounters');
        }
    }

    public function render(): View
    {
        return view('livewire.person.records.episodes');
    }
}
