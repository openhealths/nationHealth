<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Exceptions\EHealth\EHealthConnectionException;
use App\Repositories\MedicalEvents\Repository;
use App\Enums\Person\ClinicalImpressionStatus;
use App\Exceptions\EHealth\EHealthException;
use App\Rules\InDictionary;
use App\Traits\BatchLegalEntityQueries;
use App\Jobs\ClinicalImpressionSync;
use App\Classes\eHealth\EHealth;
use App\Traits\HandlesSyncBatch;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\ClinicalImpression;
use App\Enums\JobStatus;
use App\Core\Arr;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use Throwable;

class PatientClinicalImpressions extends BasePatientComponent
{
    use BatchLegalEntityQueries;
    use HandlesSyncBatch;
    use WithPagination;

    public array $filterCodeOptions = [];

    public array $encounters = [];

    public array $episodes = [];

    public string $filterCode = '';

    public string $filterEncounterId = '';

    public string $filterEpisodeId = '';

    public string $filterStatus = '';

    public string $filterEffectiveDateFrom = '';

    public string $filterEffectiveDateTo = '';

    public string $syncStatus = '';

    public bool $showAdditionalParams = false;

    protected array $dictionaryNames = [
        'eHealth/clinical_impression_patient_categories',
        'eHealth/clinical_impression_statuses',
        'eHealth/encounter_classes',
        'eHealth/resources',
    ];

    protected function getSyncStatus(string $entityType): ?string
    {
        return $this->syncStatus ?: null;
    }

    protected function getBatchName(string $entityType): string
    {
        return ClinicalImpressionSync::BATCH_NAME;
    }

    protected function getJobClass(string $entityType): string
    {
        return ClinicalImpressionSync::class;
    }

    protected function getEntityConstant(string $entityType): string
    {
        return LegalEntity::ENTITY_CLINICAL_IMPRESSION;
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
     * Paginator switching between local (synced) and eHealth search results.
     *
     * @return LengthAwarePaginator
     */
    #[Computed]
    public function paginatedClinicalImpressions(): LengthAwarePaginator
    {
        return $this->isSearching
            ? $this->searchClinicalImpressionsFromEHealth()
            : $this->paginateLocalClinicalImpressions();
    }

    public function search(): void
    {
        $this->validate($this->filterValidationRules());

        $this->isSearching = true;
        $this->resetPage();
    }

    public function sync(): void
    {
        if ($this->cannotStartSync('clinicalImpression')) {
            return;
        }

        if ($this->shouldResumeSync('clinicalImpression')) {
            $this->handleResumeLogic('clinicalImpression');

            return;
        }

        try {
            $response = EHealth::clinicalImpression()->getBySearchParams(
                $this->uuid,
                ['managing_organization_id' => legalEntity()->uuid]
            );
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while synchronizing clinical impressions');

            return;
        }

        try {
            $validatedData = $response->validate();
            Repository::clinicalImpression()->sync($this->patient(), $validatedData);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while synchronizing clinical impressions');
            Session::flash('error', __('patients.messages.clinical_impression_sync_database_error'));

            return;
        }

        if ($response->isNotLast()) {
            $this->dispatchRemainingPages('clinicalImpression');
        } else {
            legalEntity()->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_CLINICAL_IMPRESSION);
            Session::flash('success', __('patients.messages.clinical_impressions_synced_successfully'));
        }

        $this->loadFilterOptions();

        $this->isSearching = false;
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset([
            'filterCode',
            'filterEncounterId',
            'filterEpisodeId',
            'filterStatus',
            'filterEffectiveDateFrom',
            'filterEffectiveDateTo',
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
     * Paginate locally stored (synced) clinical impressions straight from the database.
     *
     * @return LengthAwarePaginator
     */
    protected function paginateLocalClinicalImpressions(): LengthAwarePaginator
    {
        $paginator = ClinicalImpression::forPatient($this->patient())
            ->withAllRelations()
            ->recentlyUpdatedFirst()
            ->paginate(config('pagination.per_page'));

        $paginator->setCollection(collect(Arr::toCamelCase($paginator->getCollection()->toArray())));

        return $paginator;
    }

    /**
     * Fetch a single page of clinical impressions from the eHealth API for the active search filters.
     *
     * @return LengthAwarePaginator
     */
    protected function searchClinicalImpressionsFromEHealth(): LengthAwarePaginator
    {
        $perPage = config('pagination.per_page');
        $page = $this->getPage();

        $params = array_filter([
            'encounter_id' => $this->filterEncounterId ?: null,
            'episode_id' => $this->filterEpisodeId ?: null,
            'code' => $this->filterCode ?: null,
            'status' => $this->filterStatus ?: null,
            'effective_date_from' => $this->filterEffectiveDateFrom ?: null,
            'effective_date_to' => $this->filterEffectiveDateTo ?: null,
            'page' => $page,
            'page_size' => $perPage
        ]);

        try {
            $response = EHealth::clinicalImpression()->getBySearchParams($this->uuid, $params);
            $clinicalImpressions = Arr::toCamelCase($response->validate());
            $total = $response->getPaging()['total_entries'];
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while loading clinical impressions');
            $clinicalImpressions = [];
            $total = 0;
        }

        return new LengthAwarePaginator(collect($clinicalImpressions), $total, $perPage, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath()
        ]);
    }

    protected function filterValidationRules(): array
    {
        return [
            'filterCode' => ['nullable', 'string', new InDictionary('eHealth/clinical_impression_patient_categories')],
            'filterEncounterId' => ['nullable', 'string', 'max:255'],
            'filterEpisodeId' => ['nullable', 'string', 'max:255'],
            'filterStatus' => ['nullable', Rule::in(ClinicalImpressionStatus::values())],
            'filterEffectiveDateFrom' => ['nullable', 'date_format:' . config('app.date_format')],
            'filterEffectiveDateTo' => ['nullable', 'date_format:' . config('app.date_format')],
        ];
    }

    public function render(): View
    {
        return view('livewire.person.records.clinical-impressions');
    }
}
