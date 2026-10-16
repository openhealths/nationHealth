<?php

declare(strict_types=1);

namespace App\Livewire\Condition;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\JobStatus;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Jobs\ConditionSync;
use App\Livewire\Person\Records\BasePatientComponent;
use App\Models\Declaration;
use App\Models\Icd10;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Approval;
use App\Models\MedicalEvents\Sql\Condition;
use App\Repositories\MedicalEvents\Repository;
use App\Rules\InDictionary;
use App\Traits\BatchLegalEntityQueries;
use App\Traits\HandlesSyncBatch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use Throwable;

class ConditionIndex extends BasePatientComponent
{
    use BatchLegalEntityQueries;
    use HandlesSyncBatch;
    use WithPagination;

    public array $encounters = [];

    public array $episodes = [];

    public string $filterCode = '';

    public string $filterEncounterId = '';

    public string $filterEpisodeId = '';

    public string $filterOnsetDateFrom = '';

    public string $filterOnsetDateTo = '';

    public bool $showAdditionalParams = false;

    public string $syncStatus = '';

    protected array $dictionaryNames = [
        'eHealth/ICPC2/condition_codes',
        'eHealth/ICD10/condition_codes',
        'eHealth/encounter_classes',
        'eHealth/body_sites',
        'eHealth/condition_severities',
        'eHealth/condition_clinical_statuses',
        'eHealth/condition_verification_statuses',
        'eHealth/ICPC2/reasons',
        'eHealth/report_origins',
        'eHealth/resources',
    ];

    /**
     * ICD-10 dictionary matches (code and description) for the search autocomplete.
     *
     * @var array
     */
    public array $icd10Results = [];

    protected function getSyncStatus(string $entityType): ?string
    {
        return $this->syncStatus ?: null;
    }

    protected function getBatchName(string $entityType): string
    {
        return ConditionSync::BATCH_NAME;
    }

    protected function getJobClass(string $entityType): string
    {
        return ConditionSync::class;
    }

    protected function getEntityConstant(string $entityType): string
    {
        return LegalEntity::ENTITY_CONDITION;
    }

    protected function onSyncStatusChanged(string $entityType, JobStatus $status): void
    {
        $this->syncStatus = $status->value;
    }

    protected function initializeComponent(): void
    {
        $this->getDictionary();

        $this->loadFilterOptions();
    }

    /**
     * Whether the user has an active declaration or approval on the patient, which opens the conditions of every legal entity.
     *
     * @return bool
     */
    #[Computed]
    public function hasPatientAccess(): bool
    {
        return Declaration::accessGrantedTo(Auth::user(), $this->patient(), legalEntity())->exists()
            || Approval::accessGrantedTo(Auth::user(), $this->patient(), legalEntity())->exists();
    }

    #[Computed]
    public function paginatedConditions(): LengthAwarePaginator
    {
        $paginator = $this->isSearching
            ? $this->searchConditionsFromEHealth()
            : $this->paginateLocalConditions();

        $this->populateIcd10Descriptions($paginator->getCollection()->toArray());

        return $paginator;
    }

    public function search(): void
    {
        $this->validate($this->filterValidationRules());

        $this->isSearching = true;
        $this->resetPage();
    }

    public function sync(): void
    {
        if ($this->cannotStartSync('condition')) {
            return;
        }

        if ($this->shouldResumeSync('condition')) {
            $this->handleResumeLogic('condition');

            return;
        }

        try {
            $response = EHealth::condition()->getBySearchParams(
                $this->uuid,
                $this->hasPatientAccess ? [] : ['managing_organization_id' => legalEntity()->uuid]
            );
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while synchronizing condition');

            return;
        }

        try {
            $validatedData = $response->validate();
            Repository::condition()->sync($this->patient(), $validatedData);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while synchronizing condition');

            return;
        }

        if ($response->isNotLast()) {
            $this->dispatchRemainingPages('condition');
        } else {
            legalEntity()->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_CONDITION);
            Session::flash('success', __('conditions.messages.synced_successfully'));
        }

        $this->loadFilterOptions();

        $this->isSearching = false;
        $this->resetPage();
    }

    /**
     * Open the page of a condition from the list or the eHealth search, storing it first when it is not in the database yet.
     *
     * @param  string  $conditionId
     * @return void
     */
    public function view(string $conditionId): void
    {
        // The records of the persons merged into the patient count too, so a stored one is not pulled in again under this patient
        $condition = Condition::readableFor($this->patient(), true)->whereUuid($conditionId)->first()
            ?? $this->storeSearchedCondition($conditionId);

        if ($condition === null) {
            return;
        }

        if (Auth::user()->cannot('view', [$condition, $this->patient()])) {
            Session::flash('error', __('conditions.policy.view'));

            return;
        }

        if ($this->prepersonId !== null) {
            $this->redirectRoute(
                'prepersons.conditions.view',
                [legalEntity(), 'preperson' => $this->prepersonId, 'condition' => $condition->id],
                navigate: true
            );

            return;
        }

        $this->redirectRoute(
            'persons.conditions.view',
            [legalEntity(), 'person' => $this->personId, 'condition' => $condition->id],
            navigate: true
        );
    }

    public function searchICD10(string $value): void
    {
        $this->icd10Results = Icd10::search($value)->limit(50)
            ->get(['code', 'description'])
            ->toArray();
    }

    public function resetFilters(): void
    {
        $this->reset([
            'filterCode',
            'filterEncounterId',
            'filterEpisodeId',
            'filterOnsetDateFrom',
            'filterOnsetDateTo',
            'isSearching'
        ]);

        $this->resetPage();
    }

    private function loadFilterOptions(): void
    {
        $this->episodes = Repository::episode()->getByPersonId($this->patient());
        $this->encounters = Repository::encounter()->getByPersonId($this->patient());
    }

    /**
     * Store a condition found through the eHealth search, so that it has a page to open. It is kept only when the user
     * may read it, so a record the access rules hide is not left behind in the database.
     *
     * @param  string  $conditionId
     * @return Condition|null
     */
    protected function storeSearchedCondition(string $conditionId): ?Condition
    {
        try {
            $response = EHealth::condition()->getById($this->uuid, $conditionId);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while loading the condition');

            return null;
        }

        try {
            return DB::transaction(function () use ($response, $conditionId): Condition {
                Repository::condition()->sync($this->patient(), [$response->validate()]);

                $condition = Condition::forPatient($this->patient())->whereUuid($conditionId)->firstOrFail();

                if (Auth::user()->cannot('view', [$condition, $this->patient()])) {
                    throw new AuthorizationException();
                }

                return $condition;
            });
        } catch (AuthorizationException) {
            Session::flash('error', __('conditions.policy.view'));

            return null;
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while storing the condition');

            return null;
        }
    }

    /**
     * Paginate locally stored (synced) conditions straight from the database.
     *
     * @return LengthAwarePaginator
     */
    protected function paginateLocalConditions(): LengthAwarePaginator
    {
        $paginator = Condition::readableFor($this->patient(), $this->hasPatientAccess)
            ->notInDraftEncounter($this->patient())
            ->withAllRelations()
            ->recentlyUpdatedFirst()
            ->paginate(config('pagination.per_page'));

        $paginator->setCollection(collect(Arr::toCamelCase($paginator->getCollection()->toArray())));

        return $paginator;
    }

    /**
     * Fetch a single page of conditions from the eHealth API for the active search filters.
     *
     * @return LengthAwarePaginator
     */
    protected function searchConditionsFromEHealth(): LengthAwarePaginator
    {
        $perPage = config('pagination.per_page');
        $page = $this->getPage();

        $params = array_filter([
            'code' => $this->filterCode ?: null,
            'encounter_id' => $this->filterEncounterId ?: null,
            'episode_id' => $this->filterEpisodeId ?: null,
            'onset_date_from' => $this->filterOnsetDateFrom ?: null,
            'onset_date_to' => $this->filterOnsetDateTo ?: null,
            'managing_organization_id' => $this->hasPatientAccess ? null : legalEntity()->uuid,
            'page' => $page,
            'page_size' => $perPage
        ]);

        try {
            $response = EHealth::condition()->getBySearchParams($this->uuid, $params);
            $conditions = Arr::toCamelCase($response->validate());
            $total = $response->getPaging()['total_entries'];
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while loading conditions');
            $conditions = [];
            $total = 0;
        }

        return new LengthAwarePaginator(collect($conditions), $total, $perPage, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath()
        ]);
    }

    /**
     * Load ICD-10-AM code descriptions from the database into the dictionaries,
     * as these codes are not part of the eHealth dictionaries.
     *
     * @param  array  $conditions
     * @return void
     */
    private function populateIcd10Descriptions(array $conditions): void
    {
        $icd10Codes = collect($conditions)
            ->filter(static fn (array $condition) => data_get($condition, 'code.coding.0.system') === 'eHealth/ICD10_AM/condition_codes')
            ->map(static fn (array $condition) => data_get($condition, 'code.coding.0.code'))
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        if (empty($icd10Codes)) {
            return;
        }

        $this->dictionaries['eHealth/ICD10_AM/condition_codes'] = Icd10::whereIn('code', $icd10Codes)
            ->pluck('description', 'code')
            ->toArray();
    }

    protected function filterValidationRules(): array
    {
        return [
            'filterCode' => [
                'nullable',
                'string',
                new InDictionary(['eHealth/ICPC2/condition_codes', 'eHealth/ICD10_AM/condition_codes'])
            ],
            'filterEncounterId' => ['nullable', 'string', 'max:255'],
            'filterEpisodeId' => ['nullable', 'string', 'max:255'],
            'filterOnsetDateFrom' => ['nullable', 'date_format:' . config('app.date_format')],
            'filterOnsetDateTo' => ['nullable', 'date_format:' . config('app.date_format')]
        ];
    }

    public function render(): View
    {
        return view('livewire.condition.conditions');
    }
}
