<?php

declare(strict_types=1);

namespace App\Livewire\DeviceAssociation;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\JobStatus;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Jobs\DeviceAssociationSync;
use App\Livewire\EncounterPackage\Forms\EncounterCancellationForm;
use App\Livewire\Person\Records\BasePatientComponent;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Device;
use App\Models\MedicalEvents\Sql\DeviceAssociation;
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

class DeviceAssociationIndex extends BasePatientComponent
{
    use BatchLegalEntityQueries;
    use HandlesEncounterCancellation;
    use HandlesSyncBatch;
    use WithPagination;

    public EncounterCancellationForm $form;

    /**
     * Filter dropdown options the user can pick from to narrow the device associations search.
     *
     * @var array
     */
    public array $encounters = [];

    public array $episodes = [];

    public array $employees = [];

    public array $devices = [];

    /**
     * Bound search filter values applied when querying device associations.
     *
     * @var string
     */
    public string $filterDeviceId = '';

    public string $filterEncounterId = '';

    public string $filterStatus = '';

    public string $filterEpisodeId = '';

    public string $filterRecorder = '';

    public string $filterBodySite = '';

    public string $filterAssociationDateFrom = '';

    public string $filterAssociationDateTo = '';

    public string $filterRecordedFrom = '';

    public string $filterRecordedTo = '';

    public bool $showAdditionalParams = false;

    public string $syncStatus = '';

    protected array $dictionaryNames = [
        'POSITION',
        'eHealth/cancellation_reasons',
        'device_association_statuses',
        'eHealth/body_structures'
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
        return DeviceAssociationSync::BATCH_NAME;
    }

    /**
     * {@inheritDoc}
     */
    protected function getJobClass(string $entityType): string
    {
        return DeviceAssociationSync::class;
    }

    /**
     * {@inheritDoc}
     */
    protected function getEntityConstant(string $entityType): string
    {
        return LegalEntity::ENTITY_DEVICE_ASSOCIATION;
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

        $this->syncStatus = legalEntity()->getEntityStatus(LegalEntity::ENTITY_DEVICE_ASSOCIATION) ?? '';

        $this->loadFilterOptions();
    }

    /**
     * Device associations of the current page: from eHealth while searching, otherwise the locally stored ones.
     *
     * @return LengthAwarePaginator
     */
    #[Computed]
    public function paginatedAssociations(): LengthAwarePaginator
    {
        return $this->isSearching
            ? $this->searchAssociationsFromEHealth()
            : $this->paginateLocalAssociations();
    }

    /**
     * Validate the filters and switch the list to the eHealth search.
     *
     * @return void
     */
    public function search(): void
    {
        if (Auth::user()->cannot('viewAny', DeviceAssociation::class)) {
            Session::flash('error', __('device-associations.policy.view_any'));

            return;
        }

        $this->validate($this->filterValidationRules());

        $this->isSearching = true;
        $this->resetPage();
    }

    /**
     * Store the first page of the patient's device associations from eHealth and hand the remaining pages to the queue.
     *
     * @return void
     */
    public function sync(): void
    {
        if (Auth::user()->cannot('viewAny', DeviceAssociation::class)) {
            Session::flash('error', __('device-associations.policy.sync'));

            return;
        }

        if ($this->cannotStartSync('device_association')) {
            return;
        }

        if ($this->shouldResumeSync('device_association')) {
            $this->handleResumeLogic('device_association');

            return;
        }

        try {
            $response = EHealth::deviceAssociation()->getBySearchParams(
                $this->uuid,
                ['recorder_legal_entity_id' => legalEntity()->uuid]
            );
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while synchronizing device associations');

            return;
        }

        try {
            Repository::deviceAssociation()->sync($this->patient(), $response->validate());
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while synchronizing device associations');

            return;
        }

        if ($response->isNotLast()) {
            $this->dispatchRemainingPages('device_association');
        } else {
            legalEntity()->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_DEVICE_ASSOCIATION);
            Session::flash('success', __('device-associations.messages.synced_successfully'));
        }

        $this->loadFilterOptions();

        $this->isSearching = false;
        $this->resetPage();
    }

    /**
     * Open the page of a device association found through the eHealth search, storing it first when it is not in the database yet.
     *
     * @param  string  $deviceAssociationId
     * @return void
     */
    public function view(string $deviceAssociationId): void
    {
        if (Auth::user()->cannot('view', DeviceAssociation::class)) {
            Session::flash('error', __('device-associations.policy.view'));

            return;
        }

        $deviceAssociation = DeviceAssociation::forPatient($this->patient())->whereUuid($deviceAssociationId)->first()
            ?? $this->storeSearchedAssociation($deviceAssociationId);

        if ($deviceAssociation === null) {
            return;
        }

        if ($this->prepersonId !== null) {
            $this->redirectRoute(
                'prepersons.device-associations.view',
                [legalEntity(), 'preperson' => $this->prepersonId, 'deviceAssociation' => $deviceAssociation->id],
                navigate: true
            );

            return;
        }

        $this->redirectRoute(
            'persons.device-associations.view',
            [legalEntity(), 'person' => $this->personId, 'deviceAssociation' => $deviceAssociation->id],
            navigate: true
        );
    }

    /**
     * Clear the filters and go back to the locally stored device associations.
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
            'filterBodySite',
            'filterAssociationDateFrom',
            'filterAssociationDateTo',
            'filterRecordedFrom',
            'filterRecordedTo',
            'isSearching'
        ]);

        $this->resetPage();
    }

    /**
     * Store a device association found through the eHealth search, so that it has a page to open.
     *
     * @param  string  $deviceAssociationId
     * @return DeviceAssociation|null
     */
    protected function storeSearchedAssociation(string $deviceAssociationId): ?DeviceAssociation
    {
        try {
            $response = EHealth::deviceAssociation()->getById($this->uuid, $deviceAssociationId);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while loading the device association');

            return null;
        }

        try {
            Repository::deviceAssociation()->sync($this->patient(), [$response->validate()]);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while storing the device association');

            return null;
        }

        return DeviceAssociation::forPatient($this->patient())->whereUuid($deviceAssociationId)->first();
    }

    /**
     * Paginate locally stored (synced) device associations straight from the database.
     *
     * @return LengthAwarePaginator
     */
    protected function paginateLocalAssociations(): LengthAwarePaginator
    {
        $paginator = DeviceAssociation::forPatient($this->patient())
            ->notInDraftEncounter($this->patient())
            ->withAllRelations()
            ->recentlyUpdatedFirst()
            ->paginate(config('pagination.per_page'));

        // The id is hidden on the model but the list links to the device association page by it
        $paginator->setCollection(
            collect(Arr::toCamelCase($paginator->getCollection()->makeVisible('id')->toArray()))
        );

        return $paginator;
    }

    /**
     * Fetch a single page of device associations from the eHealth API for the active search filters.
     *
     * @return LengthAwarePaginator
     */
    protected function searchAssociationsFromEHealth(): LengthAwarePaginator
    {
        $perPage = config('pagination.per_page');
        $page = $this->getPage();

        // Device associations are only readable within the legal entity that recorded them, so the search is scoped to it
        $params = array_filter([
            'device_id' => $this->filterDeviceId ?: null,
            'encounter_id' => $this->filterEncounterId ?: null,
            'episode_id' => $this->filterEpisodeId ?: null,
            'status' => $this->filterStatus ?: null,
            'recorder' => $this->filterRecorder ?: null,
            'recorder_legal_entity_id' => legalEntity()->uuid,
            'body_site' => $this->filterBodySite ?: null,
            'association_date_from' => $this->filterAssociationDateFrom ?: null,
            'association_date_to' => $this->filterAssociationDateTo ?: null,
            'recorded_from' => $this->filterRecordedFrom ?: null,
            'recorded_to' => $this->filterRecordedTo ?: null,
            'page' => $page,
            'page_size' => $perPage
        ]);

        try {
            $response = EHealth::deviceAssociation()->getBySearchParams($this->uuid, $params);
            $associations = array_map(
                static fn (array $association): array => array_merge($association, [
                    'associationDate' => isset($association['associationDate'])
                        ? CarbonImmutable::parse($association['associationDate'])->format(config('app.date_format'))
                        : null
                ]),
                Arr::toCamelCase($this->formatDatesForDisplay($response->validate(), 'd.m.Y H:i'))
            );
            $total = $response->getPaging()['total_entries'];
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while loading device associations');
            $associations = [];
            $total = 0;
        }

        return new LengthAwarePaginator(collect($associations), $total, $perPage, $page, [
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
            'filterStatus' => ['nullable', 'string', new InDictionary('device_association_statuses')],
            'filterRecorder' => ['nullable', 'uuid'],
            'filterBodySite' => ['nullable', 'string', new InDictionary('eHealth/body_structures')],
            'filterAssociationDateFrom' => ['nullable', 'date_format:' . config('app.date_format')],
            'filterAssociationDateTo' => ['nullable', 'date_format:' . config('app.date_format')],
            'filterRecordedFrom' => ['nullable', 'date_format:' . config('app.date_format')],
            'filterRecordedTo' => ['nullable', 'date_format:' . config('app.date_format')]
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
        return view('livewire.device-association.device-associations');
    }
}
