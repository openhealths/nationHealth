<?php

declare(strict_types=1);

namespace App\Livewire\Episode;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\Episode\Status;
use App\Enums\JobStatus;
use App\Enums\Person\EncounterStatus;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Jobs\EpisodeFullSync;
use App\Livewire\Episode\Forms\EpisodeCancellationForm;
use App\Livewire\Episode\Forms\EpisodeClosingForm;
use App\Livewire\Person\Records\BasePatientComponent;
use App\Models\Icd10;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Episode;
use App\Repositories\MedicalEvents\Repository;
use App\Rules\InDictionary;
use App\Services\MedicalEvents\Fhir;
use App\Traits\BatchLegalEntityQueries;
use App\Traits\HandlesSyncBatch;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use Throwable;

class EpisodeIndex extends BasePatientComponent
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

    public bool $showClosingModal = false;

    public EpisodeCancellationForm $cancellationForm;

    public EpisodeClosingForm $closingForm;

    protected array $dictionaryNames = [
        'eHealth/ICPC2/condition_codes',
        'eHealth/cancellation_reasons',
        'eHealth/episode_closing_reasons'
    ];

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
            Session::flash('success', __('episodes.messages.synced_successfully'));
        }

        $this->isSearching = false;
        $this->resetPage();
    }

    public function searchICD10(string $value): void
    {
        $this->icd10Results = Icd10::search($value)->active()->limit(50)
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
            'filterStatus' => ['nullable', Rule::in(array_keys(Status::searchableOptions()))],
            'filterPeriodDateRange' => [
                'nullable',
                'string',
                'regex:/^\d{2}\.\d{2}\.\d{4}( — \d{2}\.\d{2}\.\d{4})?$/u'
            ]
        ];
    }

    /**
     * Redefine filter names for error messages.
     *
     * @return array
     */
    public function validationAttributes(): array
    {
        return [
            'filterCode' => __('patients.filter_code'),
            'filterStatus' => __('forms.status.label'),
            'filterPeriodDateRange' => __('patients.filter_created_at_range')
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
            ->forLegalEntity()
            ->with(['period', 'managingOrganization.type.coding', 'careManager.type.coding'])
            ->recentlyUpdatedFirst()
            ->paginate(config('pagination.per_page'));

        $paginator->setCollection(
            collect(Arr::toCamelCase($paginator->getCollection()->makeVisible('id')->toArray()))
        );

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

        // The picker keeps both bounds in one field, the API takes them as separate params
        $period = array_map('trim', explode('—', $this->filterPeriodDateRange));

        $params = array_filter([
            'code' => $this->filterCode ?: null,
            'status' => $this->filterStatus ?: null,
            'period_from' => convertToYmd($period[0] ?? ''),
            'period_to' => convertToYmd($period[1] ?? ''),
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

    /**
     * Open the page of an episode that a search in eHealth returned without a local record behind it.
     * Its pages are bound to a local episode, so the missing one is pulled in on the way there.
     *
     * @param  string  $id  eHealth ID of the episode
     * @param  bool  $forEditing
     * @return void
     */
    public function openEpisode(string $id, bool $forEditing = false): void
    {
        $episode = $this->findOrPullEpisode($id);

        if ($episode === null) {
            return;
        }

        $route = $forEditing ? 'episodes.edit' : 'episodes.view';

        if ($this->prepersonId !== null) {
            $this->redirectRoute(
                "prepersons.$route",
                [legalEntity(), 'preperson' => $this->prepersonId, 'episode' => $episode->id],
                navigate: true
            );

            return;
        }

        $this->redirectRoute(
            "persons.$route",
            [legalEntity(), 'person' => $this->personId, 'episode' => $episode->id],
            navigate: true
        );
    }

    /**
     * Get the episode by its eHealth ID. A search in eHealth returns episodes that are not stored locally yet,
     * so the missing one is pulled in instead of turning the user away.
     *
     * @param  string  $id  eHealth ID of the episode
     * @return Episode|null
     */
    private function findOrPullEpisode(string $id): ?Episode
    {
        $episode = Episode::forPatient($this->patient())->forLegalEntity()->whereUuid($id)->first();

        if ($episode !== null) {
            return $episode;
        }

        try {
            $response = EHealth::episode()->getById($this->uuid, $id);
            Repository::episode()->syncFull($this->patient(), [$response->validate()]);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while getting episode');

            return null;
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to store episode');

            return null;
        }

        return Episode::forPatient($this->patient())->forLegalEntity()->whereUuid($id)->first();
    }

    /**
     * Ask for the reason the episode is being marked as entered in error.
     *
     * @param  string  $id
     * @return void
     */
    public function openEpisodeCancellation(string $id): void
    {
        $this->cancellationForm->reset();
        $this->cancellationForm->cancellingId = $id;
        $this->resetValidation();
        $this->showCancellationModal = true;
    }

    /**
     * Mark the selected episode as entered in error in eHealth and store the outcome locally.
     *
     * @return void
     */
    public function cancelSelectedEpisode(): void
    {
        $episode = $this->findOrPullEpisode($this->cancellationForm->cancellingId);

        if ($episode === null) {
            return;
        }

        if (Auth::user()->cannot('cancel', $episode)) {
            Session::flash('error', __('episodes.policy.cancel'));

            return;
        }

        // eHealth rejects the cancellation until every encounter of the episode is marked as entered in error
        $hasEncountersToCancel = Encounter::forEpisode($episode->uuid)
            ->where('status', '!=', EncounterStatus::ENTERED_IN_ERROR)
            ->exists();

        if ($hasEncountersToCancel) {
            Session::flash('error', __('episodes.messages.has_encounters_to_cancel'));

            return;
        }

        $formattedData = Fhir::episode()->toCancelFhir($this->cancellationForm->validate());

        try {
            EHealth::episode()->cancel(
                $this->uuid,
                $episode->uuid,
                removeEmptyKeys(Arr::toSnakeCase($formattedData))
            );
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while cancelling episode');

            return;
        }

        // eHealth accepted the cancellation; only now persist it locally
        try {
            Repository::episode()->markAsEnteredInError($episode, $formattedData);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to store cancelled episode');

            return;
        }

        $this->showCancellationModal = false;

        Session::flash('success', __('episodes.messages.cancelled'));
    }

    /**
     * Ask for the moment, the reason and the summary the episode is closed with.
     *
     * @param  string  $id
     * @return void
     */
    public function openEpisodeClosing(string $id): void
    {
        $this->closingForm->reset();
        $this->closingForm->closingId = $id;
        $this->closingForm->closingDate = now()->format(config('app.date_format'));
        $this->closingForm->closingTime = now()->format('H:i');
        $this->resetValidation();
        $this->showClosingModal = true;
    }

    /**
     * Close the selected episode in eHealth and store the outcome locally.
     *
     * @return void
     */
    public function closeSelectedEpisode(): void
    {
        $episode = $this->findOrPullEpisode($this->closingForm->closingId);

        if ($episode === null) {
            return;
        }

        if (Auth::user()->cannot('close', $episode)) {
            Session::flash('error', __('episodes.policy.close'));

            return;
        }

        $episode->loadMissing('period');

        $this->closingForm->periodStart = $episode->period?->start ?? '';

        $formattedData = Fhir::episode()->toCloseFhir($this->closingForm->validate());

        try {
            EHealth::episode()->close(
                $this->uuid,
                $episode->uuid,
                removeEmptyKeys(Arr::toSnakeCase($formattedData))
            );
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while closing episode');

            return;
        }

        // eHealth accepted the closing; only now persist it locally
        try {
            Repository::episode()->markAsClosed($episode, $formattedData);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to store closed episode');

            return;
        }

        $this->showClosingModal = false;

        Session::flash('success', __('episodes.messages.closed'));
    }

    public function render(): View
    {
        return view('livewire.episode.episode-index');
    }
}
