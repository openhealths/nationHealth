<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\Person\EpisodeStatus;
use App\Rules\InDictionary;
use Illuminate\Validation\Rule;
use App\Enums\JobStatus;
use App\Jobs\EpisodeFullSync;
use App\Models\Icd10;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Episode;
use App\Repositories\MedicalEvents\Repository;
use App\Traits\BatchLegalEntityQueries;
use App\Traits\HandlesSyncBatch;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Session;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use Throwable;

class PatientEpisodes extends BasePatientComponent
{
    use BatchLegalEntityQueries;
    use HandlesSyncBatch;
    use WithPagination;

    public string $syncStatus = '';

    public string $filterPeriodDateRange = '';

    public string $filterCode = '';

    public string $filterStatus = '';

    public bool $showAdditionalParams = false;

    public bool $showCancellationModal = false;
    public ?string $cancellingEpisodeUuid = null;
    public string $cancellationReason = '';
    public string $explanatoryLetter = '';

    public bool $showClosureModal = false;
    public ?string $closingEpisodeUuid = null;
    public string $closingDate = '';
    public string $closingReason = '';
    public string $closingSummary = '';

    protected array $dictionaryNames = ['eHealth/ICPC2/condition_codes'];

    /**
     * ICD-10 dictionary matches (code and description) for the search autocomplete.
     *
     * @var array
     */
    public array $icd10Results = [];

    protected function initializeComponent(): void
    {
        $this->getDictionary();

        $this->syncStatus = legalEntity()->getEntityStatus(LegalEntity::ENTITY_EPISODE) ?? '';
    }

    #[Computed]
    public function paginatedEpisodes(): LengthAwarePaginator
    {
        return $this->isSearching
            ? $this->searchEpisodesFromEHealth()
            : $this->paginateLocalEpisodes();
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
            $response = EHealth::episode()->getBySearchParams(
                $this->uuid,
                ['managing_organization_id' => legalEntity()->uuid]
            );
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while synchronizing episodes');

            return;
        }

        try {
            $validatedData = $response->validate();
            Repository::episode()->syncFull($this->patient(), $validatedData);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while synchronizing episodes');

            return;
        }

        if ($response->isNotLast()) {
            $this->dispatchRemainingPages('episode');
        } else {
            legalEntity()->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_EPISODE);
            Session::flash('success', __('patients.messages.episodes_synced_successfully'));
        }

        $this->isSearching = false;
        $this->resetPage();
    }

    public function searchICD10(string $value): void
    {
        $this->icd10Results = Icd10::search($value)->limit(50)
            ->get(['code', 'description'])
            ->toArray();
    }

    public function resetFilters(): void
    {
        $this->reset(['filterPeriodDateRange', 'filterCode', 'filterStatus', 'isSearching']);
        $this->resetPage();
    }

    public function search(): void
    {
        $this->validate($this->filterValidationRules());
        $this->isSearching = true;
        $this->resetPage();
    }

    /**
     * Validation rules for the episode search filters.
     *
     * @return array
     */
    protected function filterValidationRules(): array
    {
        return [
            'filterCode' => [
                'nullable',
                'string',
                new InDictionary(['eHealth/ICPC2/condition_codes', 'eHealth/ICD10_AM/condition_codes'])
            ],
            'filterStatus' => ['nullable', Rule::in(array_keys(EpisodeStatus::searchableOptions()))],
            'filterPeriodDateRange' => ['nullable', 'string', 'max:255']
        ];
    }

    /**
     * Paginate locally stored (synced) episodes straight from the database.
     *
     * @return LengthAwarePaginator
     */
    protected function paginateLocalEpisodes(): LengthAwarePaginator
    {
        $paginator = Episode::forPatient($this->patient())
            ->withRelationships()
            ->recentlyUpdatedFirst()
            ->paginate(config('pagination.per_page'));

        $paginator->setCollection(collect(Arr::toCamelCase($paginator->getCollection()->toArray())));

        return $paginator;
    }

    /**
     * Fetch a single page of episodes from the eHealth API for the active search filters.
     *
     * @return LengthAwarePaginator
     */
    protected function searchEpisodesFromEHealth(): LengthAwarePaginator
    {
        $perPage = config('pagination.per_page');
        $page = $this->getPage();

        // todo: add period params after change in frontend
        $params = array_filter([
            'code' => $this->filterCode ?: null,
            'status' => $this->filterStatus ?: null,
            'managing_organization_id' => legalEntity()->uuid,
            'page' => $page,
            'page_size' => $perPage
        ]);

        try {
            $response = EHealth::episode()->getBySearchParams($this->uuid, $params);
            $episodes = Arr::toCamelCase($this->formatDatesForDisplay($response->validate()));
            $total = $response->getPaging()['total_entries'];
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while searching episodes');
            $episodes = [];
            $total = 0;
        }

        return new LengthAwarePaginator(collect($episodes), $total, $perPage, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath()
        ]);
    }

    public function openEpisodeCancellation(string $uuid): void {}

    public function closeEpisodeCancellationModal(): void {}

    public function cancelSelectedEpisode(): void {}

    public function openEpisodeClosure(string $uuid): void {}

    public function closeEpisodeClosureModal(): void {}

    public function closeSelectedEpisode(): void {}

    public function render(): View
    {
        return view('livewire.person.records.episodes');
    }
}
