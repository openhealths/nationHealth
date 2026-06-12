<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\JobStatus;
use App\Jobs\EncounterFullSync;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Repositories\MedicalEvents\Repository;
use App\Traits\BatchLegalEntityQueries;
use App\Traits\HandlesSyncBatch;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use Throwable;

class PatientEncounters extends BasePatientComponent
{
    use BatchLegalEntityQueries;
    use HandlesSyncBatch;
    use WithPagination;

    public array $episodes = [];

    public array $originEpisodes = [];

    public array $incomingReferrals = [];

    public string $syncStatus = '';

    public string $filterStartDateRange = '';

    public string $filterEndDateRange = '';

    public string $filterEpisodeId = '';

    public string $filterIncomingReferralId = '';

    public string $filterOriginEpisodeId = '';

    public bool $showAdditionalParams = false;

    public array $dictionaryNames = [
        'eHealth/encounter_classes',
        'eHealth/encounter_types',
        'SPECIALITY_TYPE'
    ];

    protected function initializeComponent(): void
    {
        $this->getDictionary();

        $this->syncStatus = legalEntity()->getEntityStatus(LegalEntity::ENTITY_ENCOUNTER) ?? '';

        $this->loadFilterOptions();
    }

    #[Computed]
    public function paginatedEncounters(): LengthAwarePaginator
    {
        return $this->isSearching
            ? $this->searchEncountersFromEHealth()
            : $this->paginateLocalEncounters();
    }

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

        $this->loadFilterOptions();

        $this->isSearching = false;
        $this->resetPage();
    }

    public function search(): void
    {
        $this->validate($this->filterValidationRules());
        $this->isSearching = true;
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset([
            'filterStartDateRange',
            'filterEndDateRange',
            'filterEpisodeId',
            'filterIncomingReferralId',
            'filterOriginEpisodeId',
            'isSearching'
        ]);
        $this->resetPage();
    }

    /**
     * Validation rules for the encounter search filters.
     *
     * @return array
     */
    protected function filterValidationRules(): array
    {
        return [
            'filterStartDateRange' => ['nullable', 'string', 'max:255'],
            'filterEndDateRange' => ['nullable', 'string', 'max:255'],
            'filterEpisodeId' => ['nullable', 'uuid'],
            'filterIncomingReferralId' => ['nullable', 'uuid'],
            'filterOriginEpisodeId' => ['nullable', 'uuid']
        ];
    }

    /**
     * Load the episode and referral options used to populate the search filters.
     *
     * @return void
     */
    protected function loadFilterOptions(): void
    {
        $this->episodes = Repository::episode()->getByPersonId($this->personId);

        $encounters = Encounter::forPerson($this->personId)
            ->with(['incomingReferral.type.coding', 'originEpisode.type.coding'])
            ->get();

        // Name from the record, with the value as a fallback.
        $this->incomingReferrals = $encounters->pluck('incomingReferral')
            ->filter()
            ->map(static fn (Identifier $referral): array => [
                'uuid' => $referral->value,
                'displayValue' => $referral->displayValue ?? $referral->value
            ])
            ->unique('uuid')
            ->values()
            ->toArray();

        // Name from the record, with the value as a fallback.
        $this->originEpisodes = $encounters->pluck('originEpisode')
            ->filter()
            ->map(static fn (Identifier $episode): array => [
                'uuid' => $episode->value,
                'displayValue' => $episode->displayValue ?? $episode->value
            ])
            ->unique('uuid')
            ->values()
            ->toArray();
    }

    /**
     * Paginate locally stored (synced) encounters straight from the database.
     *
     * @return LengthAwarePaginator
     */
    protected function paginateLocalEncounters(): LengthAwarePaginator
    {
        $paginator = Encounter::forPerson($this->personId)
            ->withRelationships()
            ->recentlyUpdatedFirst()
            ->paginate(config('pagination.per_page'));

        $paginator->setCollection(
            $paginator->getCollection()->map(function (Encounter $encounter) {
                $data = Arr::toCamelCase($encounter->toArray());
                $data['id'] = $encounter->id;

                return $data;
            })
        );

        return $paginator;
    }

    /**
     * Fetch a single page of encounters from the eHealth API for the active search filters.
     *
     * @return LengthAwarePaginator
     */
    protected function searchEncountersFromEHealth(): LengthAwarePaginator
    {
        $perPage = config('pagination.per_page');
        $page = $this->getPage();

        // todo: add period params after change in frontend
        $params = array_filter([
            'managing_organization_id' => legalEntity()->uuid,
            'episode_id' => $this->filterEpisodeId ?: null,
            'incoming_referral_id' => $this->filterIncomingReferralId ?: null,
            'origin_episode_id' => $this->filterOriginEpisodeId ?: null,
            'page' => $page,
            'page_size' => $perPage
        ]);

        try {
            $response = EHealth::encounter()->getBySearchParams($this->uuid, $params);
            $encounters = Arr::toCamelCase($this->formatDatesForDisplay($response->validate()));
            $total = $response->getPaging()['total_entries'];
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while searching encounters');
            $encounters = [];
            $total = 0;
        }

        return new LengthAwarePaginator(collect($encounters), $total, $perPage, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath()
        ]);
    }

    public function render(): View
    {
        return view('livewire.person.records.encounters');
    }
}
