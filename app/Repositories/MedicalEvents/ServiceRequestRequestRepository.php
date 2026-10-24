<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Enums\Person\ServiceRequestStatus;
use App\Enums\Person\ServiceRequestProgramStatus;
use App\Models\CarePlanActivity;
use App\Models\Employee\Employee;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Repositories\MedicalEvents\Concerns\ResolvesRequestFhirRefs;
use App\Services\MedicalEvents\Mappers\ServiceRequestMapper;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Throwable;

/**
 * @property ServiceRequestRequest $model
 */
class ServiceRequestRequestRepository extends BaseRepository
{
    use ResolvesRequestFhirRefs;

    public function __construct(ServiceRequestRequest $model)
    {
        parent::__construct($model);
    }

    /**
     * Create or update service request request in DB for patient.
     *
     * @param  array  $data
     * @param  int  $personId
     * @return int
     * @throws Throwable
     */
    private const FHIR_REFERENCES = ['code' => ['codeReference', 'codeIdentifierId'], 'program' => ['programReference', 'programIdentifierId'], 'context' => ['context', 'contextId'], 'requesterEmployee' => ['requesterEmployee', 'requesterEmployeeId'], 'requesterLegalEntity' => ['requesterLegalEntity', 'requesterLegalEntityId'], 'subject' => ['subjectReference', 'subjectIdentifierId'], 'performer' => ['performer', 'performerId'], 'locationReference' => ['locationReference', 'locationReferenceId']];
    private const FHIR_COLLECTIONS = ['basedOn' => 'basedOnReferences', 'supportingInfo' => 'supportingInfoReferences', 'reasonReference' => 'reasonReferences', 'permittedResources' => 'permittedReferences', 'specimens' => 'specimenReferences', 'focus' => 'focusReferences'];

    public function store(array $data, int $personId): int
    {
        $existing = $this->model->newQuery()->whereUuid($data['uuid'] ?? $data['id'])->first();
        if ($existing !== null && $existing->personId !== $personId) {
            throw new \InvalidArgumentException('The Service Request belongs to another patient.');
        }
        if (!empty($data['based_on_uuid']) && !($data['_fhirImport'] ?? false)) {
            foreach ($this->activityContext((string) $data['based_on_uuid'], $personId) as $field => $value) {
                if (!array_key_exists($field, $data)) {
                    $data[$field] = $value;
                }
            }
        }
        $mapper = new ServiceRequestMapper();
        $original = $data['_fhir'] ?? ($existing !== null && ($existing->getAttributes()['fhir_version'] ?? null) === 1 ? $this->getResource($existing) : []);
        $employee = isset($data['employee_id']) ? Employee::with('legalEntity')->findOrFail($data['employee_id']) : $existing?->employee;
        $person = \App\Models\Person\Person::findOrFail($personId);
        $options = ['resource' => $original, 'timezone' => config('app.timezone'), 'carePlanUuid' => $data['care_plan_uuid'] ?? null];
        if ($original === []) {
            $options['person_uuid'] = $person->uuid;
        }
        if (!isset($original['requesterEmployee']) && !isset($original['requester_employee'])) {
            $options['employee_uuid'] = $employee?->uuid;
            $options['legal_entity_uuid'] = $employee?->legalEntity?->uuid;
        }
        if (!empty($data['context_uuid']) && empty($data['context_type'])) {
            $type = data_get($original, 'context.identifier.type.coding.0.code');
            if ($type === null && Encounter::whereUuid($data['context_uuid'])->wherePersonId($personId)->exists()) {
                $type = 'encounter';
            }
            $data['context_type'] = $type;
        }
        // A complete remote import must not be reduced to fields exposed by the old form.
        $resource = isset($data['_fhir']) && ($data['_fhirImport'] ?? false)
            ? $mapper->normalize($data['_fhir'])
            : $mapper->toFhir($data, $options);

        return $this->storeResource($resource, $personId, $data['employee_id'] ?? $existing?->employeeId, $data['division_id'] ?? $existing?->divisionId)->id;
    }

    public function storeResource(array $resource, int $personId, ?int $employeeId = null, ?int $divisionId = null): ServiceRequestRequest
    {
        $resource = (new ServiceRequestMapper())->normalize($resource);
        if (empty($resource['id']) || empty(data_get($resource, 'code.identifier.value'))) {
            throw new \InvalidArgumentException('A complete Service Request requires its identity and service reference.');
        }
        $person = \App\Models\Person\Person::findOrFail($personId);
        $subject = data_get($resource, 'subject.identifier.value') ?? data_get($resource, 'patient.identifier.value');
        if ($subject !== null && $subject !== $person->uuid) {
            throw new \InvalidArgumentException('The Service Request patient does not match its import context.');
        }
        if (isset($resource['requesterEmployee']['identifier']['value'])) {
            $employeeId = Employee::whereUuid($resource['requesterEmployee']['identifier']['value'])->value('id');
        }

        return DB::transaction(function () use ($resource, $personId, $employeeId, $divisionId): ServiceRequestRequest {
            $request = $this->model->newQuery()->whereUuid($resource['id'])->lockForUpdate()->first();
            if ($request !== null && $request->personId !== $personId) {
                throw new \InvalidArgumentException('The Service Request belongs to another patient.');
            }
            $request ??= new ServiceRequestRequest();
            if ($request->exists) {
                $request->load($this->fhirRelations());
            }
            $attributes = [
                'uuid' => $resource['id'], 'personId' => $personId, 'employeeId' => $employeeId, 'divisionId' => $divisionId,
                'status' => $resource['status'], 'clinicalStatus' => $resource['status'], 'requestNumber' => $resource['requisition'] ?? null,
                'serviceId' => data_get($resource, 'code.identifier.value'), 'quantity' => data_get($resource, 'quantity.value'),
                'programId' => data_get($resource, 'program.identifier.value'), 'note' => $resource['note'] ?? null,
                'patientInstruction' => $resource['patientInstruction'] ?? null,
                'startedAt' => $this->sqlDate(data_get($resource, 'occurrencePeriod.start')), 'endedAt' => $this->sqlDate(data_get($resource, 'occurrencePeriod.end')),
                'occurrenceDateTime' => $this->sqlDate($resource['occurrenceDateTime'] ?? null),
                'informWith' => data_get($resource, 'informWith.authMethodId'), 'fhirVersion' => 1,
                'supportingInfo' => (new ServiceRequestMapper())->fromFhir($resource)['supporting_info'],
                'reasonReference' => (new ServiceRequestMapper())->fromFhir($resource)['reason_reference'],
            ];
            foreach (self::FHIR_REFERENCES as $field => [$relation, $attribute]) {
                $existing = $request->exists ? $request->getRelation($relation) : null;
                $attributes[$attribute] = isset($resource[$field]) ? Repository::identifier()->replaceReference($existing, $resource[$field])->id : null;
            }
            foreach (['category' => ['category', 'categoryId'], 'performerType' => ['performerType', 'performerTypeId']] as $field => [$relation, $attribute]) {
                $existing = $request->exists ? $request->getRelation($relation) : null;
                $attributes[$attribute] = isset($resource[$field]) ? Repository::codeableConcept()->replaceComplete($existing, $resource[$field])->id : null;
            }
            $attributes['intentId'] = isset($resource['intent']) ? \App\Models\MedicalEvents\Sql\Coding::firstOrCreate(['system' => self::REQUEST_INTENT_SYSTEM, 'code' => $resource['intent']])->id : null;
            $attributes['priorityId'] = isset($resource['priority']) ? \App\Models\MedicalEvents\Sql\CodeableConcept::firstOrCreate(['text' => $resource['priority']])->id : null;
            foreach (['quantity' => ['quantityQuantity', 'quantityId'], 'remainingQuantity' => ['remainingQuantity', 'remainingQuantityId']] as $field => [$relation, $attribute]) {
                $attributes[$attribute] = $this->replaceQuantity($request->exists ? $request->getRelation($relation) : null, $resource[$field] ?? null);
            }
            $known = ['id', 'status', 'requisition', 'intent', 'priority', 'category', 'performerType', 'quantity', 'remainingQuantity', 'occurrencePeriod', 'occurrenceDateTime', 'note', 'patientInstruction', ...array_keys(self::FHIR_REFERENCES), ...array_keys(self::FHIR_COLLECTIONS)];
            $attributes['fhirMetadata'] = ['extra' => array_diff_key($resource, array_flip($known)), 'presentCollections' => array_values(array_intersect(array_keys(self::FHIR_COLLECTIONS), array_keys($resource))), 'occurrenceLexical' => $resource['occurrencePeriod'] ?? null, 'occurrenceDateTimeLexical' => $resource['occurrenceDateTime'] ?? null];
            $request->forceFill($attributes)->save();
            Repository::period()->syncExact($request, $resource['occurrencePeriod'] ?? null);
            foreach (self::FHIR_COLLECTIONS as $field => $relation) {
                $existing = $request->{$relation}()->get();
                $ids = [];
                foreach (array_values($resource[$field] ?? []) as $position => $reference) {
                    $identifier = Repository::identifier()->replaceReference($existing->get($position), $reference);
                    $ids[$identifier->id] = ['position' => $position];
                }
                $request->{$relation}()->sync($ids);
            }
            $activity = $request->basedOnReferences()->whereHas('type.coding', fn ($query) => $query->whereIn('code', ['activity', 'care_plan_activity']))->first();
            $request->basedOnId = $activity?->id;
            $request->save();

            return $request;
        });
    }

    public function getResource(ServiceRequestRequest|int $request): array
    {
        $request = is_int($request) ? $this->model->newQuery()->findOrFail($request) : $request;
        if (($request->getAttributes()['fhir_version'] ?? null) !== 1) {
            throw new \InvalidArgumentException('Service Request requires full import or explicit draft conversion before use.');
        }
        $request->load($this->fhirRelations());
        $resource = $request->fhirMetadata['extra'] ?? [];
        foreach (['id' => 'uuid', 'status' => 'clinicalStatus', 'requisition' => 'requestNumber', 'note' => 'note', 'patientInstruction' => 'patientInstruction'] as $field => $attribute) {
            if ($request->{$attribute} !== null) {
                $resource[$field] = $request->{$attribute};
            }
        }
        if ($request->intent !== null) {
            $resource['intent'] = $request->intent->code;
        }
        if ($request->priority !== null) {
            $resource['priority'] = $request->priority->text;
        }
        foreach (self::FHIR_REFERENCES as $field => [$relation]) {
            if ($request->getRelation($relation) !== null) {
                $resource[$field] = Repository::identifier()->toReference($request->getRelation($relation));
            }
        }
        foreach (['category', 'performerType'] as $field) {
            if ($request->getRelation($field) !== null) {
                $resource[$field] = Repository::codeableConcept()->toResource($request->getRelation($field));
            }
        }
        foreach (['quantity' => 'quantityQuantity', 'remainingQuantity' => 'remainingQuantity'] as $field => $relation) {
            $quantity = $request->getRelation($relation);
            if ($quantity !== null) {
                $resource[$field] = ['value' => $quantity->value];
                foreach (['system', 'code', 'unit', 'comparator'] as $key) {
                    if ($quantity->{$key} !== null) {
                        $resource[$field][$key] = $quantity->{$key};
                    }
                }
            }
        }
        if ($request->occurrencePeriod !== null) {
            $resource['occurrencePeriod'] = array_filter(Repository::period()->toResource($request->occurrencePeriod), static fn ($value) => $value !== null);
        }
        if ($request->occurrenceDateTime !== null) {
            $resource['occurrenceDateTime'] = \Carbon\CarbonImmutable::parse($request->getRawOriginal('occurrence_date_time'), 'UTC')->format('Y-m-d\TH:i:s.u\Z');
        }
        foreach ($request->fhirMetadata['occurrenceLexical'] ?? [] as $boundary => $original) {
            $actual = $resource['occurrencePeriod'][$boundary] ?? null;
            if ($original !== null && $actual !== null && \Carbon\CarbonImmutable::parse($original)->equalTo(\Carbon\CarbonImmutable::parse($actual))) {
                $resource['occurrencePeriod'][$boundary] = $original;
            }
        }
        $original = $request->fhirMetadata['occurrenceDateTimeLexical'] ?? null;
        if ($original !== null && isset($resource['occurrenceDateTime']) && \Carbon\CarbonImmutable::parse($original)->equalTo(\Carbon\CarbonImmutable::parse($resource['occurrenceDateTime']))) {
            $resource['occurrenceDateTime'] = $original;
        }
        foreach (self::FHIR_COLLECTIONS as $field => $relation) {
            if ($request->getRelation($relation)->isNotEmpty() || in_array($field, $request->fhirMetadata['presentCollections'] ?? [], true)) {
                $resource[$field] = $request->getRelation($relation)->map(fn ($item) => Repository::identifier()->toReference($item))->all();
            }
        }

        return $resource;
    }

    public function backfillResources(int $personId, bool $write = false): array
    {
        \App\Models\Person\Person::findOrFail($personId);
        $report = ['records' => 0, 'converted' => 0, 'needsRemoteSync' => [], 'errors' => []];
        $resources = [];
        $this->model->newQuery()->wherePersonId($personId)->with(['employee.legalEntity', 'category.coding', 'priority', 'intent', 'basedOn.type.coding', 'context.type.coding'])->chunkById(100, function ($records) use (&$report, &$resources): void {
            foreach ($records as $record) {
                $report['records']++;
                if (($record->getAttributes()['fhir_version'] ?? null) === 1) {
                    continue;
                }
                if (!in_array(strtolower($record->status), ['draft', 'new'], true) || filled($record->requestNumber)) {
                    $report['needsRemoteSync'][] = $record->id;
                    continue;
                }
                try {
                    $resources[$record->id] = $this->legacyDraftResource($record);
                } catch (\Throwable $exception) {
                    $report['errors'][] = ['localId' => $record->id, 'reason' => $exception->getMessage()];
                }
            }
        });
        if (!$write || $report['errors'] !== [] || $report['needsRemoteSync'] !== []) {
            return $report;
        }
        DB::transaction(function () use ($resources, $personId, &$report): void {
            foreach ($resources as $id => $resource) {
                $record = $this->model->newQuery()->whereKey($id)->wherePersonId($personId)->lockForUpdate()->firstOrFail();
                if (($record->getAttributes()['fhir_version'] ?? null) === 1) {
                    continue;
                }
                if (!in_array(strtolower($record->status), ['draft', 'new'], true) || filled($record->requestNumber)) {
                    throw new \InvalidArgumentException('The draft changed after preflight; repeat reconciliation.');
                }
                $resource = $this->legacyDraftResource($record);
                $this->storeResource($resource, $personId, $record->employeeId, $record->divisionId);
                $report['converted']++;
            }
        });

        return $report;
    }

    public function legacyDraftResource(ServiceRequestRequest $record): array
    {
        if (!in_array(strtolower($record->status), ['draft', 'new'], true) || filled($record->requestNumber)) {
            throw new \InvalidArgumentException('Registered historical resources require a complete remote import.');
        }
        $record->loadMissing(['employee.legalEntity', 'category.coding', 'priority', 'intent', 'basedOn.type.coding', 'context.type.coding']);
        if ($record->employee === null || $record->employee->legalEntity === null) {
            throw new \InvalidArgumentException('Historical author identity cannot be recovered.');
        }
        $data = $record->getAttributes();
        $data['category'] = $record->category?->coding?->first()?->code ?? $record->category?->text;
        $data['priority'] = $record->priority?->text;
        $data['intent'] = $record->intent?->code ?? 'order';
        $data['based_on_uuid'] = $record->basedOn?->value;
        $data['context_uuid'] = $record->context?->value;
        $data['supporting_info'] = $record->supportingInfo;
        $data['reason_reference'] = $record->reasonReference;
        $contextType = $record->context?->type?->first()?->coding?->first()?->code;
        if ($data['context_uuid'] !== null && $contextType === null) {
            if (!Encounter::whereUuid($data['context_uuid'])->wherePersonId($record->personId)->exists()) {
                throw new \InvalidArgumentException('Historical context is untyped and cannot be matched to a patient encounter.');
            }
            $contextType = 'encounter';
        }
        $data['context_type'] = $contextType;
        $activity = $data['based_on_uuid'] ? CarePlanActivity::whereUuid($data['based_on_uuid'])->whereHas('carePlan', fn ($query) => $query->wherePersonId($record->personId))->first() : null;
        if ($data['based_on_uuid'] && $activity === null) {
            throw new \InvalidArgumentException('The historical activity reference cannot be matched to this patient.');
        }
        $options = ['employee_uuid' => $record->employee->uuid, 'legal_entity_uuid' => $record->employee->legalEntity->uuid, 'carePlanUuid' => $activity?->carePlan?->uuid, 'timezone' => config('app.timezone')];
        if ($record->quantity !== null) {
            $data['quantity_system'] = $activity?->quantitySystem;
            $data['quantity_code'] = $activity?->quantityCode;
            if (empty($data['quantity_system']) || empty($data['quantity_code'])) {
                throw new \InvalidArgumentException('Historical quantity units are missing; reconciliation is required.');
            }
        }
        if ($activity !== null) {
            $data['service_type'] = $activity->productReferenceIdentifier?->type?->first()?->coding?->first()?->code;
            if (empty($data['service_type'])) {
                throw new \InvalidArgumentException('Historical service/service_group type cannot be recovered.');
            }
        }

        return (new ServiceRequestMapper())->toFhir($data, $options);
    }

    public function person(int $id): ?\App\Models\Person\Person
    {
        return \App\Models\Person\Person::find($id);
    }

    public function activityForDraft(int $id, int $carePlanId, int $personId): CarePlanActivity
    {
        return CarePlanActivity::whereKey($id)->whereCarePlanId($carePlanId)->whereHas('carePlan', fn ($query) => $query->wherePersonId($personId))->with('carePlan')->firstOrFail();
    }

    public function executorContext(Employee $employee): array
    {
        $employee->loadMissing(['division', 'legalEntity']);

        return ['employee_uuid' => $employee->uuid, 'division_uuid' => $employee->divisionUuid ?: $employee->division?->uuid, 'legal_entity_uuid' => $employee->legalEntityUuid ?: $employee->legalEntity?->uuid];
    }

    public function updateRequestNumber(string $uuid, string $number): void
    {
        $this->model->newQuery()->whereUuid($uuid)->update(['request_number' => $number]);
    }

    public function updateClinicalStatus(ServiceRequestRequest $record, string $status): void
    {
        $attributes = ['status' => $status, 'clinicalStatus' => $status];
        if ($status === ServiceRequestStatus::COMPLETED->value && isset($record->fhirMetadata['extra']['programProcessingStatus'])) {
            $metadata = $record->fhirMetadata;
            $metadata['extra']['programProcessingStatus'] = ServiceRequestProgramStatus::COMPLETED->value;
            $attributes['fhirMetadata'] = $metadata;
        }
        $record->forceFill($attributes)->save();
    }

    public function applyExecutionResult(ServiceRequestRequest $record, array $response, ?string $programId): void
    {
        $metadata = $record->fhirMetadata ?? [];
        $metadata['extra']['programProcessingStatus'] = $response['program_processing_status'] ?? $response['programProcessingStatus'] ?? ServiceRequestProgramStatus::IN_PROGRESS->value;
        $record->forceFill(['status' => ServiceRequestStatus::IN_PROGRESS->value, 'fhirMetadata' => $metadata]);
        if ($programId !== null) {
            $reference = ['identifier' => ['type' => ['coding' => [['system' => 'eHealth/resources', 'code' => 'medical_program']]], 'value' => $programId]];
            $record->programIdentifierId = Repository::identifier()->replaceReference($record->programReference, $reference)->id;
            $record->programId = $programId;
        }
        $record->save();
    }

    public function cacheExecutionResource(array $remote, string $patientUuid, Employee $employee): ?ServiceRequestRequest
    {
        $person = \App\Models\Person\Person::whereUuid($patientUuid)->first();
        $resource = (new ServiceRequestMapper())->normalize($remote);
        // A summary is insufficient to materialize a new clinical document.
        if ($person === null || empty($resource['id']) || empty(data_get($resource, 'code.identifier.value'))) {
            return null;
        }

        return $this->storeResource($resource, $person->id, null, null);
    }

    public function assertCompletionResourceOwned(string $referralUuid, string $resourceUuid, string $resourceType): void
    {
        $referral = $this->findByUuid($referralUuid);
        $personId = $referral?->personId;

        $resource = match ($resourceType) {
            'encounter' => \App\Models\MedicalEvents\Sql\Encounter::query()->where('uuid', $resourceUuid)->first(),
            'procedure' => \App\Models\MedicalEvents\Sql\Procedure::query()->where('uuid', $resourceUuid)->first(),
            'diagnostic_report' => \App\Models\MedicalEvents\Sql\DiagnosticReport::query()->where('uuid', $resourceUuid)->first(),
            default => null,
        };

        if ($resource === null) {
            throw new \InvalidArgumentException(__('care-plan.referral_complete_emz_required'));
        }

        $resourcePersonId = $resource->personId ?? $resource->person_id ?? null;
        if ($personId !== null && $resourcePersonId !== null && (int) $resourcePersonId !== (int) $personId) {
            throw new \InvalidArgumentException(__('care-plan.referral_complete_emz_mismatch'));
        }
    }

    public function activityContext(string $uuid, int $personId): array
    {
        $activity = CarePlanActivity::whereUuid($uuid)->with(['carePlan', 'productReferenceIdentifier.type.coding'])->first();
        if ($activity === null) {
            return [];
        }
        if ($activity->carePlan->personId !== $personId) {
            throw new \InvalidArgumentException('The activity belongs to another patient.');
        }
        $type = $activity->productReferenceIdentifier?->type?->first()?->coding?->first()?->code;
        $system = $activity->quantitySystem;
        $code = $activity->quantityCode;
        $serviceId = $activity->productReference;
        $programId = $activity->program;
        if (($activity->getAttributes()['fhir_version'] ?? null) === 1 && method_exists(\App\Repositories\CarePlanActivityRepository::class, 'getResource')) {
            $resource = app(\App\Repositories\CarePlanActivityRepository::class)->getResource($activity->id);
            $system = data_get($resource, 'detail.quantity.system');
            $code = data_get($resource, 'detail.quantity.code');
            $type = data_get($resource, 'detail.productReference.identifier.type.coding.0.code');
            $serviceId = data_get($resource, 'detail.productReference.identifier.value');
            $programId = data_get($resource, 'detail.program.identifier.value');
        }

        return array_filter(['care_plan_uuid' => $activity->carePlan->uuid, 'service_id' => $serviceId, 'program_id' => $programId, 'service_type' => $type, 'quantity_system' => $system, 'quantity_code' => $code], static fn ($value) => $value !== null);
    }

    public function formData(ServiceRequestRequest $request): array
    {
        return (new ServiceRequestMapper())->fromFhir($this->getResource($request));
    }

    private function fhirRelations(): array
    {
        return ['intent', 'priority', 'category.coding', 'performerType.coding', 'quantityQuantity', 'remainingQuantity', 'occurrencePeriod', ...array_map(static fn (array $entry): string => $entry[0].'.type.coding', array_values(self::FHIR_REFERENCES)), ...array_map(static fn (string $name): string => $name.'.type.coding', array_values(self::FHIR_COLLECTIONS))];
    }

    private function replaceQuantity(?\App\Models\MedicalEvents\Sql\Quantity $quantity, ?array $data): ?int
    {
        if ($data === null) {
            return null;
        }
        $value = $data['value'] ?? null;
        if (!is_numeric($value) || !is_finite((float) $value) || abs((float) $value) >= 1e14) {
            throw new \InvalidArgumentException('Quantity is outside the supported range.');
        }
        try {
            $decimal = \Brick\Math\BigDecimal::of((string) $value)->toScale(10);
        } catch (\Brick\Math\Exception\MathException $exception) {
            throw new \InvalidArgumentException('Quantity precision must not be silently rounded.', previous: $exception);
        }
        if (!$decimal->isEqualTo((string) (float) $value)) {
            throw new \InvalidArgumentException('Quantity cannot be represented without loss in the JSON numeric contract.');
        }
        $quantity ??= new \App\Models\MedicalEvents\Sql\Quantity();
        $quantity->fill(['value' => $value, 'system' => $data['system'] ?? null, 'code' => $data['code'] ?? null, 'unit' => $data['unit'] ?? null, 'comparator' => $data['comparator'] ?? null])->save();

        return $quantity->id;
    }

    private function sqlDate(?string $value): ?string
    {
        return $value === null ? null : \Carbon\CarbonImmutable::parse($value, 'UTC')->utc()->format('Y-m-d H:i:s.u');
    }

    /**
     * @param  array{
     *     status?: string|null,
     *     started_at_from?: string|null,
     *     started_at_to?: string|null,
     *     ended_at_from?: string|null,
     *     ended_at_to?: string|null
     * }  $filters
     * @return list<array<string, mixed>>
     */
    public function searchByPersonId(int $personId, array $filters = []): array
    {
        $query = $this->model
            ->newQuery()
            ->with(['basedOn', 'context', 'category.coding', 'priority'])
            ->where('person_id', $personId);

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $query->whereRaw('LOWER(status) = ?', [strtolower($status)]);
        }

        if (!empty($filters['started_at_from'])) {
            $query->whereDate('started_at', '>=', $filters['started_at_from']);
        }
        if (!empty($filters['started_at_to'])) {
            $query->whereDate('started_at', '<=', $filters['started_at_to']);
        }
        if (!empty($filters['ended_at_from'])) {
            $query->whereDate('ended_at', '>=', $filters['ended_at_from']);
        }
        if (!empty($filters['ended_at_to'])) {
            $query->whereDate('ended_at', '<=', $filters['ended_at_to']);
        }

        $requests = $query
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->get();

        $activityUuids = $requests
            ->map(fn ($r) => $r->basedOn?->value)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $carePlanIdsByActivityUuid = $activityUuids === []
            ? []
            : CarePlanActivity::query()
                ->whereIn('uuid', $activityUuids)
                ->get(['id', 'uuid', 'care_plan_id'])
                ->keyBy('uuid')
                ->all();

        $encounterUuids = $requests
            ->map(fn ($r) => $r->context?->value)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $encounterIdsByUuid = $encounterUuids === []
            ? []
            : Encounter::query()
                ->whereIn('uuid', $encounterUuids)
                ->pluck('id', 'uuid')
                ->all();

        return $requests
            ->map(fn (ServiceRequestRequest $request): array => $this->toPatientRegistryRow(
                $request,
                $carePlanIdsByActivityUuid,
                $encounterIdsByUuid
            ))
            ->all();
    }

    /**
     * @param  array<string, object>  $carePlanIdsByActivityUuid
     * @param  array<string, int>  $encounterIdsByUuid
     * @return array<string, mixed>
     */
    public function toPatientRegistryRow(
        ServiceRequestRequest $request,
        array $carePlanIdsByActivityUuid = [],
        array $encounterIdsByUuid = []
    ): array {
        $status = strtolower((string) $request->status);
        $startedAt = $request->startedAt;
        $endedAt = $request->endedAt;
        $qty = $request->quantity;
        $qtyLabel = $qty !== null && $qty !== ''
            ? rtrim(rtrim(number_format((float) $qty, 2, '.', ''), '0'), '.')
            : '';

        $category = strtolower((string) ($request->category?->coding?->first()?->code ?? $request->category?->text ?? ''));
        $categoryKey = 'care-plan.referral_category.'.$category;
        $categoryLabel = $category !== '' && Lang::has($categoryKey)
            ? __($categoryKey)
            : ($category !== '' ? $category : '—');

        $priority = strtolower((string) ($request->priority?->text ?? ''));
        $priorityKey = 'care-plan.referral_priority.'.$priority;
        $priorityLabel = $priority !== '' && Lang::has($priorityKey)
            ? __($priorityKey)
            : ($priority !== '' ? $priority : '—');

        $serviceId = (string) ($request->serviceId ?? '');
        $itemName = $serviceId !== '' && preg_match('/^[0-9a-f-]{36}$/i', $serviceId) !== 1
            ? $serviceId
            : $categoryLabel;

        $activityUuid = $request->basedOn?->value;
        $encounterUuid = $request->context?->value;
        $activityData = $activityUuid ? ($carePlanIdsByActivityUuid[$activityUuid] ?? null) : null;
        $activityId = $activityData ? $activityData->id : null;
        $carePlanId = $activityData ? $activityData->care_plan_id : null;
        $encounterId = $encounterUuid ? ($encounterIdsByUuid[$encounterUuid] ?? null) : null;

        $basisLabel = match (true) {
            $activityId !== null && $activityId > 0 => 'План лікування',
            $encounterId !== null && $encounterId > 0 => 'Взаємодія',
            default => '—',
        };

        $periodLabel = '—';
        if ($startedAt !== null && $endedAt !== null) {
            $periodLabel = $startedAt->format('d.m.Y').' — '.$endedAt->format('d.m.Y');
        } elseif ($startedAt !== null) {
            $periodLabel = 'з '.$startedAt->format('d.m.Y');
        }

        $draftStatuses = [
            ServiceRequestStatus::DRAFT->value,
            ServiceRequestStatus::NEW->value,
        ];

        return [
            'id' => $request->id,
            'uuid' => (string) $request->uuid,
            'kind' => 'service_request',
            'requestNumber' => trim((string) ($request->requestNumber ?? '')),
            'status' => (string) $request->status,
            'statusLabel' => ServiceRequestStatus::labelFor($status),
            'statusBadge' => ServiceRequestStatus::colorFor($status),
            'itemName' => $itemName !== '' && $itemName !== '—' ? $itemName : 'Послуга',
            'quantity' => $qtyLabel !== '' ? $qtyLabel : '—',
            'startedAt' => $startedAt?->toDateString(),
            'endedAt' => $endedAt?->toDateString(),
            'periodLabel' => $periodLabel,
            'programName' => $this->displayProgramName($request->programId),
            'categoryLabel' => $categoryLabel,
            'priorityLabel' => $priorityLabel,
            'note' => (string) ($request->note ?? ''),
            'patientInstruction' => (string) ($request->patientInstruction ?? ''),
            'basisLabel' => $basisLabel,
            'encounterId' => $encounterId,
            'activityId' => $activityId,
            'carePlanId' => $carePlanId,
            'canSign' => in_array($status, $draftStatuses, true),
            'canOperate' => $status === ServiceRequestStatus::ACTIVE->value,
            'canRecall' => $status === ServiceRequestStatus::ACTIVE->value,
            'canCancel' => $status === ServiceRequestStatus::ACTIVE->value,
        ];
    }

    /**
     * Get service request requests data related to the person.
     *
     * @param  int  $personId
     * @return array
     */
    public function getByPersonId(int $personId): array
    {
        return $this->model
            ->where('person_id', $personId)
            ->get()
            ->toArray();
    }

    public function findByUuid(string $uuid): ?ServiceRequestRequest
    {
        return $this->model->newQuery()->where('uuid', $uuid)->first();
    }

    /**
     * @param  array<string, mixed>  $referral
     */
    public function storeExternalIfMissing(array $referral, Employee $employee, int $personId): void
    {
        $data = new ServiceRequestMapper()->fromFhir($referral);
        $uuid = $data['uuid'] ?? null;

        if (blank($uuid)) {
            return;
        }
        $existing = $this->findByUuid($uuid);
        if ($existing !== null) {
            if ((int) $existing->personId !== $personId) {
                throw new \InvalidArgumentException('A Service Request UUID already belongs to another patient.');
            }

            return;
        }

        $this->store([
            ...$data,
            'employee_id' => $employee->id,
            'division_id' => $employee->divisionId,
            'status' => $data['status'] ?? ServiceRequestStatus::ACTIVE->value,
            '_fhirImport' => true,
        ], $personId);
    }

    public function storeExternalManyIfMissing(array $referrals, int $personId): void
    {
        if ($referrals === []) {
            return;
        }

        $mappedReferrals = collect($referrals)
            ->map(function (array $item): array {
                $data = new ServiceRequestMapper()->fromFhir($item['referral']);

                return [
                    'data' => $data,
                    'employee' => $item['employee'],
                ];
            })
            ->filter(fn (array $item): bool => filled($item['data']['uuid'] ?? null))
            ->unique(fn (array $item): string => $item['data']['uuid'])
            ->values();

        $existingUuids = $this->model
            ->whereIn('uuid', $mappedReferrals->pluck('data.uuid')->all())
            ->get(['uuid', 'person_id']);
        if ($existingUuids->contains(fn ($record): bool => (int) $record->personId !== $personId)) {
            throw new \InvalidArgumentException('A Service Request UUID already belongs to another patient.');
        }
        $existingUuids = $existingUuids->pluck('uuid')->all();

        foreach ($mappedReferrals as $item) {
            $data = $item['data'];
            $employee = $item['employee'];

            if (in_array($data['uuid'], $existingUuids, true)) {
                continue;
            }

            $this->store([
                ...$data,
                'employee_id' => $employee->id,
                'division_id' => $employee->divisionId,
                'status' => $data['status'] ?? ServiceRequestStatus::ACTIVE->value,
                '_fhirImport' => true,
            ], $personId);
        }
    }

    public function sumIssuedQuantityByActivity(string $activityUuid, array $excludedStatuses = MedicalEventsRequestStatuses::EXCLUDED_FROM_ISSUED_SUM, ?string $excludedUuid = null): float
    {
        return (float) $this->model->newQuery()
            ->whereHas('basedOn', fn ($q) => $q->where('value', $activityUuid))
            ->whereNotIn('status', $excludedStatuses)
            ->when($excludedUuid, fn ($query) => $query->where('uuid', '!=', $excludedUuid))
            ->sum('quantity');
    }

    public function findDraftByActivity(string $activityUuid): ?ServiceRequestRequest
    {
        return $this->model->newQuery()
            ->whereHas('basedOn', fn ($q) => $q->where('value', $activityUuid))
            ->whereIn('status', ['draft', 'DRAFT'])
            ->latest('id')
            ->first();
    }

    /**
     * Resolve optional transfer fields to the existing FHIR Identifier/CodeableConcept tables.
     * Omitted fields preserve a draft's references during partial response updates.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, int|null>
     */
    private function transferPerformerAttributes(array $data): array
    {
        if (!array_key_exists('performer', $data)
            && !array_key_exists('location_reference', $data)
            && !array_key_exists('performer_type', $data)) {
            return [];
        }

        $existing = $this->model->newQuery()
            ->with(['performer', 'locationReference', 'performerType.coding'])
            ->where('uuid', $data['uuid'] ?? $data['id'])
            ->first();
        $attributes = [];

        foreach (['performer' => 'legal_entity', 'location_reference' => 'division'] as $field => $type) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $value = trim((string) ($data[$field] ?? ''));
            $relation = $field === 'performer' ? $existing?->performer : $existing?->locationReference;
            if ($value === '') {
                $attributes[$field.'_id'] = null;
            } elseif ($relation?->value === $value) {
                $attributes[$field.'_id'] = $relation->id;
            } else {
                $identifier = Repository::identifier()->store($value);
                Repository::codeableConcept()->attach($identifier, [
                    'identifier' => ['type' => ['coding' => [['system' => 'eHealth/resources', 'code' => $type]]]],
                ]);
                $attributes[$field.'_id'] = $identifier->id;
            }
        }

        if (array_key_exists('performer_type', $data)) {
            $code = trim((string) ($data['performer_type'] ?? ''));
            $concept = $existing?->performerType;
            if ($code === '') {
                $attributes['performer_type_id'] = null;
            } elseif ($concept?->coding->first()?->code === $code) {
                $attributes['performer_type_id'] = $concept->id;
            } else {
                $attributes['performer_type_id'] = Repository::codeableConcept()->store([
                    'coding' => [['system' => 'SPECIALITY_TYPE', 'code' => $code]],
                ])->id;
            }
        }

        return $attributes;
    }

    private function displayProgramName(mixed $programId): string
    {
        $value = trim((string) ($programId ?? ''));
        if ($value === '' || preg_match('/^[0-9a-f-]{36}$/i', $value) === 1) {
            return '—';
        }

        return $value;
    }

    /**
     * @param  array<int, string>  $columns
     * @return Collection<int, ServiceRequestRequest>
     */
    public function getByPersonIdAndStatus(int $personId, string|array $status, array $columns = ['*']): Collection
    {
        return $this->model
            ->newQuery()
            ->where('person_id', $personId)
            ->whereIn('status', (array) $status)
            ->get($columns);
    }

    /**
     * @param  array<string, mixed>  $procedure
     */
    public function procedureReferralLabel(array $procedure): string
    {
        $paperReferral = data_get($procedure, 'paperReferral.requisition');

        if (filled($paperReferral)) {
            return (string) $paperReferral;
        }

        $uuid = data_get($procedure, 'basedOn.identifier.value')
            ?: data_get($procedure, 'basedOn.0.identifier.value');

        if (blank($uuid)) {
            return '-';
        }

        $displayValue = data_get($procedure, 'basedOn.identifier.displayValue')
            ?: data_get($procedure, 'basedOn.0.identifier.displayValue');

        if (filled($displayValue) && $displayValue !== $uuid) {
            return (string) $displayValue;
        }

        $requestNumber = $this->model
            ->newQuery()
            ->where('uuid', $uuid)
            ->value('request_number');

        return $requestNumber ?: (string) $uuid;
    }

    /**
     * @param  array<string, mixed>  $encounter
     * @param  array<string, string>  $requestNumbersByUuid
     */
    public function encounterReferralLabel(array $encounter, array $requestNumbersByUuid = []): string
    {
        $paperReferral = data_get($encounter, 'paperReferral.requisition');

        if (filled($paperReferral)) {
            return (string) $paperReferral;
        }

        $uuid = $this->incomingReferralUuid($encounter);
        $displayValue = data_get($encounter, 'incomingReferral.displayValue')
            ?: data_get($encounter, 'incomingReferral.display_value');

        if (filled($displayValue) && (string) $displayValue !== (string) $uuid) {
            return (string) $displayValue;
        }

        if (filled($uuid) && isset($requestNumbersByUuid[$uuid]) && $requestNumbersByUuid[$uuid] !== '') {
            return $requestNumbersByUuid[$uuid];
        }

        if (filled($displayValue)) {
            return (string) $displayValue;
        }

        return $uuid ?? '-';
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $encounters
     * @return array<string, string>
     */
    public function requestNumbersForEncounters(iterable $encounters): array
    {
        $uuids = collect($encounters)
            ->map(fn (array $encounter): ?string => $this->incomingReferralUuid($encounter))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($uuids === []) {
            return [];
        }

        return $this->model
            ->newQuery()
            ->whereIn('uuid', $uuids)
            ->whereNotNull('request_number')
            ->pluck('request_number', 'uuid')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $encounter
     */
    private function incomingReferralUuid(array $encounter): ?string
    {
        $uuid = data_get($encounter, 'incomingReferral.identifier.value')
            ?: data_get($encounter, 'incomingReferral.value');

        return filled($uuid) ? (string) $uuid : null;
    }

    public function saveStatus(ServiceRequestRequest $record, string $status): void
    {
        $record->status = $status;
        $record->save();
    }

    public function refreshRecord(ServiceRequestRequest $record): void
    {
        $record->refresh();
    }
}
