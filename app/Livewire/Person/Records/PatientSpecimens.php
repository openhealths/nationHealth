<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\JobStatus;
use App\Enums\Specimen\Status;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Jobs\SpecimenSync;
use App\Livewire\EncounterPackage\Forms\EncounterCancellationForm;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Specimen;
use App\Repositories\MedicalEvents\Repository;
use App\Rules\InDictionary;
use App\Traits\BatchLegalEntityQueries;
use App\Traits\HandlesEncounterCancellation;
use App\Traits\HandlesSyncBatch;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use Throwable;

class PatientSpecimens extends BasePatientComponent
{
    use BatchLegalEntityQueries;
    use HandlesEncounterCancellation;
    use HandlesSyncBatch;
    use WithPagination;

    public EncounterCancellationForm $form;

    public string $filterStatus = '';

    public string $filterType = '';

    public string $filterCollectedRange = '';

    public string $filterRegisteredBy = '';

    public string $filterContainerIdentifier = '';

    public string $filterContainerType = '';

    public string $filterParent = '';

    public string $filterRequest = '';

    public string $filterEncounter = '';

    public bool $showAdditionalParams = false;

    public string $syncStatus = '';

    /**
     * Employees the specimens can be filtered by.
     *
     * @var array
     */
    public array $employees = [];

    /**
     * Encounters of the patient the specimens can be filtered by.
     *
     * @var array
     */
    public array $encounters = [];

    /**
     * Specimens of the patient that can be picked as a parent one.
     *
     * @var array
     */
    public array $parentSpecimens = [];

    /**
     * Electronic referrals of the patient the specimens can be filtered by.
     *
     * @var array
     */
    public array $referrals = [];

    protected array $dictionaryNames = [
        'specimen_types',
        'specimen_container_types',
        'POSITION',
        'eHealth/cancellation_reasons'
    ];

    protected function getSyncStatus(string $entityType): ?string
    {
        return $this->syncStatus ?: null;
    }

    protected function getBatchName(string $entityType): string
    {
        return SpecimenSync::BATCH_NAME;
    }

    protected function getJobClass(string $entityType): string
    {
        return SpecimenSync::class;
    }

    protected function getEntityConstant(string $entityType): string
    {
        return LegalEntity::ENTITY_SPECIMEN;
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
     * Load the options the specimens can be filtered by.
     *
     * @return void
     */
    protected function loadFilterOptions(): void
    {
        $this->encounters = Repository::encounter()->getByPersonId($this->patient());

        $this->parentSpecimens = Specimen::forPatient($this->patient())
            ->notDraft()
            ->with('type.coding')
            ->get(['id', 'uuid', 'accession_identifier', 'type_id'])
            ->map(fn (Specimen $specimen): array => [
                'uuid' => $specimen->uuid,
                'name' => collect([
                    $specimen->accessionIdentifier,
                    data_get($this->dictionaries, 'specimen_types.' . $specimen->type->coding->first()?->code)
                ])->filter()->implode(' - ') ?: $specimen->uuid
            ])
            ->toArray();

        // Referrals live under the person only, so a preperson has none to pick from
        $this->referrals = $this->personId === null
            ? []
            : collect(Repository::serviceRequest()->getByPersonId($this->personId))
                ->map(static fn (array $referral): array => [
                    'uuid' => $referral['uuid'],
                    'name' => $referral['requestNumber'] ?: $referral['uuid']
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

    #[Computed]
    public function paginatedSpecimens(): LengthAwarePaginator
    {
        return $this->isSearching
            ? $this->searchSpecimensFromEHealth()
            : $this->paginateLocalSpecimens();
    }

    public function search(): void
    {
        $this->validate($this->filterValidationRules());

        $this->isSearching = true;
        $this->resetPage();
    }

    /**
     * Sync the first page of the patient specimens and hand the remaining pages over to the queue.
     *
     * @return void
     */
    public function sync(): void
    {
        if ($this->cannotStartSync('specimen')) {
            return;
        }

        if ($this->shouldResumeSync('specimen')) {
            $this->handleResumeLogic('specimen');

            return;
        }

        try {
            $response = EHealth::specimen()->getBySearchParams($this->uuid);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while synchronizing specimens');

            return;
        }

        try {
            $validatedData = $response->validate();
            Repository::specimen()->sync($this->patient(), $validatedData);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while synchronizing specimens');

            return;
        }

        if ($response->isNotLast()) {
            $this->dispatchRemainingPages('specimen');
        } else {
            legalEntity()->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_SPECIMEN);
            Session::flash('success', __('specimens.messages.synced_successfully'));
        }

        $this->loadFilterOptions();

        $this->isSearching = false;
        $this->resetPage();
    }

    /**
     * Open the page of a specimen found through the eHealth search, storing it first when it is not in the database yet.
     * A specimen that is already stored is opened as it is, without going to eHealth again.
     *
     * @param  string  $specimenId
     * @return void
     */
    public function view(string $specimenId): void
    {
        $specimen = Specimen::forPatient($this->patient())->whereUuid($specimenId)->first()
            ?? $this->storeSearchedSpecimen($specimenId);

        if ($specimen === null) {
            return;
        }

        if ($this->prepersonId !== null) {
            $this->redirectRoute(
                'prepersons.specimens.view',
                [legalEntity(), 'preperson' => $this->prepersonId, 'specimen' => $specimen->id],
                navigate: true
            );

            return;
        }

        $this->redirectRoute(
            'persons.specimens.view',
            [legalEntity(), 'person' => $this->personId, 'specimen' => $specimen->id],
            navigate: true
        );
    }

    /**
     * Store a specimen found through the eHealth search, so that it has a page to open.
     *
     * @param  string  $specimenId
     * @return Specimen|null
     */
    protected function storeSearchedSpecimen(string $specimenId): ?Specimen
    {
        try {
            $response = EHealth::specimen()->getDetails($this->uuid, $specimenId);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while loading the specimen');

            return null;
        }

        try {
            Repository::specimen()->sync($this->patient(), [$response->validate()]);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while storing the specimen');

            return null;
        }

        return Specimen::forPatient($this->patient())->whereUuid($specimenId)->first();
    }

    public function resetFilters(): void
    {
        $this->reset([
            'filterStatus',
            'filterType',
            'filterCollectedRange',
            'filterRegisteredBy',
            'filterContainerIdentifier',
            'filterContainerType',
            'filterParent',
            'filterRequest',
            'filterEncounter',
            'isSearching'
        ]);

        $this->resetPage();
    }

    /**
     * Paginate locally stored (synced) specimens straight from the database.
     *
     * @return LengthAwarePaginator
     */
    protected function paginateLocalSpecimens(): LengthAwarePaginator
    {
        $paginator = Specimen::forPatient($this->patient())
            ->notInDraftEncounter($this->patient())
            ->withAllRelations()
            ->latest('ehealth_inserted_at')
            ->paginate(config('pagination.per_page'));

        // The id is hidden on the model but the list links to the specimen page by it
        $paginator->setCollection(
            collect(Arr::toCamelCase($paginator->getCollection()->makeVisible('id')->toArray()))
        );

        return $paginator;
    }

    /**
     * Fetch a single page of specimens from the eHealth API for the active search filters.
     *
     * @return LengthAwarePaginator
     */
    protected function searchSpecimensFromEHealth(): LengthAwarePaginator
    {
        $perPage = config('pagination.per_page');
        $page = $this->getPage();
        // The range picker keeps both bounds in one field
        $collectedBounds = array_map('trim', explode('—', $this->filterCollectedRange));

        $params = array_filter([
            'status' => $this->filterStatus ?: null,
            'type' => $this->filterType ?: null,
            'registered_by' => $this->filterRegisteredBy ?: null,
            'collected_from' => $collectedBounds[0] ?: null,
            'collected_to' => $collectedBounds[1] ?? null,
            'container_identifier' => $this->filterContainerIdentifier ?: null,
            'container_type' => $this->filterContainerType ?: null,
            'parent' => $this->filterParent ?: null,
            'request' => $this->filterRequest ?: null,
            'encounter' => $this->filterEncounter ?: null,
            'page' => $page,
            'page_size' => $perPage
        ]);

        try {
            $response = EHealth::specimen()->getBySearchParams($this->uuid, $params);
            $specimens = Arr::toCamelCase($this->formatDatesForDisplay($response->validate(), 'd.m.Y H:i'));
            $total = $response->getPaging()['total_entries'];
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while loading specimens');
            $specimens = [];
            $total = 0;
        }

        return new LengthAwarePaginator(collect($specimens), $total, $perPage, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath()
        ]);
    }

    protected function filterValidationRules(): array
    {
        return [
            'filterStatus' => ['nullable', Rule::enum(Status::class)->except(Status::DRAFT)],
            'filterType' => ['nullable', 'string', new InDictionary('specimen_types')],
            'filterCollectedRange' => ['nullable', 'string'],
            'filterRegisteredBy' => ['nullable', 'uuid'],
            'filterContainerIdentifier' => ['nullable', 'string'],
            'filterContainerType' => ['nullable', 'string', new InDictionary('specimen_container_types')],
            'filterParent' => ['nullable', 'uuid'],
            'filterRequest' => ['nullable', 'uuid'],
            'filterEncounter' => ['nullable', 'uuid']
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
        return view('livewire.person.records.patient-specimens');
    }
}
