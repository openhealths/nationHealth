<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Classes\eHealth\Api\Patient\ServiceRequest as ServiceRequestApi;
use App\Core\Arr;
use App\Enums\JobStatus;
use App\Enums\Person\EncounterStatus;
use App\Enums\Person\ServiceRequestProgramStatus;
use App\Enums\Person\ServiceRequestStatus;
use App\Jobs\RemoteEHealthLinksProcessing;
use App\Models\CarePlanActivity;
use App\Models\EhealthLink;
use App\Models\Employee\Employee;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Coding;
use App\Models\MedicalEvents\Sql\Condition;
use App\Models\MedicalEvents\Sql\DiagnosticReport;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\MedicalEvents\Sql\Observation;
use App\Models\MedicalEvents\Sql\Procedure;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\MedicalEvents\Sql\Specimen;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\Concerns\ResolvesRequestFhirRefs;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use App\Repositories\Repository;
use App\Services\Dictionary\ServiceSearch;
use App\Services\MedicalEvents\Mappers\ServiceRequestMapper;
use App\Services\MedicalEvents\Mappers\ServiceRequestRegistryMapper;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * @property ServiceRequestRequest $model
 */
class ServiceRequestRequestRepository extends BaseRepository
{
    use ResolvesRequestFhirRefs;

    private ?ServiceRequestRegistryMapper $registryMapper = null;

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
    private const FHIR_REFERENCES = ['code' => ['codeReference', 'codeIdentifierId'], 'program' => ['programReference', 'programIdentifierId'], 'context' => ['context', 'contextId'], 'requesterEmployee' => ['requesterEmployee', 'requesterEmployeeId'], 'requesterLegalEntity' => ['requesterLegalEntity', 'requesterLegalEntityId'], 'subject' => ['subjectReference', 'subjectIdentifierId'], 'performer' => ['performer', 'performerId'], 'locationReference' => ['locationReference', 'locationReferenceId'], 'usedByEmployee' => ['usedByEmployee', 'usedByEmployeeId'], 'usedByLegalEntity' => ['usedByLegalEntity', 'usedByLegalEntityId'], 'completedWith' => ['completedWith', 'completedWithId']];
    private const FHIR_COLLECTIONS = ['basedOn' => 'basedOnReferences', 'supportingInfo' => 'supportingInfoReferences', 'reasonReference' => 'reasonReferences', 'permittedResources' => 'permittedReferences', 'specimens' => 'specimenReferences', 'focus' => 'focusReferences'];

    public function store(array $resource, int|Person|Preperson $personId, ?int $employeeId = null, ?int $divisionId = null): int
    {
        if (empty($resource['id'])) {
            throw new InvalidArgumentException('A mapped Service Request requires its identity.');
        }

        return $this->storageTransaction(function () use ($resource, $personId, $employeeId, $divisionId): int {
            $existing = $this->model->newQuery()->whereUuid($resource['id'])->lockForUpdate()->first();
            if ($existing !== null && $this->hasPendingSubmission($existing)) {
                throw ValidationException::withMessages(['submission' => __('care-plan.submission_pending')]);
            }

            return $this->storeResource($resource, $personId, $employeeId ?? $existing?->employeeId, $divisionId ?? $existing?->divisionId)->id;
        });
    }

    public function authorForDraft(?int $employeeId, ?string $employeeUuid): ?Employee
    {
        if ($employeeId === null && blank($employeeUuid)) {
            return null;
        }

        return Employee::with('legalEntity')->when(
            $employeeId !== null,
            fn ($query) => $query->whereKey($employeeId),
            fn ($query) => $query->whereUuid($employeeUuid)
        )->first();
    }

    public function patientForEncounter(Encounter $encounter): Person|Preperson|null
    {
        return $encounter->person ?? $encounter->preperson;
    }

    public function patientForRequest(ServiceRequestRequest $request): Person|Preperson|null
    {
        return $request->person ?? $request->preperson;
    }

    public function specimenForPatient(int $id, Person|Preperson $patient): Specimen
    {
        return Specimen::forPatient($patient)->notDraft()->whereKey($id)->firstOrFail();
    }

    public function specimenAuthorChoices(Specimen $specimen): array
    {
        return Auth::user()->employees()->whereLegalEntityId(legalEntity()->id)->with(['party', 'legalEntity'])
            ->get()->filter(fn (Employee $employee): bool => Auth::user()->can('createFromSpecimen', [ServiceRequestRequest::class, $employee, $specimen]))
            ->map(static fn (Employee $employee): array => ['id' => $employee->id, 'uuid' => $employee->uuid, 'label' => $employee->party?->fullName ?? $employee->uuid])
            ->values()->all();
    }

    public function draftForSpecimen(Specimen $specimen, Person|Preperson $patient): ?ServiceRequestRequest
    {
        return $this->model->newQuery()->forPatient($patient)->whereStatus('draft')
            ->whereHas('focusReferences', static fn ($query) => $query->whereValue($specimen->uuid))
            ->whereHas('employee', static fn ($query) => $query->whereLegalEntityId(legalEntity()->id))
            ->latest('id')->first();
    }

    public function requestForSpecimen(string $uuid, Specimen $specimen, Person|Preperson $patient): ServiceRequestRequest
    {
        return $this->model->newQuery()->forPatient($patient)->whereUuid($uuid)
            ->whereHas('focusReferences', static fn ($query) => $query->whereValue($specimen->uuid))
            ->whereHas('employee', static fn ($query) => $query->whereLegalEntityId(legalEntity()->id))
            ->firstOrFail();
    }

    /** Exclude every patient identity field from the specimen-context UI projection. */
    public function specimenReferralRows(array $validated): array
    {
        return array_map(static fn (array $item): array => [
            'id' => $item['id'],
            'requisition' => $item['requisition'],
            'status' => $item['status'],
            'category' => data_get($item, 'category.coding.0.code'),
            'service' => data_get($item, 'code.display_value'),
            'program_processing_status' => $item['program_processing_status'] ?? null,
        ], $validated);
    }

    public function assertFhirStorageReady(bool $beforeSubmission = false): void
    {
        $this->assertStorageSchema([
            'service_request_requests' => ['code_identifier_id', 'program_identifier_id', 'requester_employee_id', 'requester_legal_entity_id', 'subject_identifier_id', 'quantity_id', 'remaining_quantity_id', 'occurrence_date_time', 'clinical_status', 'program_processing_status', 'authored_on', 'explanatory_letter', 'status_reason_id', 'details_synced_at', 'used_by_employee_id', 'used_by_legal_entity_id', 'completed_with_id', 'status_history', 'expiration_date'],
            'service_request_references' => ['service_request_id', 'identifier_id', 'collection', 'position'],
        ], $beforeSubmission ? 'clinical-storage.submission_update_required' : 'clinical-storage.update_required');
    }

    public function storeResource(array $resource, int|Person|Preperson $personId, ?int $employeeId = null, ?int $divisionId = null): ServiceRequestRequest
    {
        if (empty($resource['id']) || empty(data_get($resource, 'code.identifier.value'))) {
            throw new InvalidArgumentException('A complete Service Request requires its identity and service reference.');
        }
        $person = is_int($personId) ? Person::findOrFail($personId) : $personId;
        $patientColumn = $person instanceof Preperson ? 'preperson_id' : 'person_id';
        $subject = data_get($resource, 'subject.identifier.value') ?? data_get($resource, 'patient.identifier.value');
        if ($subject !== null && $subject !== $person->uuid) {
            throw new InvalidArgumentException('The Service Request patient does not match its import context.');
        }
        if (isset($resource['requesterEmployee']['identifier']['value'])) {
            $employeeId = Employee::whereUuid($resource['requesterEmployee']['identifier']['value'])->value('id');
        }

        return $this->storageTransaction(function () use ($resource, $personId, $person, $patientColumn, $employeeId, $divisionId): ServiceRequestRequest {
            $request = $this->model->newQuery()->whereUuid($resource['id'])->lockForUpdate()->first();
            if ($request !== null && (!$request->forPatient($person)->whereKey($request->id)->exists())) {
                throw new InvalidArgumentException('The Service Request belongs to another patient.');
            }
            $request ??= new ServiceRequestRequest();
            if ($request->exists) {
                $request->load($this->fhirRelations());
            }
            $attributes = [
                'uuid' => $resource['id'], 'person_id' => null, 'preperson_id' => null, $patientColumn => $person->id, 'employeeId' => $employeeId, 'divisionId' => $divisionId,
                'status' => $resource['status'], 'clinicalStatus' => $resource['status'], 'requestNumber' => $resource['requisition'] ?? null,
                'serviceId' => data_get($resource, 'code.identifier.value'), 'quantity' => data_get($resource, 'quantity.value'),
                'programId' => data_get($resource, 'program.identifier.value'), 'note' => $resource['note'] ?? null,
                'patientInstruction' => $resource['patientInstruction'] ?? null,
                'startedAt' => $this->sqlDate(data_get($resource, 'occurrencePeriod.start')), 'endedAt' => $this->sqlDate(data_get($resource, 'occurrencePeriod.end')),
                'occurrenceDateTime' => $this->sqlDate($resource['occurrenceDateTime'] ?? null),
                'programProcessingStatus' => $resource['programProcessingStatus'] ?? null,
                'statusHistory' => $resource['statusHistory'] ?? [],
                'expirationDate' => $this->sqlDate($resource['expirationDate'] ?? null),
                'authoredOn' => $this->sqlDate($resource['authoredOn'] ?? null),
                'explanatoryLetter' => $resource['explanatoryLetter'] ?? null,
                'informWith' => data_get($resource, 'informWith.authMethodId'), 'detailsSyncedAt' => now(),
                'supportingInfo' => (new ServiceRequestMapper())->fromFhir($resource)['supporting_info'],
                'reasonReference' => (new ServiceRequestMapper())->fromFhir($resource)['reason_reference'],
            ];
            foreach (self::FHIR_REFERENCES as $field => [$relation, $attribute]) {
                $existing = $request->exists ? $request->getRelation($relation) : null;
                $attributes[$attribute] = !empty(data_get($resource, $field.'.identifier.value')) ? $this->syncIdentifier($request->exists ? $request : null, $resource[$field], $relation)->id : null;
            }
            foreach (['category' => ['category', 'categoryId'], 'performerType' => ['performerType', 'performerTypeId'], 'statusReason' => ['statusReasonConcept', 'statusReasonId']] as $field => [$relation, $attribute]) {
                $existing = $request->exists ? $request->getRelation($relation) : null;
                $attributes[$attribute] = isset($resource[$field]) ? $this->syncCodeableConcept($request->exists ? $request : null, $resource[$field], $relation)->id : null;
            }
            $attributes['intentId'] = isset($resource['intent']) ? Coding::firstOrCreate(['system' => self::REQUEST_INTENT_SYSTEM, 'code' => $resource['intent']])->id : null;
            $attributes['priorityId'] = isset($resource['priority']) ? CodeableConcept::firstOrCreate(['text' => $resource['priority']])->id : null;
            foreach (['quantity' => ['quantityQuantity', 'quantityId'], 'remainingQuantity' => ['remainingQuantity', 'remainingQuantityId']] as $field => [$relation, $attribute]) {
                $attributes[$attribute] = MedicalEventsRepository::quantity()->replaceComplete($request->exists ? $request->getRelation($relation) : null, $resource[$field] ?? null)?->id;
            }
            $request->forceFill($attributes)->save();
            MedicalEventsRepository::period()->sync($request, !empty($resource['occurrencePeriod']) ? $resource['occurrencePeriod'] + ['start' => null] : null, 'occurrencePeriod');
            foreach (self::FHIR_COLLECTIONS as $field => $relation) {
                $this->syncPivot($request, $relation, $this->syncIdentifiers($request, $resource[$field] ?? [], $relation));
            }
            $activity = $request->basedOnReferences()->whereHas('type.coding', fn ($query) => $query->whereIn('code', ['activity', 'care_plan_activity']))->first();
            $request->basedOnId = $activity?->id;
            $request->save();

            return $request;
        });
    }

    /** This entry point is for full responses fetched from ESOZ, never locally assembled drafts. */
    public function importResource(array $resource, int|Person|Preperson $personId, ?int $employeeId = null, ?int $divisionId = null): ServiceRequestRequest
    {
        return $this->storageTransaction(function () use ($resource, $personId, $employeeId, $divisionId): ServiceRequestRequest {
            $record = $this->storeResource($resource, $personId, $employeeId, $divisionId);
            $this->reconcileSubmissionFromResource($record, $resource);

            return $record;
        });
    }

    public function getResource(ServiceRequestRequest|int $request): array
    {
        $request = is_int($request) ? $this->model->newQuery()->findOrFail($request) : $request;
        if ($request->detailsSyncedAt === null) {
            throw new InvalidArgumentException('Service Request requires full import or explicit draft conversion before use.');
        }
        $request->load($this->fhirRelations());
        $resource = [];
        foreach (['id' => 'uuid', 'status' => 'clinicalStatus', 'requisition' => 'requestNumber', 'note' => 'note', 'patientInstruction' => 'patientInstruction', 'programProcessingStatus' => 'programProcessingStatus', 'explanatoryLetter' => 'explanatoryLetter'] as $field => $attribute) {
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
                $resource[$field] = MedicalEventsRepository::identifier()->toReference($request->getRelation($relation));
            }
        }
        // ESOZ represents an unused referral with empty display-only executor references.
        $resource['usedByEmployee'] ??= ['displayValue' => ''];
        $resource['usedByLegalEntity'] ??= ['displayValue' => ''];
        $resource['statusHistory'] = $request->statusHistory ?? [];
        if ($request->expirationDate !== null) {
            $resource['expirationDate'] = $request->expirationDate->toIso8601ZuluString();
        }
        foreach (['category' => 'category', 'performerType' => 'performerType', 'statusReason' => 'statusReasonConcept'] as $field => $relation) {
            if ($request->getRelation($relation) !== null) {
                $resource[$field] = MedicalEventsRepository::codeableConcept()->toResource($request->getRelation($relation));
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
            $resource['occurrencePeriod'] = array_filter(MedicalEventsRepository::period()->toResource($request->occurrencePeriod), static fn ($value) => $value !== null);
        }
        if ($request->occurrenceDateTime !== null) {
            $resource['occurrenceDateTime'] = CarbonImmutable::parse($request->getRawOriginal('occurrence_date_time'), 'UTC')->format('Y-m-d\TH:i:s.u\Z');
        }
        if ($request->informWith !== null) {
            $resource['informWith'] = ['authMethodId' => $request->informWith];
        }
        if ($request->authoredOn !== null) {
            $resource['authoredOn'] = CarbonImmutable::parse($request->authoredOn, 'UTC')->utc()->toIso8601ZuluString();
        }
        foreach (self::FHIR_COLLECTIONS as $field => $relation) {
            if (true) {
                $resource[$field] = $request->getRelation($relation)->map(fn ($item) => MedicalEventsRepository::identifier()->toReference($item))->all();
            }
        }

        return $resource;
    }

    public function backfillResources(int $personId, bool $write = false): array
    {
        Person::findOrFail($personId);
        $report = ['records' => 0, 'converted' => 0, 'needsRemoteSync' => [], 'errors' => []];
        $resources = [];
        $this->model->newQuery()->wherePersonId($personId)->with(['employee.legalEntity', 'category.coding', 'priority', 'intent', 'basedOn.type.coding', 'context.type.coding'])->chunkById(100, function ($records) use (&$report, &$resources): void {
            foreach ($records as $record) {
                $report['records']++;
                if ($record->detailsSyncedAt !== null) {
                    continue;
                }
                if (!in_array(strtolower($record->status), ['draft', 'new'], true) || filled($record->requestNumber)) {
                    $report['needsRemoteSync'][] = $record->id;
                    continue;
                }
                try {
                    $resources[$record->id] = $this->legacyDraftResource($record);
                } catch (Throwable $exception) {
                    $report['errors'][] = ['localId' => $record->id, 'reason' => $exception->getMessage()];
                }
            }
        });
        if (!$write || $report['errors'] !== [] || $report['needsRemoteSync'] !== []) {
            return $report;
        }
        $this->storageTransaction(function () use ($resources, $personId, &$report): void {
            foreach ($resources as $id => $resource) {
                $record = $this->model->newQuery()->whereKey($id)->wherePersonId($personId)->lockForUpdate()->firstOrFail();
                if ($record->detailsSyncedAt !== null) {
                    continue;
                }
                if (!in_array(strtolower($record->status), ['draft', 'new'], true) || filled($record->requestNumber)) {
                    throw new InvalidArgumentException('The draft changed after preflight; repeat reconciliation.');
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
            throw new InvalidArgumentException('Registered historical resources require a complete remote import.');
        }
        $record->loadMissing(['employee.legalEntity', 'category.coding', 'priority', 'intent', 'basedOn.type.coding', 'context.type.coding']);
        if ($record->employee === null || $record->employee->legalEntity === null) {
            throw new InvalidArgumentException('Historical author identity cannot be recovered.');
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
                throw new InvalidArgumentException('Historical context is untyped and cannot be matched to a patient encounter.');
            }
            $contextType = 'encounter';
        }
        $data['context_type'] = $contextType;
        $activity = $data['based_on_uuid'] ? CarePlanActivity::whereUuid($data['based_on_uuid'])->whereHas('carePlan', fn ($query) => $query->wherePersonId($record->personId))->first() : null;
        if ($data['based_on_uuid'] && $activity === null) {
            throw new InvalidArgumentException('The historical activity reference cannot be matched to this patient.');
        }
        $options = ['employee_uuid' => $record->employee->uuid, 'legal_entity_uuid' => $record->employee->legalEntity->uuid, 'carePlanUuid' => $activity?->carePlan?->uuid, 'timezone' => config('app.timezone')];
        if ($record->quantity !== null) {
            $data['quantity_system'] = $activity?->quantitySystem;
            $data['quantity_code'] = $activity?->quantityCode;
            if (empty($data['quantity_system']) || empty($data['quantity_code'])) {
                throw new InvalidArgumentException('Historical quantity units are missing; reconciliation is required.');
            }
        }
        if ($activity !== null) {
            $data['service_type'] = $activity->productReferenceIdentifier?->type?->first()?->coding?->first()?->code;
            if (empty($data['service_type'])) {
                throw new InvalidArgumentException('Historical service/service_group type cannot be recovered.');
            }
        }

        return (new ServiceRequestMapper())->toFhir($data, $options);
    }

    public function person(int $id): ?Person
    {
        return Person::find($id);
    }

    public function activityForDraft(int $id, int $carePlanId, int $personId): CarePlanActivity
    {
        return CarePlanActivity::whereKey($id)->whereCarePlanId($carePlanId)->whereHas('carePlan', fn ($query) => $query->wherePersonId($personId))->with('carePlan')->firstOrFail();
    }

    /** Automatically grant only the episodes/reports referenced as the referral's clinical reasons. */
    public function permittedResources(int|Person|Preperson $personId, array $references): array
    {
        $patient = is_int($personId) ? Person::findOrFail($personId) : $personId;
        $permitted = [];
        foreach ($references as $reference) {
            $uuid = $reference['uuid'] ?? data_get($reference, 'identifier.value');
            $type = $reference['type'] ?? data_get($reference, 'identifier.type.coding.0.code');
            if ($type === 'diagnostic_report') {
                $report = DiagnosticReport::forPatient($patient)->whereUuid($uuid)->firstOrFail();
                if (Auth::user()->cannot('view', [$report, $patient])) {
                    throw ValidationException::withMessages(['reason_reference' => __('referrals.messages.reason_unavailable')]);
                }
                $permitted[$uuid] = ['uuid' => $uuid, 'type' => 'diagnostic_report'];
                continue;
            }
            if (in_array($type, ['condition', 'observation'], true)) {
                $resource = $type === 'condition'
                    ? Condition::forPatient($patient)->whereUuid($uuid)->with('context')->firstOrFail()
                    : Observation::forPatient($patient)->whereUuid($uuid)->with('context')->firstOrFail();
                if (Auth::user()->cannot('view', [$resource, $patient])) {
                    throw ValidationException::withMessages(['reason_reference' => __('referrals.messages.reason_unavailable')]);
                }
                $context = $resource->context;
                $contextType = $context?->type?->first()?->coding?->first()?->code;
                $uuid = $context?->value;
                if ($contextType === 'encounter') {
                    $encounter = Encounter::forPatient($patient)->whereUuid($uuid)->with('episode')->firstOrFail();
                    if (Auth::user()->cannot('view', [$encounter, $patient])) {
                        throw ValidationException::withMessages(['reason_reference' => __('referrals.messages.reason_unavailable')]);
                    }
                    $uuid = $encounter->episode?->value;
                }
                $type = 'episode_of_care';
            }
            if (in_array($type, ['episode', 'episode_of_care'], true) && filled($uuid)) {
                $episode = Episode::forPatient($patient)->whereUuid($uuid)->firstOrFail();
                if (Auth::user()->cannot('view', [$episode, $patient])) {
                    throw ValidationException::withMessages(['reason_reference' => __('referrals.messages.reason_unavailable')]);
                }
                $permitted[$uuid] = ['uuid' => $uuid, 'type' => 'episode_of_care'];
            }
        }

        return array_values($permitted);
    }

    private function matchesReferralService(ServiceRequestRequest $referral, Encounter|Procedure|DiagnosticReport $resource): bool
    {
        if (blank($referral->programId)) {
            return true;
        }
        $requested = $referral->codeReference?->value ?? $referral->serviceId;
        $type = $referral->codeReference?->type?->first()?->coding?->first()?->code;
        $accepted = $type === 'service_group'
            ? ServiceSearch::memberServiceIds((string) $requested, dictionary()->services()->toArray())
            : [$requested];
        $actual = $resource instanceof Encounter
            ? $resource->actionReferences->pluck('value')->all()
            : [$resource->code?->value];

        return array_intersect($accepted, $actual) !== [];
    }

    public function executorChoices(string $category): array
    {
        return Auth::user()->employees()->whereLegalEntityId(legalEntity()->id)->with(['party', 'division', 'legalEntity'])->get()
            ->filter(static fn (Employee $employee): bool => Auth::user()->can('use', [ServiceRequestRequest::class, $employee, $category]))
            ->map(static fn (Employee $employee): array => ['id' => $employee->id, 'uuid' => $employee->uuid, 'label' => $employee->party?->fullName ?? $employee->uuid])
            ->values()->all();
    }

    public function executorForUse(int $id, string $category): Employee
    {
        $employee = Auth::user()->employees()->whereLegalEntityId(legalEntity()->id)->whereKey($id)->first();
        if ($employee === null || Auth::user()->cannot('use', [ServiceRequestRequest::class, $employee, $category])) {
            throw new InvalidArgumentException(__('referrals.messages.executor_unavailable'));
        }

        return $employee;
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
        if ($status === ServiceRequestStatus::COMPLETED->value && $record->programProcessingStatus !== null) {
            $attributes['programProcessingStatus'] = ServiceRequestProgramStatus::COMPLETED->value;
        }
        $record->forceFill($attributes)->save();
    }

    public function applyExecutionResult(ServiceRequestRequest $record, array $response, ?string $programId): void
    {
        $response = Arr::toCamelCase($response);
        $programProcessingStatus = $response['program_processing_status'] ?? ($response['programProcessingStatus'] ?? ServiceRequestProgramStatus::IN_PROGRESS->value);
        $record->forceFill(['status' => ServiceRequestStatus::IN_PROGRESS->value, 'programProcessingStatus' => $programProcessingStatus]);
        foreach (['usedByEmployee' => 'usedByEmployeeId', 'usedByLegalEntity' => 'usedByLegalEntityId'] as $field => $attribute) {
            if (!empty(data_get($response, $field.'.identifier.value'))) {
                $record->{$attribute} = $this->syncIdentifier($record, $response[$field], $field)->id;
            }
        }
        if ($programId !== null) {
            $reference = ['identifier' => ['type' => ['coding' => [['system' => 'eHealth/resources', 'code' => 'medical_program']]], 'value' => $programId]];
            $record->programIdentifierId = $this->syncIdentifier($record, $reference, 'programReference')->id;
            $record->programId = $programId;
        }
        if ($programId === null) {
            // Explicitly selecting programless execution removes the earlier draft program.
            $record->programId = null;
            $record->programIdentifierId = null;
        }
        $record->save();
    }

    public function cacheExecutionResource(array $remote, string $patientUuid, Employee $employee): ?ServiceRequestRequest
    {
        $person = Person::whereUuid($patientUuid)->first() ?? Preperson::whereUuid($patientUuid)->first();
        $resource = (new ServiceRequestMapper())->normalize($remote);
        // A summary is insufficient to materialize a new clinical document.
        if ($person === null || empty($resource['id']) || empty(data_get($resource, 'code.identifier.value'))) {
            return null;
        }

        return $this->importResource($resource, $person, null, null);
    }

    public function assertCompletionResourceOwned(string $referralUuid, string $resourceUuid, string $resourceType): Person|Preperson
    {
        $referral = $this->findByUuid($referralUuid);
        $personId = $referral?->personId;

        $resource = match ($resourceType) {
            'encounter' => Encounter::query()->where('uuid', $resourceUuid)->first(),
            'procedure' => Procedure::query()->where('uuid', $resourceUuid)->first(),
            'diagnostic_report' => DiagnosticReport::query()->where('uuid', $resourceUuid)->first(),
            default => null,
        };

        if ($resource === null) {
            throw new InvalidArgumentException(__('care-plan.referral_complete_emz_required'));
        }

        $resourcePersonId = $resource->getAttribute('person_id');
        if ($personId !== null && $resourcePersonId !== null && (int) $resourcePersonId !== (int) $personId) {
            throw new InvalidArgumentException(__('care-plan.referral_complete_emz_mismatch'));
        }
        $person = $resource->getAttribute('preperson_id') !== null ? Preperson::findOrFail($resource->getAttribute('preperson_id')) : Person::findOrFail($resourcePersonId);
        if ($referral !== null && !$this->model->newQuery()->whereKey($referral->id)->forPatient($person)->exists()) {
            throw new InvalidArgumentException(__('care-plan.referral_complete_emz_mismatch'));
        }
        if (!in_array($resourceUuid, array_column($this->completionResources($person, $referralUuid, $resourceType), 'uuid'), true)) {
            throw new InvalidArgumentException(__('care-plan.referral_complete_emz_not_linked'));
        }

        return $person;
    }

    public function activityContext(string $uuid, int $personId): array
    {
        $activity = CarePlanActivity::whereUuid($uuid)->with(['carePlan', 'productReferenceIdentifier.type.coding'])->first();
        if ($activity === null) {
            return [];
        }
        if ($activity->carePlan->personId !== $personId) {
            throw new InvalidArgumentException('The activity belongs to another patient.');
        }
        $type = $activity->productReferenceIdentifier?->type?->first()?->coding?->first()?->code;
        $system = $activity->quantitySystem;
        $code = $activity->quantityCode;
        $serviceId = $activity->productReference;
        $programId = $activity->program;
        if ($activity->detailsSyncedAt !== null) {
            $resource = Repository::carePlanActivity()->getResource($activity->id);
            $system = data_get($resource, 'detail.quantity.system');
            $code = data_get($resource, 'detail.quantity.code');
            $type = data_get($resource, 'detail.productReference.identifier.type.coding.0.code');
            $serviceId = data_get($resource, 'detail.productReference.identifier.value');
            $programId = data_get($resource, 'detail.program.identifier.value');
        }

        return array_filter(['care_plan_id' => $activity->carePlanId, 'care_plan_activity_id' => $activity->id, 'care_plan_uuid' => $activity->carePlan->uuid, 'service_id' => $serviceId, 'program_id' => $programId, 'service_type' => $type, 'quantity_system' => $system, 'quantity_code' => $code], static fn ($value) => $value !== null);
    }

    public function formData(ServiceRequestRequest $request): array
    {
        return (new ServiceRequestMapper())->fromFhir($this->getResource($request));
    }

    public function registeredViewRoute(ServiceRequestRequest $request): ?string
    {
        $patient = $this->patientForRequest($request);
        if (!$patient instanceof Person) {
            return null;
        }
        $activity = filled($request->basedOn?->value) ? CarePlanActivity::whereUuid($request->basedOn->value)
            ->whereHas('carePlan', fn ($query) => $query->wherePersonId($patient->id)->whereLegalEntityId(legalEntity()->id))
            ->with('carePlan')->first() : null;
        if ($activity !== null && Auth::user()->can('view', $activity->carePlan)) {
            return route('care-plans.activities.show', ['legalEntity' => legalEntity()->id, 'carePlan' => $activity->carePlanId, 'activity' => $activity->id]);
        }
        $encounter = filled($request->context?->value) ? Encounter::forPatient($patient)->whereUuid($request->context->value)->first() : null;
        if ($encounter !== null && Auth::user()->can('view', [$encounter, $patient])) {
            return route('persons.encounters.referrals.show', ['legalEntity' => legalEntity()->id, 'person' => $patient->id, 'encounter' => $encounter->id, 'referral' => $request->id]);
        }

        return null;
    }

    /** Re-resolve BA08 choices before draft persistence or signing; client IDs never grant access. */
    public function encounterActivityContext(Encounter $encounter, array $data, string $employeeUuid): array
    {
        $activityUuid = $data['based_on_uuid'] ?? null;
        $activity = filled($activityUuid) ? CarePlanActivity::whereUuid($activityUuid)->first() : null;
        $planId = (int) ($data['care_plan_id'] ?? $activity?->carePlanId);
        $activityId = (int) ($data['care_plan_activity_id'] ?? $activity?->id);
        if ($planId === 0 && $activityId === 0 && blank($activityUuid)) {
            return $data;
        }
        $personId = $encounter->getAttribute('person_id');
        $choice = $personId === null || $planId === 0 ? null : collect(Repository::carePlan()->documentActivityChoices(
            $planId,
            $personId,
            $encounter->uuid,
            Auth::user(),
            'service_request',
            $employeeUuid
        ))->firstWhere('id', $activityId);
        if ($choice === null || (filled($activityUuid) && $choice['uuid'] !== $activityUuid)
            || data_get($choice, 'productReference.identifier.value') !== ($data['service_id'] ?? null)
            || data_get($choice, 'productReference.identifier.type.coding.0.code') !== ($data['service_type'] ?? 'service')) {
            throw ValidationException::withMessages(['encounterReferralForm.care_plan_activity_id' => __('referrals.messages.activity_unavailable')]);
        }
        $remaining = $choice['remainingQuantity'];
        $quantity = (float) ($data['quantity'] ?? 0);
        if (!is_array($remaining) || !is_numeric($remaining['value'] ?? null) || $quantity <= 0 || $quantity > (float) $remaining['value']) {
            throw ValidationException::withMessages(['encounterReferralForm.quantity' => __('referrals.messages.activity_quantity_unavailable')]);
        }
        $context = $this->activityContext($choice['uuid'], $personId);

        return array_replace($data, $context, [
            'based_on_uuid' => $choice['uuid'],
            'program_id' => $context['program_id'] ?? null,
            'quantity_system' => $remaining['system'] ?? ($context['quantity_system'] ?? null),
            'quantity_code' => $remaining['code'] ?? ($context['quantity_code'] ?? null),
            'quantity_unit' => $remaining['unit'] ?? null,
        ]);
    }

    private function fhirRelations(): array
    {
        return ['statusReasonConcept.coding', 'intent', 'priority', 'category.coding', 'performerType.coding', 'quantityQuantity', 'remainingQuantity', 'occurrencePeriod', ...array_map(static fn (array $entry): string => $entry[0].'.type.coding', array_values(self::FHIR_REFERENCES)), ...array_map(static fn (string $name): string => $name.'.type.coding', array_values(self::FHIR_COLLECTIONS))];
    }

    private function sqlDate(?string $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value, 'UTC')->utc()->format('Y-m-d H:i:s.u');
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
            ->with(['basedOn', 'context', 'category.coding', 'priority', 'programReference'])
            ->withExists(['ehealthLinks as submission_pending' => static fn ($query) => $query->where('status', JobStatus::PENDING->value)])
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

    /** Delegate display projection while preserving repository callers and pending-submission checks. */
    public function toPatientRegistryRow(
        ServiceRequestRequest $request,
        array $carePlanIdsByActivityUuid = [],
        array $encounterIdsByUuid = []
    ): array {
        $pending = array_key_exists('submission_pending', $request->getAttributes())
            ? (bool) $request->submissionPending
            : $this->hasPendingSubmission($request);
        $this->registryMapper ??= new ServiceRequestRegistryMapper();

        return $this->registryMapper->map(
            $request,
            $carePlanIdsByActivityUuid,
            $encounterIdsByUuid,
            pending: $pending,
            canComplete: $this->canComplete($request)
        );
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

    public function findForPerson(int $id, int $personId): ServiceRequestRequest
    {
        return $this->model->newQuery()->whereKey($id)->wherePersonId($personId)->firstOrFail();
    }

    public function findForPatient(int $id, Person|Preperson $patient): ServiceRequestRequest
    {
        return $this->model->newQuery()->whereKey($id)->forPatient($patient)->firstOrFail();
    }

    public function findByUuid(string $uuid): ?ServiceRequestRequest
    {
        return $this->model->newQuery()->where('uuid', $uuid)->first();
    }

    public function incomingForEncounter(Encounter $encounter): ?ServiceRequestRequest
    {
        $patient = $this->patientForEncounter($encounter);
        if ($patient === null || $encounter->incomingReferral?->value === null) {
            return null;
        }

        return $this->model->newQuery()
            ->forPatient($patient)
            ->whereUuid($encounter->incomingReferral?->value)
            ->with(['category.coding', 'priority', 'requesterEmployee', 'employee.party', 'programReference'])
            ->first();
    }

    public function completionForPerson(string $uuid, int $personId): ServiceRequestRequest
    {
        return $this->model->newQuery()->whereUuid($uuid)->wherePersonId($personId)->firstOrFail();
    }

    public function canComplete(ServiceRequestRequest $record): bool
    {
        return Auth::user()->can('completeRecord', [ServiceRequestRequest::class, $record]);
    }

    public function latestCompletion(ServiceRequestRequest $record, string $requiredScope = 'service_request:complete'): ?EhealthLink
    {
        return $record->ehealthLinks()->with('job')
            ->whereHas('job', static fn ($query) => $query
                ->where('request_data->requiredScope', $requiredScope)
                ->where('request_data->legalEntityId', legalEntity()->id))
            ->latest('id')->first();
    }

    public function completionStates(array $uuids): array
    {
        return EhealthLink::query()->whereLinkableType(ServiceRequestRequest::class)
            ->whereHasMorph('linkable', [ServiceRequestRequest::class], static fn ($query) => $query->whereIn('uuid', $uuids))
            ->whereHas('job', static fn ($query) => $query
                ->where('request_data->requiredScope', 'service_request:complete')
                ->where('request_data->legalEntityId', legalEntity()->id))
            ->with('linkable')->latest('id')->get()->unique('linkable_id')
            ->mapWithKeys(static fn (EhealthLink $link): array => [$link->linkable->uuid => $link->status])->all();
    }

    public function requesterName(ServiceRequestRequest $record): string
    {
        $employee = $record->employee ?? Employee::whereUuid($record->requesterEmployee?->value)->with('party')->first();

        return $employee?->party?->fullName ?? '—';
    }

    public function importPatientResource(array $resource, Person $person): void
    {
        $resource = (new ServiceRequestMapper())->normalize($resource);
        $this->storageTransaction(function () use ($resource, $person): void {
            $existing = $this->model->newQuery()->whereUuid($resource['id'])->lockForUpdate()->first();
            if ($existing !== null && ($existing->personId !== $person->id || $this->hasPendingSubmission($existing))) {
                return;
            }
            $this->importResource($resource, $person->id, $existing?->employeeId, $existing?->divisionId);
        });
    }

    /** ESOZ SERVICE_REQUEST_<CATEGORY>_COMPLETED_WITH_CODES. */
    public function completionTypes(ServiceRequestRequest $record): array
    {
        $category = $record->category?->coding?->firstWhere('system', 'eHealth/SNOMED/service_request_categories')?->code;

        return match ($category) {
            'imaging', 'laboratory_procedure' => ['diagnostic_report'],
            'counselling', 'hospitalization', 'transfer_of_care' => ['encounter'],
            'diagnostic_procedure' => ['encounter', 'diagnostic_report'],
            'surgical_procedure' => ['encounter', 'procedure'],
            default => ['encounter', 'procedure', 'diagnostic_report'],
        };
    }

    public function completionResources(Person|Preperson $person, string $referralUuid, string $type): array
    {
        $referral = $this->findByUuid($referralUuid);
        if ($referral !== null && !in_array($type, $this->completionTypes($referral), true)) {
            return [];
        }
        $dictionaries = dictionary()->basics()
            ->getMultipleFormatted(['eHealth/encounter_types', 'eHealth/encounter_classes'], onlyActive: false)->toArray();
        $resources = match ($type) {
            'encounter' => Encounter::query()->forLegalEntity($person)
                ->whereStatus(EncounterStatus::FINISHED)
                ->whereHas('incomingReferral', static fn ($query) => $query->where('value', $referralUuid))
                ->with(['type.coding', 'class', 'period', 'performer', 'actionReferences'])->latest('id')->get()
                ->filter(static fn (Encounter $encounter): bool => $encounter->belongsToCurrentLegalEntity()
                    && Auth::user()->can('view', [$encounter, $person])),
            'procedure' => Procedure::query()->forPatient($person)->whereStatus('completed')
                ->whereHas('managingOrganization', static fn ($query) => $query->whereValue(legalEntity()->uuid))
                ->whereHas('basedOn', static fn ($query) => $query->where('value', $referralUuid))
                ->latest('id')->get()->filter(static fn (Procedure $procedure): bool => Auth::user()->can('view', [$procedure, $person])),
            'diagnostic_report' => DiagnosticReport::query()->forPatient($person)->whereStatus('final')
                ->whereHas('managingOrganization', static fn ($query) => $query->whereValue(legalEntity()->uuid))
                ->whereHas('basedOn', static fn ($query) => $query->where('value', $referralUuid))
                ->latest('id')->get()->filter(static fn (DiagnosticReport $report): bool => Auth::user()->can('view', [$report, $person])),
            default => collect(),
        };

        if ($referral !== null) {
            $resources = $resources->filter(fn ($resource): bool => $this->matchesReferralService($referral, $resource));
        }

        return $resources->map(static function ($resource) use ($type, $dictionaries): array {
            if ($resource instanceof Encounter) {
                $typeCode = $resource->type?->coding?->first()?->code;
                $classCode = $resource->class?->code;
                $name = $dictionaries['eHealth/encounter_types'][$typeCode]
                    ?? ($dictionaries['eHealth/encounter_classes'][$classCode] ?? __('encounters.label'));
                $date = $resource->period?->start;
            } else {
                $name = __('care-plan.emz_type.'.$type);
                $date = $resource->created_at?->format('d.m.Y H:i');
            }

            return ['uuid' => $resource->uuid, 'label' => implode(' · ', array_filter([$name, $date]))];
        })->values()->all();
    }

    /**
     * @param  array<string, mixed>  $referral
     */
    public function storeExternalIfMissing(array $referral, Employee $employee, int $personId): void
    {
        $resource = (new ServiceRequestMapper())->normalize($referral);
        if (empty($resource['id'])) {
            return;
        }
        $existing = $this->findByUuid($resource['id']);
        if ($existing !== null) {
            if ((int) $existing->personId !== $personId) {
                throw new InvalidArgumentException('A Service Request UUID already belongs to another patient.');
            }

            return;
        }
        $this->storeResource($resource, $personId, null, $employee->divisionId);
    }

    public function storeExternalManyIfMissing(array $referrals, int $personId): void
    {
        $this->storageTransaction(function () use ($referrals, $personId): void {
            foreach ($referrals as $item) {
                $this->storeExternalIfMissing($item['referral'], $item['employee'], $personId);
            }
        });
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
                $identifier = MedicalEventsRepository::identifier()->store($value);
                MedicalEventsRepository::codeableConcept()->attach($identifier, [
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
                $attributes['performer_type_id'] = MedicalEventsRepository::codeableConcept()->store([
                    'coding' => [['system' => 'SPECIALITY_TYPE', 'code' => $code]],
                ])->id;
            }
        }

        return $attributes;
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

    public function encounterOptions(int|Person|Preperson $personId, array $procedureCategories, array $diagnosticReportCategories): array
    {
        $services = dictionary()->services()->flattened();
        $patient = is_int($personId) ? Person::findOrFail($personId) : $personId;

        return $this->model->newQuery()
            ->forPatient($patient)
            ->whereIn('status', [ServiceRequestStatus::ACTIVE->value, ServiceRequestStatus::IN_PROGRESS->value])
            ->whereNotNull('request_number')
            ->where('request_number', '!=', '')
            ->with(['category.coding', 'priority', 'programReference', 'basedOn', 'context'])
            ->withExists(['ehealthLinks as submission_pending' => static fn ($query) => $query->where('status', JobStatus::PENDING->value)])
            ->orderByDesc('id')
            ->get()
            ->map(function (ServiceRequestRequest $referral) use ($services, $procedureCategories, $diagnosticReportCategories): array {
                $row = $this->toPatientRegistryRow($referral);
                $service = $services->firstWhere('id', $referral->serviceId);

                return [
                    'id' => $referral->uuid,
                    'requisition' => $row['requestNumber'],
                    'category' => $row['categoryLabel'] === '—' ? __('encounters.electronic_referral') : $row['categoryLabel'],
                    'serviceName' => $service['name'] ?? $row['itemName'],
                    'programName' => $row['programName'],
                    'priority' => $row['priorityLabel'],
                    'status' => $row['statusLabel'],
                    'period' => $row['periodLabel'],
                    'service' => $service,
                    'isProcedureAllowed' => $service !== null && in_array($service['category'] ?? null, $procedureCategories, true),
                    'isDiagnosticReportAllowed' => $service !== null && in_array($service['category'] ?? null, $diagnosticReportCategories, true),
                ];
            })->all();
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

    /** Resolve saved referral references for display without substituting UUIDs for missing requisitions. */
    public function requestNumbersByUuids(array $uuids): array
    {
        return $this->model->newQuery()->whereIn('uuid', $uuids)
            ->pluck('request_number', 'uuid')->all();
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

    public function hasPendingSubmission(ServiceRequestRequest $record): bool
    {
        return $record->ehealthLinks()->where('status', JobStatus::PENDING->value)->exists();
    }

    public function beginSubmission(ServiceRequestRequest $record, array $changes, string $requiredScope = 'service_request:write', array $operationContext = []): EhealthLink
    {
        return $this->storageTransaction(function () use ($record, $changes, $requiredScope, $operationContext) {
            $record = ServiceRequestRequest::whereKey($record->id)->lockForUpdate()->firstOrFail();
            if ($this->hasPendingSubmission($record)) {
                throw ValidationException::withMessages(['submission' => __('care-plan.submission_pending')]);
            }

            return RemoteEHealthLinksProcessing::startSubmission($record, [
                'resource' => $this->getResource($record), 'changes' => $changes,
                'requiredScope' => $requiredScope, 'operationContext' => $operationContext, 'legalEntityId' => legalEntity()->id,
            ]);
        });
    }

    public function finishSubmission(EhealthLink $link, array $jobData): bool
    {
        return $this->storageTransaction(function () use ($link, $jobData): bool {
            $link = EhealthLink::whereKey($link->id)->lockForUpdate()->firstOrFail();
            if ($link->status !== JobStatus::PENDING->value) {
                return false;
            }
            $processed = strtolower((string) ($jobData['status'] ?? '')) === 'processed';
            if ($processed) {
                $record = $link->linkable;
                $data = $jobData['response_data'] ?? ($jobData['result'] ?? ($jobData['data'] ?? []));
                $data = ServiceRequestApi::validateSubmissionResult($data);
                $data = (new ServiceRequestMapper())->normalize($data);
                if (isset($data['id']) && $data['id'] !== $record->uuid) {
                    throw new InvalidArgumentException('The processed Service Request does not match the submitted record.');
                }
                if (isset($data['status']) && in_array(strtolower($data['status']), ['processed', 'pending', 'processing', 'success', 'failed', 'accepted', 'queued'], true)) {
                    unset($data['status']);
                    $data = array_diff_key($data, array_flip(['links', 'eta', 'jobId', 'statusCode', 'detailSyncPending', 'carePlanActivityResource']));
                }
                $submitted = $link->job->request_data;
                $resource = array_replace($submitted['resource'], $submitted['changes'], $data);
                $patient = $this->patientForRequest($record);
                if ($patient === null) {
                    throw new InvalidArgumentException('The submitted Service Request has no patient context.');
                }
                $record = $this->storeResource($resource, $patient, $record->employeeId, $record->divisionId);
                $context = $submitted['operationContext'] ?? [];
                if (($context['execution'] ?? false) && ($resource['status'] ?? null) === 'active' && ($resource['programProcessingStatus'] ?? null) !== 'completed') {
                    $this->applyExecutionResult($record, $resource, $context['programId'] ?? null);
                }
                if (isset($jobData['carePlanActivityResource'])) {
                    $activity = $this->relatedCarePlanActivity($record, $link->job->request_data['legalEntityId']);
                    if ($activity !== null) {
                        Repository::carePlanActivity()->storeResource($jobData['carePlanActivityResource'], $activity->carePlan, $activity->id);
                    }
                }
                if (isset($context['localStatus'])) {
                    $record->forceFill(['status' => $context['localStatus']])->save();
                }
            }
            $status = $processed ? JobStatus::PROCESSED->value : JobStatus::FAILED->value;
            $link->job()->update(['status' => $status, 'response_data' => $jobData]);
            $link->update([
                'status' => $status,
                'error' => $processed ? null : (is_array($jobData['error'] ?? null) ? $jobData['error'] : ['message' => $jobData['error'] ?? __('errors.ehealth.messages.request_error')]),
                'error_code' => $processed ? null : ($jobData['status_code'] ?? null),
            ]);

            return true;
        });
    }

    public function relatedCarePlanActivity(ServiceRequestRequest $record, int $legalEntityId): ?CarePlanActivity
    {
        $uuids = $record->basedOnReferences()->whereHas('type.coding', static fn ($query) => $query->whereIn('code', ['activity', 'care_plan_activity']))->pluck('value');

        return CarePlanActivity::whereIn('uuid', $uuids)
            ->whereHas('carePlan', static fn ($query) => $query->wherePersonId($record->personId)->whereLegalEntityId($legalEntityId))
            ->with('carePlan.person')->first();
    }
}
