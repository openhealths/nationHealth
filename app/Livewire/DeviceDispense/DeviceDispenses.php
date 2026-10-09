<?php

declare(strict_types=1);

namespace App\Livewire\DeviceDispense;

use App\Core\Arr;
use App\Enums\JobStatus;
use App\Enums\DeviceDispense\Status;
use App\Models\CarePlanActivity;
use App\Models\LegalEntity;
use App\Models\CarePlan;
use App\Models\MedicalEvents\Sql\Procedure;
use App\Models\Employee\Employee;
use App\Models\MedicalEvents\Sql\DeviceDispense;
use App\Models\MedicalEvents\Sql\DeviceRequestRequest;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\MedicalEvents\Sql\Device;
use App\Classes\eHealth\EHealth;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Jobs\DeviceDispenseSync;
use App\Repositories\MedicalEvents\Repository;
use App\Livewire\Person\Records\BasePatientComponent;
use App\Traits\BatchLegalEntityQueries;
use App\Traits\HandlesSyncBatch;
use App\Rules\InDictionary;
use Illuminate\Support\Facades\Session;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use Throwable;

class DeviceDispenses extends BasePatientComponent
{
    use BatchLegalEntityQueries;
    use HandlesSyncBatch;
    use WithPagination;

    public string $filterDeviceId = '';
    public string $filterDeviceCode = '';
    public string $filterEncounterId = '';
    public string $filterStatus = '';
    public string $filterEpisodeId = '';
    public string $filterOrganization = '';
    public string $filterPractitioner = '';
    public string $filterProcedureId = '';
    public string $filterCarePlanId = '';
    public string $filterRelatedEpisodeId = '';
    public string $filterDispenseDateFrom = '';
    public string $filterDispenseDateTo = '';
    public string $filterCreatedAtFrom = '';
    public string $filterCreatedAtTo = '';
    public string $syncStatus = '';

    public array $practitioners = [];
    public array $devices = [];
    public array $deviceTypes = [];
    public array $encounters = [];
    public array $episodes = [];
    public array $organizations = [];
    public array $procedures = [];
    public array $carePlans = [];
    public array $relatedEpisodes = [];

    public bool $showAdditionalParams = false;

    protected array $dictionaryNames = ['device_definition_classification_type'];

    protected function getSyncStatus(string $entityType): ?string
    {
        return $this->syncStatus ?: null;
    }

    protected function getBatchName(string $entityType): string
    {
        return DeviceDispenseSync::BATCH_NAME;
    }

    protected function getJobClass(string $entityType): string
    {
        return DeviceDispenseSync::class;
    }

    protected function getEntityConstant(string $entityType): string
    {
        return LegalEntity::ENTITY_DEVICE_DISPENSE;
    }

    protected function onSyncStatusChanged(string $entityType, JobStatus $status): void
    {
        $this->syncStatus = $status->value;
    }

    protected function initializeComponent(): void
    {
        $this->getDictionary();

        $this->syncStatus = legalEntity()->getEntityStatus(LegalEntity::ENTITY_DEVICE_DISPENSE) ?? '';

        $this->loadFilterOptions();
    }

    /**
     * Device dispenses for the current page, either from eHealth search or from the local database.
     *
     * @return LengthAwarePaginator
     */
    #[Computed]
    public function paginatedDeviceDispenses(): LengthAwarePaginator
    {
        return $this->isSearching ? $this->searchDeviceDispensesFromEHealth() : $this->paginateLocalDeviceDispenses();
    }

    public function search(): void
    {
        $this->validate($this->filterValidationRules());

        $this->isSearching = true;
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset([
            'filterDeviceId',
            'filterDeviceCode',
            'filterEncounterId',
            'filterStatus',
            'filterEpisodeId',
            'filterOrganization',
            'filterPractitioner',
            'filterProcedureId',
            'filterCarePlanId',
            'filterRelatedEpisodeId',
            'filterDispenseDateFrom',
            'filterDispenseDateTo',
            'filterCreatedAtFrom',
            'filterCreatedAtTo',
            'isSearching'
        ]);

        $this->resetPage();
    }

    public function openDeviceDispenseView(string $deviceDispenseUuid): void
    {
        $deviceDispense = DeviceDispense::forPatient($this->patient())
            ->whereUuid($deviceDispenseUuid)
            ->first();

        if ($deviceDispense === null) {
            Session::flash(
                'error',
                __('device-dispenses.messages.not_found_in_db')
            );

            return;
        }

        if ($this->prepersonId !== null) {
            $this->redirectRoute(
                'prepersons.device-dispenses.view',
                [
                    legalEntity(),
                    'preperson' => $this->prepersonId,
                    'deviceDispense' => $deviceDispense->id
                ],
                navigate: true
            );

            return;
        }

        $this->redirectRoute(
            'persons.device-dispenses.view',
            [
                legalEntity(),
                'person' => $this->personId,
                'deviceDispense' => $deviceDispense->id
            ],
            navigate: true
        );
    }

    public function sync(): void
    {
        if ($this->cannotStartSync('device_dispense')) {
            return;
        }

        if ($this->shouldResumeSync('device_dispense')) {
            $this->handleResumeLogic('device_dispense');

            return;
        }

        try {
            $response = EHealth::deviceDispense()->getBySearchParams(
                $this->uuid,
                ['performer_legal_entity' => legalEntity()->uuid]
            );
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while synchronizing device dispense');

            return;
        }

        try {
            $validatedData = $response->validate();
            Repository::deviceDispense()->sync($this->patient(), $validatedData);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while synchronizing device dispense');

            return;
        }

        if ($response->isNotLast()) {
            $this->dispatchRemainingPages('device_dispense');
        } else {
            legalEntity()->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_DEVICE_DISPENSE);
            Session::flash('success', __('device-dispenses.messages.synced_successfully'));
        }

        $this->loadFilterOptions();

        $this->isSearching = false;
        $this->resetPage();
    }

    protected function paginateLocalDeviceDispenses(): LengthAwarePaginator
    {
        $paginator = DeviceDispense::forPatient($this->patient())
            ->withAllRelations()
            ->latest()
            ->paginate(config('pagination.per_page'));

        $performerUuids = $paginator->getCollection()
            ->map(static fn (DeviceDispense $dispense): ?string => $dispense->performer?->value)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $employeeNames = $this->getEmployeeNames($performerUuids);

        $legalEntityUuids = $paginator->getCollection()
            ->map(static fn (DeviceDispense $dispense): ?string => $dispense->performerLegalEntity?->value)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $legalEntityNames = $this->getLegalEntityNames($legalEntityUuids);

        $deviceNames = $this->getDeviceNames();

        $deviceRequestUuids = $paginator->getCollection()
            ->pluck('basedOn.value')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $deviceRequests = $this->personId === null
            ? collect()
            : DeviceRequestRequest::query()
                ->wherePersonId($this->personId)
                ->whereIn('uuid', $deviceRequestUuids)
                ->with('basedOn')
                ->get()
                ->keyBy('uuid');

        $activityUuids = $deviceRequests
            ->pluck('basedOn.value')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $carePlanActivities = CarePlanActivity::query()
            ->whereIn('uuid', $activityUuids)
            ->with('carePlan:id,uuid')
            ->get()
            ->keyBy('uuid');

        $paginator->setCollection(
            $paginator->getCollection()->map(
                static function (DeviceDispense $dispense) use ($deviceRequests, $carePlanActivities, $employeeNames, $legalEntityNames, $deviceNames): array {
                    $deviceRequest = $dispense->basedOn ? $deviceRequests->get($dispense->basedOn->value) : null;
                    $carePlanActivity = $deviceRequest?->basedOn ? $carePlanActivities->get($deviceRequest->basedOn->value) : null;
                    $data = Arr::toCamelCase($dispense->toArray());
                    $data['id'] = $dispense->id;
                    $deviceUuid = $dispense->details->first()?->device?->value;
                    if ($deviceUuid && !data_get($data, 'details.0.device.displayValue') && isset($deviceNames[$deviceUuid])) {
                        data_set($data, 'details.0.device.displayValue', $deviceNames[$deviceUuid]);
                    }
                    $performerUuid = $dispense->performer?->value;

                    if ($performerUuid && isset($employeeNames[$performerUuid])) {
                        data_set($data, 'performer.displayValue', $employeeNames[$performerUuid]);
                    }

                    $performerLegalEntityUuid = $dispense->performerLegalEntity?->value;

                    if ($performerLegalEntityUuid && isset($legalEntityNames[$performerLegalEntityUuid])) {
                        data_set($data, 'performerLegalEntity.displayValue', $legalEntityNames[$performerLegalEntityUuid]);
                    }

                    $data['procedureId'] = $dispense->partOf?->value;
                    $data['carePlanId'] = $carePlanActivity?->carePlan?->uuid;
                    $data['originEpisodeId'] = $dispense->originEpisodeId;
                    $data['encounterId'] = $dispense->encounter?->value;
                    $data['contextEpisodeId'] = $dispense->contextEpisodeId;
                    $data['createdAt'] = $dispense->created_at?->toDateTimeString();

                    return $data;
                }
            )
        );

        return $paginator;
    }

    /**
     * Fetch a single page of device dispenses from the eHealth API for the active search filters.
     *
     * @return LengthAwarePaginator
     */
    protected function searchDeviceDispensesFromEHealth(): LengthAwarePaginator
    {
        $perPage = config('pagination.per_page');
        $page = $this->getPage();

        try {
            $response = EHealth::deviceDispense()->getBySearchParams(
                $this->uuid,
                $this->buildSearchParams()
            );

            $deviceDispenses = collect(Arr::toCamelCase($this->formatDatesForDisplay($response->validate(), 'd.m.Y H:i')));
            $performerUuids = $deviceDispenses
                ->pluck('performer.identifier.value')
                ->filter()
                ->unique()
                ->values()
                ->all();

            $employeeNames = $this->getEmployeeNames($performerUuids);

            $legalEntityUuids = $deviceDispenses
                ->pluck('performerLegalEntity.identifier.value')
                ->filter()
                ->unique()
                ->values()
                ->all();

            $legalEntityNames = $this->getLegalEntityNames($legalEntityUuids);

            $deviceNames = $this->getDeviceNames();

            $deviceDispenses = $deviceDispenses->map(
                static function (array $deviceDispense) use ($employeeNames, $legalEntityNames, $deviceNames): array {
                    $deviceUuid = data_get($deviceDispense, 'details.0.device.identifier.value');

                    if ($deviceUuid  && !data_get($deviceDispense, 'details.0.device.displayValue') && isset($deviceNames[$deviceUuid])) {
                        data_set($deviceDispense, 'details.0.device.displayValue', $deviceNames[$deviceUuid]);
                    }

                    $performerUuid = data_get($deviceDispense, 'performer.identifier.value');

                    if ($performerUuid && !data_get($deviceDispense, 'performer.displayValue') && isset($employeeNames[$performerUuid])) {
                        data_set($deviceDispense, 'performer.displayValue', $employeeNames[$performerUuid]);
                    }

                    $legalEntityUuid = data_get($deviceDispense, 'performerLegalEntity.identifier.value');

                    if ($legalEntityUuid && !data_get($deviceDispense, 'performerLegalEntity.displayValue') && isset($legalEntityNames[$legalEntityUuid])) {
                        data_set($deviceDispense, 'performerLegalEntity.displayValue', $legalEntityNames[$legalEntityUuid]);
                    }
                    $deviceDispense['procedureId'] = data_get($deviceDispense, 'partOf.identifier.value');
                    $deviceDispense['encounterId'] = data_get($deviceDispense, 'encounter.identifier.value');
                    $deviceDispense['createdAt'] = data_get($deviceDispense, 'ehealthInsertedAt');

                    return $deviceDispense;
                }
            );

            $total = $response->getPaging()['total_entries'];
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while loading device dispenses');

            $deviceDispenses = collect();
            $total = 0;
        }

        return new LengthAwarePaginator(
            $deviceDispenses,
            $total,
            $perPage,
            $page,
            [
                'path' => LengthAwarePaginator::resolveCurrentPath()
            ]
        );
    }

    private function getDeviceNames(): array
    {
        return collect($this->devices)
            ->pluck('name', 'uuid')
            ->filter()
            ->toArray();
    }

    private function loadFilterOptions(): void
    {
        $this->episodes = Repository::episode()->getByPersonId($this->patient());
        $this->relatedEpisodes = $this->episodes;
        $this->encounters = Repository::encounter()->getByPersonId($this->patient());

        $this->getDevices();
        $this->getDeviceTypes();
        $this->getOrganizationsFromDb();
        $this->getPractitionersFromDb();
        $this->getProceduresFromDb();
        $this->getCarePlansFromDb();
    }

    private function getDevices(): void
    {
        $this->devices = dictionary()->deviceDefinitions()
            ->map(static fn (array $deviceDefinition): array => [
                'uuid' => $deviceDefinition['id'],
                'name' => data_get($deviceDefinition, 'device_names.0.name') ?? $deviceDefinition['id']
            ])
            ->unique('uuid')
            ->sortBy('name')
            ->values()
            ->toArray();
    }

    private function getDeviceTypes(): void
    {
        $this->deviceTypes = collect(
            data_get($this->dictionaries, 'device_definition_classification_type', [])
        )
            ->map(static fn (string $name, string|int $code): array => [
                'code' => (string) $code,
                'name' => $name
            ])
            ->sortBy('name')
            ->values()
            ->toArray();
    }

    private function getOrganizationsFromDb(): void
    {
        $this->organizations = [
            [
                'uuid' => legalEntity()->uuid,
                'name' => legalEntity()->name
            ]
        ];
    }

    private function getPractitionersFromDb(): void
    {
        $this->practitioners = Employee::whereLegalEntityId(legalEntity()->id)
            ->active()
            ->select(['uuid', 'party_id'])
            ->with('party:id,last_name,first_name,second_name')
            ->get()
            ->map(static fn (Employee $employee): array => [
                'uuid' => $employee->uuid,
                'name' => $employee->fullName
            ])
            ->toArray();
    }

    private function getProceduresFromDb(): void
    {
        $services = collect(dictionary()->services()->flattened()->toArray())
            ->mapWithKeys(static function (array $service): array {
                $name = collect([
                    data_get($service, 'code'),
                    data_get($service, 'name')
                ])->filter()->implode(' | ');

                return [
                    data_get($service, 'id') => $name
                ];
            });

        $this->procedures = Procedure::forPatient($this->patient())
            ->with('code')
            ->get(['id', 'uuid', 'code_id'])
            ->map(static function (Procedure $procedure) use ($services): array {
                return [
                    'uuid' => $procedure->uuid,
                    'name' => $services->get($procedure->code?->value) ?: $procedure->uuid
                ];
            })
            ->sortBy('name')
            ->values()
            ->toArray();
    }

    private function getCarePlansFromDb(): void
    {
        if ($this->personId === null) {
            $this->carePlans = [];

            return;
        }

        $this->carePlans = CarePlan::query()
            ->wherePersonId($this->personId)
            ->whereNotNull('uuid')
            ->get(['uuid', 'title'])
            ->map(static fn (CarePlan $carePlan): array => [
                'uuid' => $carePlan->uuid,
                'name' => $carePlan->title ?: $carePlan->uuid
            ])
            ->sortBy('name')
            ->values()
            ->toArray();
    }

    private function getEmployeeNames(array $uuids): array
    {
        return Employee::query()
            ->whereIn('uuid', $uuids)
            ->with('party:id,last_name,first_name,second_name')
            ->get(['uuid', 'party_id'])
            ->mapWithKeys(static fn (Employee $employee): array => [
                $employee->uuid => $employee->fullName
            ])
            ->filter()
            ->toArray();
    }

    private function getLegalEntityNames(array $uuids): array
    {
        return LegalEntity::query()
            ->whereIn('uuid', $uuids)
            ->get(['uuid', 'edr', 'beneficiary'])
            ->mapWithKeys(static fn (LegalEntity $legalEntity): array => [
                $legalEntity->uuid => $legalEntity->name
            ])
            ->filter()
            ->toArray();
    }
    
    private function buildSearchParams(): array
    {
        return array_filter([
            'device' => $this->filterDeviceId ?: null,
            'device_code' => $this->filterDeviceCode ?: null,
            'encounter' => $this->filterEncounterId ?: null,
            'status' => $this->filterStatus ?: null,
            'context_episode_id' => $this->filterEpisodeId ?: null,
            'performer_legal_entity' => $this->filterOrganization ?: legalEntity()->uuid,
            'performer' => $this->filterPractitioner ?: null,
            'part_of' => $this->filterProcedureId ?: null,
            'origin_episode_id' => $this->filterRelatedEpisodeId ?: null,
            'when_handed_over_from' => $this->filterDispenseDateFrom ?: null,
            'when_handed_over_to' => $this->filterDispenseDateTo ?: null,
            'inserted_at_from' => $this->filterCreatedAtFrom ?: null,
            'inserted_at_to' => $this->filterCreatedAtTo ?: null,
            'page' => $this->getPage(),
            'page_size' => config('pagination.per_page')
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    protected function filterValidationRules(): array
    {
        return [
            'filterDeviceId' => ['nullable', 'uuid'],
            'filterDeviceCode' => ['nullable', 'string', new InDictionary('device_definition_classification_type')],
            'filterEncounterId' => ['nullable', 'uuid'],
            'filterStatus' => ['nullable', Rule::in(Status::values())],
            'filterEpisodeId' => ['nullable', 'uuid'],
            'filterOrganization' => ['nullable', 'string', 'max:255'],
            'filterPractitioner' => ['nullable', 'uuid'],
            'filterProcedureId' => ['nullable', 'uuid'],
            'filterCarePlanId' => ['nullable', 'uuid'],
            'filterRelatedEpisodeId' => ['nullable', 'uuid'],
            'filterDispenseDateFrom' => ['nullable', 'date_format:' . config('app.date_format')],
            'filterDispenseDateTo' => ['nullable', 'date_format:' . config('app.date_format')],
            'filterCreatedAtFrom' => ['nullable', 'date_format:' . config('app.date_format')],
            'filterCreatedAtTo' => ['nullable', 'date_format:' . config('app.date_format')]
        ];
    }

    public function render(): View
    {
        return view('livewire.device-dispense.device-dispenses');
    }
}
