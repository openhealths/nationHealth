<?php

declare(strict_types=1);

namespace App\Livewire\DetectedIssue;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\JobStatus;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Jobs\DetectedIssueSync;
use App\Livewire\EncounterPackage\Forms\EncounterCancellationForm;
use App\Livewire\Person\Records\BasePatientComponent;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\DetectedIssue;
use App\Models\MedicalEvents\Sql\Device;
use App\Repositories\MedicalEvents\Repository;
use App\Rules\InDictionary;
use App\Traits\BatchLegalEntityQueries;
use App\Traits\HandlesEncounterCancellation;
use App\Traits\HandlesSyncBatch;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use Throwable;

class DetectedIssueIndex extends BasePatientComponent
{
    use BatchLegalEntityQueries;
    use HandlesEncounterCancellation;
    use HandlesSyncBatch;
    use WithPagination;

    public EncounterCancellationForm $form;

    /**
     * Filter dropdown options the user can pick from to narrow the detected issues search.
     *
     * @var array
     */
    public array $encounters = [];

    public array $episodes = [];

    public array $employees = [];

    public array $devices = [];

    /**
     * Bound search filter values applied when querying detected issues.
     *
     * @var string
     */
    public string $filterDeviceId = '';

    public string $filterEncounterId = '';

    public string $filterStatus = '';

    public string $filterEpisodeId = '';

    public string $filterRecorder = '';

    public string $filterIdentifiedDateTimeFrom = '';

    public string $filterIdentifiedDateTimeTo = '';

    public string $filterInsertedAtFrom = '';

    public string $filterInsertedAtTo = '';

    public bool $showAdditionalParams = false;

    public string $syncStatus = '';

    protected array $dictionaryNames = [
        'POSITION',
        'eHealth/cancellation_reasons',
        'detected_issue_statuses',
        'detected_issue_codes',
        'eHealth/report_origins'
    ];

    /**
     * {@inheritDoc}
     */
    protected function getSyncStatus(string $entityType): ?string
    {
        return $this->syncStatus ?: null;
    }

    /**
     * {@inheritDoc}
     */
    protected function getBatchName(string $entityType): string
    {
        return DetectedIssueSync::BATCH_NAME;
    }

    /**
     * {@inheritDoc}
     */
    protected function getJobClass(string $entityType): string
    {
        return DetectedIssueSync::class;
    }

    /**
     * {@inheritDoc}
     */
    protected function getEntityConstant(string $entityType): string
    {
        return LegalEntity::ENTITY_DETECTED_ISSUE;
    }

    /**
     * {@inheritDoc}
     */
    protected function onSyncStatusChanged(string $entityType, JobStatus $status): void
    {
        $this->syncStatus = $status->value;
    }

    /**
     * {@inheritDoc}
     */
    protected function initializeComponent(): void
    {
        $this->getDictionary();

        $this->syncStatus = legalEntity()->getEntityStatus(LegalEntity::ENTITY_DETECTED_ISSUE) ?? '';

        $this->loadFilterOptions();
    }

    /**
     * Detected issues of the current page: from eHealth while searching, otherwise the locally stored ones.
     *
     * @return LengthAwarePaginator
     */
    #[Computed]
    public function paginatedIssues(): LengthAwarePaginator
    {
        return $this->isSearching
            ? $this->searchIssuesFromEHealth()
            : $this->paginateLocalIssues();
    }

    /**
     * Validate the filters and switch the list to the eHealth search.
     *
     * @return void
     */
    public function search(): void
    {
        if (Auth::user()->cannot('viewAny', DetectedIssue::class)) {
            Session::flash('error', __('detected-issues.policy.view_any'));

            return;
        }

        $this->validate($this->filterValidationRules());

        $this->isSearching = true;
        $this->resetPage();
    }

    /**
     * Store the first page of the patient's detected issues from eHealth and hand the remaining pages to the queue.
     *
     * @return void
     */
    public function sync(): void
    {
        if (Auth::user()->cannot('viewAny', DetectedIssue::class)) {
            Session::flash('error', __('detected-issues.policy.sync'));

            return;
        }

        if ($this->cannotStartSync('detected_issue')) {
            return;
        }

        if ($this->shouldResumeSync('detected_issue')) {
            $this->handleResumeLogic('detected_issue');

            return;
        }

        try {
            $response = EHealth::detectedIssue()->getBySearchParams(
                $this->uuid,
                ['recorder_legal_entity_id' => legalEntity()->uuid]
            );
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while synchronizing detected issues');

            return;
        }

        try {
            Repository::detectedIssue()->sync($this->patient(), $response->validate());
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while synchronizing detected issues');

            return;
        }

        if ($response->isNotLast()) {
            $this->dispatchRemainingPages('detected_issue');
        } else {
            legalEntity()->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_DETECTED_ISSUE);
            Session::flash('success', __('detected-issues.messages.synced_successfully'));
        }

        $this->loadFilterOptions();

        $this->isSearching = false;
        $this->resetPage();
    }

    /**
     * Open the page of a detected issue found through the eHealth search, storing it first when it is not in the database yet.
     *
     * @param  string  $detectedIssueId
     * @return void
     */
    public function view(string $detectedIssueId): void
    {
        if (Auth::user()->cannot('view', DetectedIssue::class)) {
            Session::flash('error', __('detected-issues.policy.view'));

            return;
        }

        $detectedIssue = DetectedIssue::forPatient($this->patient())->whereUuid($detectedIssueId)->first()
            ?? $this->storeSearchedIssue($detectedIssueId);

        if ($detectedIssue === null) {
            return;
        }

        if ($this->prepersonId !== null) {
            $this->redirectRoute(
                'prepersons.device-issues.view',
                [legalEntity(), 'preperson' => $this->prepersonId, 'detectedIssue' => $detectedIssue->id],
                navigate: true
            );

            return;
        }

        $this->redirectRoute(
            'persons.device-issues.view',
            [legalEntity(), 'person' => $this->personId, 'detectedIssue' => $detectedIssue->id],
            navigate: true
        );
    }

    /**
     * Clear the filters and go back to the locally stored detected issues.
     *
     * @return void
     */
    public function resetFilters(): void
    {
        $this->reset([
            'filterDeviceId',
            'filterEncounterId',
            'filterStatus',
            'filterEpisodeId',
            'filterRecorder',
            'filterIdentifiedDateTimeFrom',
            'filterIdentifiedDateTimeTo',
            'filterInsertedAtFrom',
            'filterInsertedAtTo',
            'isSearching'
        ]);

        $this->resetPage();
    }

    /**
     * Store a detected issue found through the eHealth search, so that it has a page to open.
     *
     * @param  string  $detectedIssueId
     * @return DetectedIssue|null
     */
    protected function storeSearchedIssue(string $detectedIssueId): ?DetectedIssue
    {
        try {
            $response = EHealth::detectedIssue()->getById($this->uuid, $detectedIssueId);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while loading the detected issue');

            return null;
        }

        try {
            Repository::detectedIssue()->sync($this->patient(), [$response->validate()]);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while storing the detected issue');

            return null;
        }

        return DetectedIssue::forPatient($this->patient())->whereUuid($detectedIssueId)->first();
    }

    /**
     * Paginate locally stored (synced) detected issues straight from the database.
     *
     * @return LengthAwarePaginator
     */
    protected function paginateLocalIssues(): LengthAwarePaginator
    {
        $paginator = DetectedIssue::forPatient($this->patient())
            ->notInDraftEncounter($this->patient())
            ->withAllRelations()
            ->recentlyUpdatedFirst()
            ->paginate(config('pagination.per_page'));

        // The id is hidden on the model but the list links to the detected issue page by it
        $paginator->setCollection(
            collect(Arr::toCamelCase($paginator->getCollection()->makeVisible('id')->toArray()))
        );

        return $paginator;
    }

    /**
     * Fetch a single page of detected issues from the eHealth API for the active search filters.
     *
     * @return LengthAwarePaginator
     */
    protected function searchIssuesFromEHealth(): LengthAwarePaginator
    {
        $perPage = config('pagination.per_page');
        $page = $this->getPage();

        // Detected issues are only readable within the legal entity that recorded them, so the search is scoped to it
        $params = array_filter([
            'device_id' => $this->filterDeviceId ?: null,
            'encounter_id' => $this->filterEncounterId ?: null,
            'episode_id' => $this->filterEpisodeId ?: null,
            'status' => $this->filterStatus ?: null,
            'recorder' => $this->filterRecorder ?: null,
            'recorder_legal_entity_id' => legalEntity()->uuid,
            'inserted_at_from' => $this->filterInsertedAtFrom ?: null,
            'inserted_at_to' => $this->filterInsertedAtTo ?: null,
            'identified_date_time_from' => $this->filterIdentifiedDateTimeFrom
                ? CarbonImmutable::createFromFormat(config('app.date_format'), $this->filterIdentifiedDateTimeFrom)
                    ->startOfDay()
                    ->utc()
                    ->toIso8601ZuluString()
                : null,
            'identified_date_time_to' => $this->filterIdentifiedDateTimeTo
                ? CarbonImmutable::createFromFormat(config('app.date_format'), $this->filterIdentifiedDateTimeTo)
                    ->endOfDay()
                    ->utc()
                    ->toIso8601ZuluString()
                : null,
            'page' => $page,
            'page_size' => $perPage
        ]);

        try {
            $response = EHealth::detectedIssue()->getBySearchParams($this->uuid, $params);
            $issues = Arr::toCamelCase($this->formatDatesForDisplay($response->validate(), 'd.m.Y H:i'));
            $total = $response->getPaging()['total_entries'];
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while loading detected issues');
            $issues = [];
            $total = 0;
        }

        return new LengthAwarePaginator(collect($issues), $total, $perPage, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath()
        ]);
    }

    /**
     * Load the options the filter dropdowns offer.
     *
     * @return void
     */
    protected function loadFilterOptions(): void
    {
        $this->episodes = Repository::episode()->getByPersonId($this->patient());
        $this->encounters = Repository::encounter()->getByPersonId($this->patient());

        $this->devices = Device::forPatient($this->patient())
            ->with('names')
            ->get()
            ->map(static fn (Device $device): array => [
                'uuid' => $device->uuid,
                'name' => $device->names->first()?->value ?? $device->uuid
            ])
            ->toArray();

        $this->employees = Employee::whereLegalEntityId(legalEntity()->id)
            ->active()
            ->select(['uuid', 'party_id', 'position'])
            ->with('party:id,last_name,first_name,second_name')
            ->get()
            ->map(fn (Employee $employee): array => [
                'uuid' => $employee->uuid,
                'name' => $employee->fullName . ' - '
                    . ($this->dictionaries['POSITION'][$employee->position] ?? $employee->position)
            ])
            ->toArray();
    }

    /**
     * Validation rules for the search filters.
     *
     * @return array
     */
    protected function filterValidationRules(): array
    {
        return [
            'filterDeviceId' => ['nullable', 'uuid'],
            'filterEncounterId' => ['nullable', 'uuid'],
            'filterEpisodeId' => ['nullable', 'uuid'],
            'filterStatus' => ['nullable', 'string', new InDictionary('detected_issue_statuses')],
            'filterRecorder' => ['nullable', 'uuid'],
            'filterIdentifiedDateTimeFrom' => ['nullable', 'date_format:' . config('app.date_format')],
            'filterIdentifiedDateTimeTo' => ['nullable', 'date_format:' . config('app.date_format')],
            'filterInsertedAtFrom' => ['nullable', 'date_format:' . config('app.date_format')],
            'filterInsertedAtTo' => ['nullable', 'date_format:' . config('app.date_format')]
        ];
    }

    /**
     * @inheritDoc
     */
    protected function encounterCancellationForm(): EncounterCancellationForm
    {
        return $this->form;
    }

    /**
     * @inheritDoc
     */
    protected function afterEncounterCancelled(): void
    {
        $this->isSearching = false;
        $this->resetPage();
    }

    public function render(): View
    {
        return view('livewire.detected-issue.detected-issues');
    }
}
