<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Repositories\MedicalEvents\Repository;
use App\Traits\BatchLegalEntityQueries;
use App\Jobs\ConditionSync;
use App\Classes\eHealth\EHealth;
use App\Traits\HandlesSyncBatch;
use App\Models\Icd10;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Condition;
use App\Rules\InDictionary;
use App\Enums\JobStatus;
use App\Core\Arr;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use Throwable;

class PatientConditions extends BasePatientComponent
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
                ['managing_organization_id' => legalEntity()->uuid]
            );
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while synchronizing condition');

            return;
        }

        try {
            $validatedData = $response->validate();
            Repository::condition()->sync($this->personId, $validatedData);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while synchronizing condition');

            return;
        }

        if ($response->isNotLast()) {
            $this->dispatchRemainingPages('condition');
        } else {
            legalEntity()->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_CONDITION);
            Session::flash('success', __('patients.messages.conditions_synced_successfully'));
        }

        $this->loadFilterOptions();

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
        $this->episodes = Repository::episode()->getByPersonId($this->personId);
        $this->encounters = Repository::encounter()->getByPersonId($this->personId);
    }

    /**
     * Paginate locally stored (synced) conditions straight from the database.
     *
     * @return LengthAwarePaginator
     */
    protected function paginateLocalConditions(): LengthAwarePaginator
    {
        $paginator = Condition::forPerson($this->personId)
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
            'managing_organization_id' => legalEntity()->uuid,
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
        return view('livewire.person.records.conditions');
    }
}
