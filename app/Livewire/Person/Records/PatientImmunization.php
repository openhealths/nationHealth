<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\JobStatus;
use App\Jobs\ImmunizationSync;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\MedicalEvents\Sql\Immunization;
use App\Repositories\MedicalEvents\Repository;
use App\Rules\InDictionary;
use App\Traits\BatchLegalEntityQueries;
use App\Traits\HandlesSyncBatch;
use Illuminate\View\View;
use Illuminate\Support\Facades\Session;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use Throwable;

class PatientImmunization extends BasePatientComponent
{
    use BatchLegalEntityQueries;
    use HandlesSyncBatch;
    use WithPagination;

    /**
     * Filter dropdown options the user can pick from to narrow the immunizations search.
     *
     * @var array
     */
    public array $vaccines = [];

    public array $encounters = [];

    public array $episodes = [];

    /**
     * Bound search filter values applied when querying immunizations.
     *
     * @var string
     */
    public string $filterCode = '';

    public string $filterEpisodeId = '';

    public string $filterEncounterId = '';

    public string $filterDateFrom = '';

    public string $filterDateTo = '';

    public bool $showAdditionalParams = false;

    public string $syncStatus = '';

    protected array $dictionaryNames = [
        'eHealth/vaccine_codes',
        'eHealth/vaccination_routes',
        'eHealth/immunization_body_sites',
        'eHealth/immunization_report_origins',
        'eHealth/reason_explanations',
        'eHealth/reason_not_given_explanations',
        'eHealth/immunization_dosage_units',
        'eHealth/vaccination_authorities',
        'eHealth/vaccination_target_diseases',
        'eHealth/encounter_classes',
    ];

    protected function getSyncStatus(string $entityType): ?string
    {
        return $this->syncStatus ?: null;
    }

    protected function getBatchName(string $entityType): string
    {
        return ImmunizationSync::BATCH_NAME;
    }

    protected function getJobClass(string $entityType): string
    {
        return ImmunizationSync::class;
    }

    protected function getEntityConstant(string $entityType): string
    {
        return LegalEntity::ENTITY_IMMUNIZATION;
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
    public function paginatedImmunizations(): LengthAwarePaginator
    {
        return $this->isSearching
            ? $this->searchImmunizationsFromEHealth()
            : $this->paginateLocalImmunizations();
    }

    public function search(): void
    {
        $this->validate($this->filterValidationRules());

        $this->isSearching = true;
        $this->resetPage();
    }

    public function sync(): void
    {
        if ($this->cannotStartSync('immunization')) {
            return;
        }

        if ($this->shouldResumeSync('immunization')) {
            $this->handleResumeLogic('immunization');

            return;
        }

        try {
            $response = EHealth::immunization()->getBySearchParams(
                $this->uuid,
                ['managing_organization_id' => legalEntity()->uuid]
            );
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while synchronizing immunizations');

            return;
        }

        try {
            $validatedData = $response->validate();
            Repository::immunization()->sync($this->personId, $validatedData);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while synchronizing immunizations');

            return;
        }

        if ($response->isNotLast()) {
            $this->dispatchRemainingPages('immunization');
        } else {
            legalEntity()->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_IMMUNIZATION);
            Session::flash('success', __('patients.messages.immunizations_synced_successfully'));
        }

        $this->isSearching = false;
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset([
            'filterCode',
            'filterEpisodeId',
            'filterEncounterId',
            'filterDateFrom',
            'filterDateTo',
            'isSearching'
        ]);

        $this->resetPage();
    }

    /**
     * Paginate locally stored (synced) immunizations straight from the database.
     *
     * @return LengthAwarePaginator
     */
    protected function paginateLocalImmunizations(): LengthAwarePaginator
    {
        $paginator = Immunization::forPerson($this->personId)
            ->withAllRelations()
            ->recentlyUpdatedFirst()
            ->paginate(config('pagination.per_page'));

        $paginator->setCollection(collect(Arr::toCamelCase($paginator->getCollection()->toArray())));

        return $paginator;
    }

    /**
     * Fetch a single page of immunizations from the eHealth API for the active search filters.
     *
     * @return LengthAwarePaginator
     */
    protected function searchImmunizationsFromEHealth(): LengthAwarePaginator
    {
        $perPage = config('pagination.per_page');
        $page = $this->getPage();

        $params = array_filter([
            'vaccine_code' => $this->filterCode ?: null,
            'encounter_id' => $this->filterEncounterId ?: null,
            'episode_id' => $this->filterEpisodeId ?: null,
            'date_from' => $this->filterDateFrom ?: null,
            'date_to' => $this->filterDateTo ?: null,
            'page' => $page,
            'page_size' => $perPage
        ]);

        try {
            $response = EHealth::immunization()->getBySearchParams($this->uuid, $params);
            $immunizations = Arr::toCamelCase($this->formatDatesForDisplay($response->validate()));
            $total = $response->getPaging()['total_entries'];
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while loading immunizations');
            $immunizations = [];
            $total = 0;
        }

        return new LengthAwarePaginator(collect($immunizations), $total, $perPage, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath()
        ]);
    }

    protected function loadFilterOptions(): void
    {
        $this->episodes = Episode::forPerson($this->personId)->recentlyUpdatedFirst()->get()->toArray();
        $this->encounters = Encounter::forPerson($this->personId)->recentlyUpdatedFirst()->get()->toArray();
    }

    protected function filterValidationRules(): array
    {
        return [
            'filterCode' => ['nullable', 'string', new InDictionary('eHealth/vaccine_codes')],
            'filterEncounterId' => ['nullable', 'uuid'],
            'filterEpisodeId' => ['nullable', 'uuid'],
            'filterDateFrom' => ['nullable', 'date_format:' . config('app.date_format')],
            'filterDateTo' => ['nullable', 'date_format:' . config('app.date_format')]
        ];
    }

    public function render(): View
    {
        return view('livewire.person.records.immunization');
    }
}
