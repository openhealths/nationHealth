<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\JobStatus;
use App\Jobs\EncounterFullSync;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Repositories\MedicalEvents\Repository;
use App\Traits\BatchLegalEntityQueries;
use App\Traits\HandlesSyncBatch;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use Throwable;

class PatientEncounters extends BasePatientComponent
{
    use BatchLegalEntityQueries;
    use HandlesSyncBatch;

    public array $encounters = [];

    public array $encounterIdMap = [];

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

        $this->episodes = Episode::wherePersonId($this->personId)->get()->toArray();

        $encountersModel = Encounter::wherePersonId($this->personId)->withRelationships()->get();

        $this->encounters = Arr::toCamelCase($this->formatDatesForDisplay($encountersModel->makeVisible('id')->toArray()));
        $this->populateDbIds();
        $this->incomingReferrals = $encountersModel->pluck('incomingReferral')
            ->filter()
            ->map(fn (Identifier $referral) => [
                'uuid' => $referral->value,
                'displayValue' => $referral->displayValue
            ])
            ->unique('uuid')
            ->values()
            ->toArray();

        $this->originEpisodes = $encountersModel->pluck('originEpisode')
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
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while synchronizing encounters');

            return;
        }

        try {
            $validatedData = $response->validate();
            Repository::encounter()->sync($this->personId, $validatedData);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while synchronizing encounters');

            return;
        }

        if ($response->isNotLast()) {
            $this->dispatchRemainingPages('encounter');
        } else {
            legalEntity()->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_ENCOUNTER);
            Session::flash('success', __('patients.messages.encounters_synced_successfully'));
        }

        $this->encounters = Arr::toCamelCase($this->formatDatesForDisplay($validatedData));
        $this->populateDbIds();
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
            $this->populateDbIds();
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while searching encounters');
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

    private function populateDbIds(): void
    {
        $uuids = collect($this->encounters)->pluck('uuid')->filter()->toArray();
        if (empty($uuids)) {
            return;
        }

        $dbMap = Encounter::whereIn('uuid', $uuids)->pluck('id', 'uuid')->toArray();

        foreach ($this->encounters as &$encounter) {
            $uuid = $encounter['uuid'] ?? null;
            if ($uuid && isset($dbMap[$uuid])) {
                $encounter['id'] = $dbMap[$uuid];
            }
        }
        unset($encounter);
    }

    public function render(): View
    {
        return view('livewire.person.records.encounters');
    }
}
