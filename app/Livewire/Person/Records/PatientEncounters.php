<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\JobStatus;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Jobs\EncounterFullSync;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\Person\Person;
use App\Repositories\MedicalEvents\Repository;
use App\Traits\BatchLegalEntityQueries;
use App\Traits\HandlesSyncBatch;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;
use Throwable;

class PatientEncounters extends BasePatientComponent
{
    use BatchLegalEntityQueries;
    use HandlesSyncBatch;

    public array $encounters = [];

    public array $episodes = [];
    public array $originEpisodes = [];
    public array $incomingReferrals = [];

    public string $syncStatus = '';

    public string $filterStartDateRange = '';

    public string $filterEndDateRange = '';

    public string $filterEpisode = '';

    public string $filterIncomingReferral = '';

    public string $filterOriginEpisode = '';

    public bool $showAdditionalParams = false;

    public array $dictionaryNames = [
        'eHealth/encounter_classes',
        'eHealth/encounter_types',
        'SPECIALITY_TYPE'
    ];

    protected function getSyncStatus(string $entityType): ?string
    {
        return $this->syncStatus ?: null;
    }

    protected function getBatchName(string $entityType): string
    {
        return EncounterFullSync::BATCH_NAME;
    }

    protected function getJobClass(string $entityType): string
    {
        return EncounterFullSync::class;
    }

    protected function getEntityConstant(string $entityType): string
    {
        return LegalEntity::ENTITY_ENCOUNTER;
    }

    protected function onSyncStatusChanged(string $entityType, JobStatus $status): void
    {
        $this->syncStatus = $status->value;
    }

    protected function initializeComponent(): void
    {
        $this->getDictionary();

        $status = legalEntity()->getEntityStatus(LegalEntity::ENTITY_ENCOUNTER);
        $this->syncStatus = $status instanceof JobStatus ? $status->value : ($status ?? '');

        $person = Person::whereId($this->personId)
            ->with(['episodes:person_id,uuid,name', 'encounters.incomingReferral', 'encounters.originEpisode'])
            ->first();

        $this->episodes = $person->episodes->toArray();

        $this->incomingReferrals = $person->encounters
            ->pluck('incomingReferral')
            ->filter()
            ->map(fn (Identifier $referral) => [
                'uuid' => $referral->value,
                'displayValue' => $referral->displayValue
            ])
            ->unique('uuid')
            ->values()
            ->toArray();

        $this->originEpisodes = $person->encounters
            ->pluck('originEpisode')
            ->filter()
            ->map(fn (Identifier $referral) => [
                'uuid' => $referral->value,
                'displayValue' => $referral->displayValue
            ])
            ->unique('uuid')
            ->values()
            ->toArray();
    }

    public function sync(): void
    {
        if ($this->cannotStartSync('encounter')) {
            return;
        }

        if ($this->shouldResumeSync('encounter')) {
            $this->handleResumeLogic('encounter');

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
            Repository::encounter()->sync($this->personId, $validatedData);
        } catch (Throwable $exception) {
            $this->logDatabaseErrors($exception, 'Error while synchronizing encounters');
            Session::flash('error', __('patients.messages.encounter_sync_database_error'));

            return;
        }

        if ($response->isNotLast()) {
            $this->dispatchRemainingPages('encounter');
        } else {
            legalEntity()->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_ENCOUNTER);
            Session::flash('success', __('patients.messages.encounters_synced_successfully'));
        }

        $this->encounters = Arr::toCamelCase($this->formatDatesForDisplay($validatedData));
    }

    public function search(): void
    {
        // todo: add period params after change in frontend
        $params = array_filter([
            'managing_organization_id' => legalEntity()->uuid,
            'episode_id' => $this->filterEpisode ?: null,
            'incoming_referral_id' => $this->filterIncomingReferral ?: null,
            'origin_episode_id' => $this->filterOriginEpisode ?: null
        ]);

        try {
            $response = EHealth::encounter()->getBySearchParams($this->uuid, $params);
            $this->encounters = Arr::toCamelCase($this->formatDatesForDisplay($response->validate()));
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error while searching encounters');
        }
    }

    public function resetFilters(): void
    {
        $this->reset([
            'filterStartDateRange',
            'filterEndDateRange',
            'filterEpisode',
            'filterIncomingReferral',
            'filterOriginEpisode'
        ]);
    }

    public function render(): View
    {
        return view('livewire.person.records.encounters');
    }
}
