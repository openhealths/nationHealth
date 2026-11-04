<?php

declare(strict_types=1);

namespace App\Livewire\ClinicalImpression;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\ClinicalImpression\Status;
use App\Enums\JobStatus;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Jobs\ClinicalImpressionFullSync;
use App\Livewire\EncounterPackage\Forms\EncounterCancellationForm;
use App\Livewire\Person\Records\BasePatientComponent;
use App\Models\Declaration;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Approval;
use App\Models\MedicalEvents\Sql\ClinicalImpression;
use App\Repositories\MedicalEvents\Repository;
use App\Rules\InDictionary;
use App\Traits\BatchLegalEntityQueries;
use App\Traits\HandlesEncounterCancellation;
use App\Traits\HandlesSyncBatch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use Throwable;

class ClinicalImpressionIndex extends BasePatientComponent
{
    use BatchLegalEntityQueries;
    use HandlesEncounterCancellation;
    use HandlesSyncBatch;
    use WithPagination;

    public EncounterCancellationForm $form;

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
        'eHealth/cancellation_reasons',
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
        return ClinicalImpressionFullSync::BATCH_NAME;
    }

    protected function getJobClass(string $entityType): string
    {
        return ClinicalImpressionFullSync::class;
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
     * Whether the user has access to all the patient's clinical impressions: an active declaration (ABAC rule 1) or an
     * active approval on the patient's data (ABAC rule 4).
     *
     * @return bool
     */
    #[Computed]
    public function hasPatientAccess(): bool
    {
        return Declaration::accessGrantedTo(Auth::user(), $this->patient(), legalEntity())->exists()
            || Approval::accessGrantedTo(Auth::user(), $this->patient(), legalEntity())->exists();
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
        // eHealth searches the clinical impressions only with access to all the patient's data
        if (!$this->hasPatientAccess) {
            Session::flash('error', __('clinical-impressions.policy.search'));

            return;
        }

        $this->validate($this->filterValidationRules());

        $this->isSearching = true;
        $this->resetPage();
    }

    public function sync(): void
    {
        // eHealth returns the clinical impressions only with access to all the patient's data
        if (!$this->hasPatientAccess) {
            Session::flash('error', __('clinical-impressions.policy.sync'));

            return;
        }

        if ($this->cannotStartSync('clinical_impression')) {
            return;
        }

        if ($this->shouldResumeSync('clinical_impression') && $this->handleResumeLogic('clinical_impression')) {
            return;
        }

        try {
            $response = EHealth::clinicalImpression()->getBySearchParams($this->uuid);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while synchronizing clinical impressions');

            return;
        }

        try {
            $validatedData = $response->validate();
            Repository::clinicalImpression()->sync($this->patient(), $validatedData);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while synchronizing clinical impressions');

            return;
        }

        if ($response->isNotLast()) {
            $this->dispatchRemainingPages('clinical_impression');
        } else {
            legalEntity()->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_CLINICAL_IMPRESSION);
            Session::flash('success', __('clinical-impressions.messages.synced_successfully'));
        }

        $this->loadFilterOptions();

        $this->isSearching = false;
        $this->resetPage();
    }

    /**
     * Open the page of a clinical impression from the list or the eHealth search, storing it first when it is not in
     * the database yet.
     *
     * @param  string  $clinicalImpressionId
     * @return void
     */
    public function view(string $clinicalImpressionId): void
    {
        // The records of the persons merged into the patient count too, so a stored one is not pulled in again under this patient
        $clinicalImpression = ClinicalImpression::readableFor($this->patient(), true)->whereUuid($clinicalImpressionId)->first()
            ?? $this->storeSearchedClinicalImpression($clinicalImpressionId);

        if ($clinicalImpression === null) {
            return;
        }

        if (Auth::user()->cannot('view', [$clinicalImpression, $this->patient()])) {
            Session::flash('error', __('clinical-impressions.policy.view'));

            return;
        }

        if ($this->prepersonId !== null) {
            $this->redirectRoute(
                'prepersons.clinical-impressions.view',
                [legalEntity(), 'preperson' => $this->prepersonId, 'clinicalImpression' => $clinicalImpression->id],
                navigate: true
            );

            return;
        }

        $this->redirectRoute(
            'persons.clinical-impressions.view',
            [legalEntity(), 'person' => $this->personId, 'clinicalImpression' => $clinicalImpression->id],
            navigate: true
        );
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
     * Store a clinical impression found through the eHealth search, so that it has a page to open. It is kept only when
     * the user may read it, so a record the access rules hide is not left behind in the database.
     *
     * @param  string  $clinicalImpressionId
     * @return ClinicalImpression|null
     */
    protected function storeSearchedClinicalImpression(string $clinicalImpressionId): ?ClinicalImpression
    {
        try {
            $response = EHealth::clinicalImpression()->getById($this->uuid, $clinicalImpressionId);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while loading the clinical impression');

            return null;
        }

        try {
            return DB::transaction(function () use ($response, $clinicalImpressionId): ClinicalImpression {
                Repository::clinicalImpression()->sync($this->patient(), [$response->validate()]);

                $clinicalImpression = ClinicalImpression::forPatient($this->patient())
                    ->whereUuid($clinicalImpressionId)
                    ->firstOrFail();

                if (Auth::user()->cannot('view', [$clinicalImpression, $this->patient()])) {
                    throw new AuthorizationException();
                }

                return $clinicalImpression;
            });
        } catch (AuthorizationException) {
            Session::flash('error', __('clinical-impressions.policy.view'));

            return null;
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while storing the clinical impression');

            return null;
        }
    }

    /**
     * Paginate locally stored (synced) clinical impressions straight from the database.
     *
     * @return LengthAwarePaginator
     */
    protected function paginateLocalClinicalImpressions(): LengthAwarePaginator
    {
        $paginator = ClinicalImpression::readableFor($this->patient(), $this->hasPatientAccess)
            ->notInDraftEncounter($this->patient())
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
            'filterStatus' => ['nullable', Rule::in(Status::values())],
            'filterEffectiveDateFrom' => ['nullable', 'date_format:' . config('app.date_format')],
            'filterEffectiveDateTo' => ['nullable', 'date_format:' . config('app.date_format')],
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
        return view('livewire.clinical-impression.clinical-impressions');
    }
}
