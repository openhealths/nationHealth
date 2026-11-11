<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Classes\eHealth\SchemaResponseContract;
use App\Enums\Person\EncounterStatus;
use App\Enums\Person\MedicationRequestStatus;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\Division;
use App\Models\Employee\Employee;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Coding;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest;
use App\Models\Person\Person;
use App\Repositories\MedicalEvents\Concerns\ResolvesRequestFhirRefs;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use App\Repositories\Repository;
use App\Services\MedicalEvents\ActivityRemainingQuantityGuard;
use App\Services\MedicalEvents\InformWith;
use App\Services\MedicalEvents\Mappers\MedicationRequestMapper;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * @property MedicationRequestRequest $model
 */
class MedicationRequestRepository extends BaseRepository
{
    use ResolvesRequestFhirRefs;

    public function __construct(MedicationRequestRequest $model)
    {
        parent::__construct($model);
    }

    /**
     * Create medication request request in DB for patient with related dosage instructions.
     *
     * @param  array  $data
     * @param  int  $personId
     * @return int
     * @throws Throwable
     */
    private const REFERENCE_FIELDS = ['subject', 'requester', 'division', 'medicationReference', 'program', 'context', 'priorPrescription', 'basedOn', 'managingOrganization', 'blockedByLegalEntity'];

    public function store(array $data, int $personId): int
    {
        $existing = $this->findByUuid($data['uuid'] ?? $data['id']);
        if ($existing !== null && (int) $existing->personId !== $personId) {
            throw new InvalidArgumentException('The medication record belongs to another patient.');
        }
        $person = Person::findOrFail($personId);
        $employee = isset($data['employee_id']) ? Employee::findOrFail($data['employee_id']) : null;
        $division = isset($data['division_id']) ? Division::findOrFail($data['division_id']) : null;
        $original = $existing?->detailsSyncedAt !== null ? $this->getResource($existing) : [];
        $options = [
            'resource' => $original,
            'person_uuid' => $person->uuid,
            'employee_uuid' => $employee?->uuid,
            'division_uuid' => $division?->uuid,
        ];
        if (array_key_exists('care_plan_uuid', $data)) {
            $options['carePlanUuid'] = $data['care_plan_uuid'];
        }
        $resource = (new MedicationRequestMapper())->toFhir($data, $options);

        return $this->storeResource(
            $resource,
            $personId,
            $data['employee_id'] ?? null,
            $data['division_id'] ?? null,
            $data['source'] ?? MedicationRequestRequest::SOURCE_LOCAL,
            MedicationRequestRequest::TYPE_REQUEST,
            $data['ehealth_payload'] ?? null
        )->id;
    }

    public function assertFhirStorageReady(bool $beforeSubmission = false): void
    {
        $this->assertStorageSchema([
            'medication_request_requests' => ['details_synced_at', 'signed_prescription_pending', 'authored_on', 'quantity_id', 'container_quantity_id', 'dispense_valid_from', 'dispense_valid_to', 'rejected_at', 'rejected_by_uuid', 'blocked_to', 'reject_reason', 'reject_reason_code', 'block_reason', 'block_reason_code'],
            'medication_request_references' => ['medication_request_id', 'identifier_id', 'field', 'position'],
            'dosage_instructions' => ['position', 'additional_instruction', 'as_needed_codeable_concept', 'site'],
            'periods' => ['start_precision', 'end_precision', 'start_timezone', 'end_timezone'],
        ], $beforeSubmission ? 'clinical-storage.submission_update_required' : 'clinical-storage.update_required');
    }

    /** Store a complete profile. List summaries must use upsertFromEHealth instead. */
    public function storeResource(
        array $resource,
        int $personId,
        ?int $employeeId = null,
        ?int $divisionId = null,
        string $source = MedicationRequestRequest::SOURCE_EHEALTH,
        string $resourceType = MedicationRequestRequest::TYPE_REQUEST,
        ?array $native = null
    ): MedicationRequestRequest {
        $this->assertResource($resource, $personId, $resourceType);

        return $this->storageTransaction(function () use ($resource, $personId, $employeeId, $divisionId, $source, $resourceType, $native): MedicationRequestRequest {
            $request = $this->model->newQuery()->whereUuid($resource['id'])->lockForUpdate()->first();
            if ($request !== null) {
                if ((int) $request->personId !== $personId) {
                    throw new InvalidArgumentException('Medication identity or resource type does not match.');
                }
                if ($request->resourceType !== $resourceType) {
                    // Historical list caches classified every external record as a signed prescription.
                    // Only a complete authoritative MRR detail may correct that unsynchronized projection.
                    $legacyRequestImport = $request->source === MedicationRequestRequest::SOURCE_EHEALTH
                        && $request->detailsSyncedAt === null
                        && $request->resourceType === MedicationRequestRequest::TYPE_PRESCRIPTION
                        && $resourceType === MedicationRequestRequest::TYPE_REQUEST
                        && $source === MedicationRequestRequest::SOURCE_EHEALTH
                        && $native !== null;
                    if (!$legacyRequestImport) {
                        throw new InvalidArgumentException('Medication identity or resource type does not match.');
                    }
                    SchemaResponseContract::validateOperationData($native, 'request_detail', 'medication');
                    if ($native['id'] !== $resource['id'] || $native['person']['id'] !== $this->personUuid($personId)) {
                        throw new InvalidArgumentException('Medication identity or resource type does not match.');
                    }
                }
            }
            $canonical = $request?->detailsSyncedAt !== null;
            $request ??= $this->model->newInstance();
            $existingReferences = $canonical ? $request->references()->with('type.coding')->get() : collect();
            $referenceIds = [];
            $activityId = null;
            $contextId = null;
            foreach (self::REFERENCE_FIELDS as $field) {
                $values = $field === 'basedOn' ? ($resource[$field] ?? []) : (isset($resource[$field]) ? [$resource[$field]] : []);
                $existing = $existingReferences->filter(fn ($reference) => $reference->pivot->field === $field)->values();
                foreach (array_values($values) as $position => $value) {
                    $reference = MedicalEventsRepository::identifier()->update($existing->get($position) ?? MedicalEventsRepository::identifier()->store($value['identifier']['value']), $value);
                    $referenceIds[$reference->id] = ['field' => $field, 'position' => $position];
                    if ($field === 'context') {
                        $contextId = $reference->id;
                    }
                    if ($field === 'basedOn' && (new MedicationRequestMapper())->referenceType($value) === 'activity') {
                        $activityId = $reference->id;
                    }
                }
            }
            $category = isset($resource['category'])
                ? $this->syncCodeableConcept($canonical ? $request : null, $resource['category'], 'category') : null;
            $request->forceFill([
                'uuid' => $resource['id'], 'personId' => $personId,
                'employeeId' => $employeeId ?? $request->employeeId, 'divisionId' => $divisionId ?? $request->divisionId,
                'status' => $resource['status'], 'requestNumber' => $resource['requestNumber'] ?? null,
                'medicationId' => data_get($resource, 'medicationReference.identifier.value'),
                'medicationQty' => data_get($resource, 'quantity.value'),
                'medicationProgramId' => data_get($resource, 'program.identifier.value'),
                'startedAt' => data_get($resource, 'period.start'), 'endedAt' => data_get($resource, 'period.end'),
                'intentId' => isset($resource['intent']) ? Coding::firstOrCreate(['system' => self::REQUEST_INTENT_SYSTEM, 'code' => $resource['intent']])->id : null,
                'priorityId' => isset($resource['priority']) ? CodeableConcept::firstOrCreate(['text' => $resource['priority']])->id : null,
                'categoryId' => $category?->id, 'basedOnId' => $activityId, 'contextId' => $contextId,
                'note' => $resource['note'] ?? null,
                'containerDosage' => is_string($resource['containerDosage'] ?? null) ? $resource['containerDosage'] : null,
                'informWith' => InformWith::authMethodId($resource['informWith'] ?? null),
                'source' => $request->exists ? $request->source : $source, 'resourceType' => $resourceType,
                'detailsSyncedAt' => now(),
                'authoredOn' => $resource['createdAt'] ?? null,
                'dispenseValidFrom' => $resource['dispenseValidFrom'] ?? null,
                'dispenseValidTo' => $resource['dispenseValidTo'] ?? null,
                'rejectedAt' => $resource['rejectedAt'] ?? null,
                'rejectedByUuid' => $resource['rejectedBy'] ?? null,
                'blockedTo' => $resource['blockedTo'] ?? null,
                'rejectReason' => $resource['rejectReason'] ?? null,
                'rejectReasonCode' => $resource['rejectReasonCode'] ?? null,
                'blockReason' => $resource['blockReason'] ?? null,
                'blockReasonCode' => $resource['blockReasonCode'] ?? null,
                'quantityId' => MedicalEventsRepository::quantity()->replaceComplete($canonical ? $request->quantityQuantity : null, $resource['quantity'] ?? null)?->id,
                'containerQuantityId' => MedicalEventsRepository::quantity()->replaceComplete($canonical ? $request->containerQuantity : null, is_array($resource['containerDosage'] ?? null) ? $resource['containerDosage'] : null)?->id,
            ]);
            if ($native !== null) {
                $request->ehealthPayload = $native;
            }
            $request->save();
            MedicalEventsRepository::period()->sync($request, $resource['period'] ?? null, preservePrecision: true);
            $request->references()->sync($referenceIds);
            foreach ($existingReferences as $reference) {
                if (!array_key_exists($reference->id, $referenceIds)) {
                    foreach ($reference->type as $concept) {
                        MedicalEventsRepository::codeableConcept()->delete($concept);
                    }
                    $reference->delete();
                }
            }
            $existingDosages = $request->dosageInstructions()->orderBy('position')->orderBy('id')->get();
            $kept = [];
            foreach (array_values($resource['dosageInstruction'] ?? []) as $position => $dosage) {
                $row = $existingDosages->get($position) ?? $request->dosageInstructions()->make();
                $attributes = ['position' => $position];
                foreach (['sequence', 'text', 'patientInstruction', 'additionalInstruction', 'timing', 'asNeededBoolean', 'asNeededCodeableConcept', 'site', 'route', 'method', 'doseAndRate', 'maxDosePerAdministration', 'maxDosePerPeriod', 'maxDosePerLifetime'] as $field) {
                    $attributes[Str::snake($field)] = $dosage[$field] ?? ($field === 'asNeededBoolean' ? false : null);
                }
                $row->fill($attributes)->save();
                $kept[] = $row->id;
            }
            $request->dosageInstructions()->whereNotIn('id', $kept)->delete();

            return $request->unsetRelations();
        });
    }

    public function getResource(MedicationRequestRequest|int $request): array
    {
        $request = is_int($request) ? $this->model->newQuery()->findOrFail($request) : $request;
        if ($request->detailsSyncedAt === null) {
            throw new InvalidArgumentException('Medication record requires full detail import before clinical use.');
        }
        $request->load(['references.type.coding', 'dosageInstructions', 'intent', 'category.coding', 'priority', 'quantityQuantity', 'containerQuantity', 'period']);
        $resource = ['id' => $request->uuid, 'status' => $request->status];
        foreach (['requestNumber' => $request->requestNumber, 'intent' => $request->intent?->code, 'priority' => $request->priority?->text, 'note' => $request->note] as $field => $value) {
            if ($value !== null) {
                $resource[$field] = $value;
            }
        }
        if ($request->rejectedByUuid !== null) {
            $resource['rejectedBy'] = $request->rejectedByUuid;
        }
        if ($request->authoredOn !== null) {
            $resource['createdAt'] = $request->authoredOn;
        }
        foreach (['dispenseValidFrom', 'dispenseValidTo', 'rejectedAt', 'blockedTo', 'rejectReason', 'rejectReasonCode', 'blockReason', 'blockReasonCode'] as $field) {
            if ($request->{$field} !== null) {
                $resource[$field] = in_array($field, ['rejectedAt', 'blockedTo'], true)
                    ? CarbonImmutable::parse($request->{$field}, 'UTC')->utc()->toIso8601ZuluString()
                    : $request->{$field};
            }
        }
        if ($request->informWith !== null) {
            $resource['informWith'] = ['authMethodId' => $request->informWith];
        }
        if ($request->category !== null) {
            $resource['category'] = MedicalEventsRepository::codeableConcept()->toResource($request->category);
        }
        if ($request->quantityQuantity !== null) {
            $resource['quantity'] = MedicalEventsRepository::quantity()->toResource($request->quantityQuantity);
        }
        if ($request->containerQuantity !== null) {
            $resource['containerDosage'] = MedicalEventsRepository::quantity()->toResource($request->containerQuantity);
        } elseif ($request->containerDosage !== null) {
            $resource['containerDosage'] = $request->containerDosage;
        }
        if ($request->period !== null) {
            $resource['period'] = array_filter(MedicalEventsRepository::period()->toResource($request->period, preservePrecision: true), static fn ($value) => $value !== null);
        }
        $resource['basedOn'] = [];
        foreach ($request->references as $reference) {
            $value = MedicalEventsRepository::identifier()->toReference($reference);
            $field = $reference->pivot->field;
            if ($field === 'basedOn') {
                $resource[$field][] = $value;
            } else {
                $resource[$field] = $value;
            }
        }
        $resource['dosageInstruction'] = $request->dosageInstructions->sortBy('position')->map(static function ($row): array {
            $dosage = [];
            foreach (['sequence', 'text', 'patientInstruction', 'additionalInstruction', 'timing', 'asNeededBoolean', 'asNeededCodeableConcept', 'site', 'route', 'method', 'doseAndRate', 'maxDosePerAdministration', 'maxDosePerPeriod', 'maxDosePerLifetime'] as $field) {
                if ($row->{$field} !== null && !($field === 'asNeededBoolean' && $row->asNeededCodeableConcept !== null)) {
                    $dosage[$field] = $row->{$field};
                }
            }

            return $dosage;
        })->values()->all();

        return $resource;
    }

    public function importDetails(array $native, int $personId, string $resourceType): MedicationRequestRequest
    {
        if (!isset($native['person']['id']) && !isset($native['person_id'])) {
            throw new InvalidArgumentException('A list summary cannot be imported as complete medication details.');
        }
        if (!isset($native['dosage_instruction']) || (!isset($native['medication_id']) && !isset($native['medication_info']['medication_id']))) {
            throw new InvalidArgumentException('Complete medication details require medication and dosage_instruction.');
        }

        return $this->storeResource(
            (new MedicationRequestMapper())->fromEHealth($native),
            $personId,
            source: MedicationRequestRequest::SOURCE_EHEALTH,
            resourceType: $resourceType,
            native: $native
        );
    }

    /** Read-only preflight. Registered records are reimported, never guessed from legacy projections. */
    public function preflight(int $personId): array
    {
        Person::findOrFail($personId);

        return $this->model->newQuery()->wherePersonId($personId)->orderBy('id')->get(['id', 'uuid', 'resource_type', 'details_synced_at'])
            ->map(fn ($record) => ['localId' => $record->id, 'uuid' => $record->uuid, 'resourceType' => $record->resourceType,
                'action' => $record->detailsSyncedAt !== null ? 'ready' : 'importFullDetails'])->all();
    }

    private function assertResource(array $resource, int $personId, string $resourceType): void
    {
        if (!Str::isUuid($resource['id'] ?? '') || empty($resource['status'])) {
            throw new InvalidArgumentException('A complete medication resource requires id and status.');
        }
        if (!in_array($resourceType, [MedicationRequestRequest::TYPE_REQUEST, MedicationRequestRequest::TYPE_PRESCRIPTION], true)) {
            throw new InvalidArgumentException('Unknown medication resource type.');
        }
        $person = Person::findOrFail($personId);
        $subject = data_get($resource, 'subject.identifier.value');
        if ($subject !== null && $subject !== $person->uuid) {
            throw new InvalidArgumentException('Medication subject does not match its patient context.');
        }
        if (isset($resource['quantity']['value'])) {
            $value = $resource['quantity']['value'];
            if (!is_numeric($value) || !is_finite((float) $value)) {
                throw new InvalidArgumentException('Medication quantity must be finite.');
            }
            $numeric = is_float($value) ? json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) : (string) $value;
            $quantity = BigDecimal::of($numeric);
            if (!$quantity->isEqualTo(json_encode((float) $value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR))) {
                throw new InvalidArgumentException('Medication quantity cannot round-trip through the JSON numeric contract.');
            }
            if (!$quantity->isEqualTo($quantity->toScale(10, RoundingMode::DOWN)) || $quantity->abs()->isGreaterThanOrEqualTo('100000000000000')) {
                throw new InvalidArgumentException('Medication quantity exceeds decimal(24,10) storage precision.');
            }
        }
    }

    /**
     * Get medication request requests data that is related to the person.
     *
     * @param  int  $personId
     * @return array
     */
    public function getByPersonId(int $personId): array
    {
        return $this->model
            ->with(['dosageInstructions.doseRate'])
            ->where('person_id', $personId)
            ->get()
            ->toArray();
    }

    /**
     * Patient-scoped MRR search with TV 3.9.4.1 basic filters (status + period).
     *
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
            ->with(['dosageInstructions.doseRate', 'basedOn', 'context', 'category.coding'])
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

        $source = $filters['source'] ?? null;
        if ($source !== null) {
            $query->where('source', $source);
        }

        if (isset($filters['resource_type'])) {
            $query->where('resource_type', $filters['resource_type']);
        }
        if (!empty($filters['request_number'])) {
            $query->where('request_number', 'like', '%'.$filters['request_number'].'%');
        }

        foreach (['medication_id', 'medical_program_id' => 'medication_program_id'] as $key => $column) {
            $filter = is_int($key) ? $column : $key;
            if (!empty($filters[$filter])) {
                $query->where($column, $filters[$filter]);
            }
        }
        foreach (['legal_entity_id' => 'legal_entity->id', 'employee_id' => 'employee->id', 'episode_id' => 'episode_id'] as $filter => $path) {
            if (!empty($filters[$filter])) {
                $query->where('ehealth_payload->'.$path, $filters[$filter]);
            }
        }
        if (!empty($filters['encounter_id'])) {
            $query->whereHas('context', fn (Builder $context) => $context->whereValue($filters['encounter_id']));
        }
        if (!empty($filters['care_plan_id'])) {
            $query->whereHas('references', fn (Builder $reference) => $reference->where('medication_request_references.field', 'basedOn')->whereValue($filters['care_plan_id']));
        }
        foreach (['created_from' => ['authored_on', '>='], 'created_to' => ['authored_on', '<='],
            'dispense_valid_from_start' => ['dispense_valid_from', '>='], 'dispense_valid_from_end' => ['dispense_valid_from', '<='],
            'dispense_valid_to_start' => ['dispense_valid_to', '>='], 'dispense_valid_to_end' => ['dispense_valid_to', '<=']] as $filter => [$column, $operator]) {
            if (!empty($filters[$filter])) {
                $query->whereDate($column, $operator, $filters[$filter]);
            }
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
            ->map(fn (MedicationRequestRequest $request): array => $this->toPatientRegistryRow(
                $request,
                $carePlanIdsByActivityUuid,
                $encounterIdsByUuid
            ))
            ->all();
    }

    /**
     * Flatten a local MRR into UI-ready fields for the patient eRx registry.
     *
     * @return array{
     *     id: int|null,
     *     uuid: string,
     *     requestNumber: string,
     *     status: string,
     *     statusLabel: string,
     *     statusBadge: string,
     *     medicationName: string,
     *     medicationQty: string,
     *     startedAt: string|null,
     *     endedAt: string|null,
     *     periodLabel: string,
     *     programName: string,
     *     categoryLabel: string,
     *     basisLabel: string,
     *     encounterId: int|null,
     *     activityId: int|null,
     *     carePlanId: int|null
     * }
     */
    public function toPatientRegistryRow(MedicationRequestRequest $request, array $carePlanIdsByActivityUuid = [], array $encounterIdsByUuid = []): array
    {
        $payload = is_array($request->ehealthPayload) ? $request->ehealthPayload : [];
        $status = strtolower((string) $request->status);
        $startedAt = $request->startedAt;
        $endedAt = $request->endedAt;
        $qty = $request->medicationQty;
        $qtyLabel = $qty !== null && $qty !== ''
            ? rtrim(rtrim(number_format((float) $qty, 10, '.', ''), '0'), '.')
            : '';

        $medicationName = (string) (
            data_get($payload, 'medication_info.medication_name')
            ?: data_get($payload, 'medication_name')
            ?: data_get($payload, 'medication.name')
            ?: ''
        );
        if ($medicationName === '' || preg_match('/^[0-9a-f-]{36}$/i', $medicationName) === 1) {
            $medicationName = 'Лікарський засіб';
        }

        $programName = (string) (
            data_get($payload, 'medical_program.name')
            ?: data_get($payload, 'medical_program_name')
            ?: ''
        );

        $categoryValue = $request->category?->text ?: $request->category?->coding->first()?->code ?: data_get($payload, 'category') ?: '';
        $category = strtolower((string) $categoryValue);
        $categoryLabel = match ($category) {
            'community' => 'Амбулаторно',
            'inpatient' => 'Стаціонар',
            default => $category !== '' ? $category : '—',
        };

        $activityUuid = $request->basedOn?->value;
        $encounterUuid = $request->context?->value;

        $activityData = $activityUuid ? ($carePlanIdsByActivityUuid[$activityUuid] ?? null) : null;
        $activityId = $activityData ? $activityData->id : null;
        $carePlanId = $activityData ? $activityData->carePlanId : null;

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

        return [
            'id' => $request->id,
            'uuid' => (string) $request->uuid,
            'requestNumber' => (string) ($request->requestNumber ?: $request->uuid),
            'status' => (string) $request->status,
            'statusLabel' => $this->statusLabel($status),
            'statusBadge' => $this->statusBadge($status),
            'medicationName' => $medicationName,
            'medicationQty' => $qtyLabel !== '' ? $qtyLabel : '—',
            'startedAt' => $startedAt?->toDateString(),
            'endedAt' => $endedAt?->toDateString(),
            'periodLabel' => $periodLabel,
            'programName' => $programName !== '' ? $programName : '—',
            'categoryLabel' => $categoryLabel,
            'basisLabel' => $basisLabel,
            'encounterId' => $encounterId,
            'activityId' => $activityId,
            'carePlanId' => $carePlanId,
        ];
    }

    private function statusLabel(string $status): string
    {
        return MedicationRequestStatus::labelFor($status);
    }

    private function statusBadge(string $status): string
    {
        return MedicationRequestStatus::colorFor($status);
    }

    public function findByUuid(string $uuid): ?MedicationRequestRequest
    {
        return $this->model->newQuery()->where('uuid', $uuid)->first();
    }

    public function personUuid(int $personId): string
    {
        return (string) Person::findOrFail($personId)->uuid;
    }

    public function divisionUuid(?int $divisionId): ?string
    {
        return $divisionId === null ? null : Division::findOrFail($divisionId)->uuid;
    }

    public function findActivity(string $uuid, int $personId, ?int $carePlanId = null, ?int $legalEntityId = null): ?CarePlanActivity
    {
        return CarePlanActivity::whereUuid($uuid)
            ->when($carePlanId !== null, fn ($query) => $query->whereCarePlanId($carePlanId))
            ->whereHas('carePlan', function ($query) use ($personId, $legalEntityId): void {
                $query->wherePersonId($personId);
                if ($legalEntityId !== null) {
                    $query->whereLegalEntityId($legalEntityId);
                }
            })->first();
    }

    public function assertActivityBasis(CarePlan $plan, CarePlanActivity $activity, array $formData): void
    {
        if ((int) $activity->carePlanId !== (int) $plan->id) {
            throw new InvalidArgumentException('The medication activity belongs to another Care Plan.');
        }
        $repository = Repository::carePlanActivity();
        if ($activity->detailsSyncedAt !== null && method_exists($repository, 'getResource')) {
            $detail = $repository->getResource($activity)['detail'];
            $kind = $detail['kind'] ?? null;
            $product = data_get($detail, 'productReference.identifier.value');
            $program = data_get($detail, 'program.identifier.value');
        } else {
            $kind = $activity->resolvedKind();
            $product = $activity->productReference;
            $program = $activity->program;
        }
        if ($kind !== 'medication_request' || ($product !== null && $product !== ($formData['medication_id'] ?? null))
            || (!empty($formData['program_id']) && $program !== null && $program !== $formData['program_id'])) {
            throw new InvalidArgumentException('The prescription must match the medication and program of its Activity.');
        }
    }

    public function findAuthor(int|string $id): ?Employee
    {
        return Employee::where(is_numeric($id) ? 'id' : 'uuid', $id)->first();
    }

    public function updateRecord(MedicationRequestRequest $request, array $attributes): void
    {
        $request->fill($attributes)->save();
    }

    /** Persist ESOZ acknowledgement independently of the subsequent detail import. */
    public function recordSigned(MedicationRequestRequest $request, array $prescription): void
    {
        if (!Str::isUuid($prescription['id'] ?? '') || $prescription['id'] === $request->uuid) {
            throw new InvalidArgumentException('A signed prescription must have its own MR identity.');
        }
        $this->storageTransaction(function () use ($request, $prescription): void {
            $locked = $this->model->newQuery()->whereKey($request->id)->wherePersonId($request->personId)->lockForUpdate()->firstOrFail();
            $payload = $locked->ehealthPayload ?? [];
            if (isset($payload['active_id']) && $payload['active_id'] !== $prescription['id']) {
                throw new InvalidArgumentException('The signed request is already linked to another prescription.');
            }
            $payload['active_id'] = $prescription['id'];
            $locked->forceFill(['status' => MedicationRequestStatus::SIGNED->value, 'ehealthPayload' => $payload, 'signedPrescriptionPending' => true])->save();
        });
        $request->refresh();
    }

    /** A GET-only recovery path; importing details must never submit another signature. */
    public function completeSignedPrescription(MedicationRequestRequest $request, array $detail): void
    {
        $this->storageTransaction(function () use ($request, $detail): void {
            $locked = $this->model->newQuery()->whereKey($request->id)->wherePersonId($request->personId)->lockForUpdate()->firstOrFail();
            $activeId = data_get($locked->ehealthPayload, 'active_id');
            $personUuid = $this->personUuid((int) $locked->personId);
            if (!$activeId || ($detail['id'] ?? null) !== $activeId
                || data_get($detail, 'person.id', $detail['person_id'] ?? null) !== $personUuid
                || (isset($detail['person_id']) && $detail['person_id'] !== $personUuid)) {
                throw new InvalidArgumentException('Prescription details do not match the confirmed prescription and patient.');
            }
            if (!in_array(strtolower((string) ($detail['status'] ?? '')), ['active', 'blocked', 'completed', 'rejected', 'expired', 'entered-in-error'], true)) {
                throw new InvalidArgumentException('The detail endpoint did not return a prescription status.');
            }
            $existing = $this->model->newQuery()->whereUuid($activeId)->lockForUpdate()->first();
            // A delayed hydration must not reactivate a prescription changed in the meantime.
            if ($existing && in_array(strtolower((string) $existing->status), ['blocked', 'completed', 'rejected', 'expired', 'entered-in-error'], true)) {
                $detail['status'] = $existing->status;
            }
            $this->importDetails($detail, (int) $locked->personId, MedicationRequestRequest::TYPE_PRESCRIPTION);
            $locked->signedPrescriptionPending = false;
            $locked->save();
        });
        $request->refresh();
    }

    public function pendingSignedPrescriptions(int $personId): Collection
    {
        return $this->model->newQuery()->wherePersonId($personId)
            ->where('resource_type', MedicationRequestRequest::TYPE_REQUEST)
            ->where('signed_prescription_pending', true)->get();
    }

    public function occupiedQuantity(string $activityUuid, ?string $exceptUuid = null): float
    {
        return (float) $this->quantityRecords($activityUuid)
            ->when($exceptUuid, fn ($query) => $query->where('uuid', '!=', $exceptUuid))
            ->whereNotIn('status', ActivityRemainingQuantityGuard::occupyingStatusesExcluded())
            ->sum('medication_qty');
    }

    public function eligibleEncounters(int $personId, ?string $employeeUuid): Collection
    {
        if ($employeeUuid === null || $employeeUuid === '') {
            return collect();
        }

        $localDay = CarbonImmutable::now((string) config('app.timezone'))->startOfDay();
        $startUtc = $localDay->utc()->format('Y-m-d H:i:s');
        $endUtc = $localDay->addDay()->utc()->format('Y-m-d H:i:s');

        return Encounter::query()->where('person_id', $personId)
            ->where('status', EncounterStatus::FINISHED)
            ->whereHas('performer', fn ($query) => $query->whereValue($employeeUuid))
            ->whereHas('period', fn ($query) => $query->where('end', '>=', $startUtc)->where('end', '<', $endUtc))
            ->with(['period', 'performer'])->orderByDesc('id')->get();
    }

    public function sumIssuedQuantityByActivity(string $activityUuid): float
    {
        return (float) $this->quantityRecords($activityUuid)
            ->where('status', '!=', MedicationRequestStatus::ENTERED_IN_ERROR->value)
            ->sum('medication_qty');
    }

    /** Prefer the linked MR's current status and quantity over its signed MRR. */
    private function quantityRecords(string $activityUuid): Builder
    {
        return $this->model->newQuery()->whereHas('basedOn', fn ($query) => $query->whereValue($activityUuid))
            ->where(function ($query) use ($activityUuid): void {
                $query->where('resource_type', MedicationRequestRequest::TYPE_PRESCRIPTION)
                    ->orWhereNotExists(function ($prescription) use ($activityUuid): void {
                        $prescription->selectRaw('1')->from('medication_request_requests as prescription')
                            ->join('identifiers as basis', 'basis.id', '=', 'prescription.based_on_id')
                            ->whereRaw("medication_request_requests.ehealth_payload->>'active_id' = prescription.uuid::text")
                            ->whereColumn('prescription.person_id', 'medication_request_requests.person_id')
                            ->where('prescription.resource_type', MedicationRequestRequest::TYPE_PRESCRIPTION)
                            ->where('basis.value', $activityUuid);
                    });
            });
    }

    /** Hide a signed request only when its actual prescription is stored for the same patient and activity. */
    public function carePlanDocumentsForDisplay(EloquentCollection $records): EloquentCollection
    {
        $prescriptions = $records->filter(fn (MedicationRequestRequest $record): bool => $record->resourceType === MedicationRequestRequest::TYPE_PRESCRIPTION)->keyBy('uuid');

        return $records->reject(function (MedicationRequestRequest $record) use ($prescriptions): bool {
            if ($record->resourceType !== MedicationRequestRequest::TYPE_REQUEST) {
                return false;
            }
            $prescription = $prescriptions->get(data_get($record->ehealthPayload, 'active_id'));

            return $prescription !== null
                && (int) $prescription->personId === (int) $record->personId
                && $prescription->basedOn?->value === $record->basedOn?->value;
        })->values();
    }

    /**
     * Cache a patient-scoped eHealth request or prescription without replacing local-only details.
     * Resource type is supplied by the API endpoint used for the search; source records its origin.
     *
     * @param  array<string, mixed>  $eHealthData  Raw payload from GET /persons/{id}/medication_requests or similar.
     * @param  int  $personId  Local person ID.
     * @return MedicationRequestRequest
     */
    public function upsertFromEHealth(
        array $eHealthData,
        int $personId,
        string $resourceType = MedicationRequestRequest::TYPE_PRESCRIPTION
    ): MedicationRequestRequest {
        $uuid = $eHealthData['id'] ?? ($eHealthData['uuid'] ?? null);
        $personUuid = data_get($eHealthData, 'person.id') ?? ($eHealthData['person_id'] ?? null);
        if ($personUuid !== null && $personUuid !== $this->personUuid($personId)) {
            throw new InvalidArgumentException('The medication list belongs to another patient.');
        }

        if (!is_string($uuid) || !Str::isUuid($uuid)) {
            throw new InvalidArgumentException('eHealth medication record must have a UUID.');
        }
        if (!in_array($resourceType, [MedicationRequestRequest::TYPE_REQUEST, MedicationRequestRequest::TYPE_PRESCRIPTION], true)) {
            throw new InvalidArgumentException('Unknown medication resource type.');
        }

        return $this->storageTransaction(function () use ($eHealthData, $personId, $resourceType, $uuid) {
            $request = $this->model->newQuery()->where('uuid', $uuid)->lockForUpdate()->first();
            if ($request !== null && (int) $request->personId !== $personId) {
                throw (new ModelNotFoundException())->setModel(MedicationRequestRequest::class);
            }

            if ($request === null) {
                $request = $this->model->newInstance([
                    'uuid' => $uuid,
                    'person_id' => $personId,
                    'source' => MedicationRequestRequest::SOURCE_EHEALTH,
                    'status' => 'unknown',
                ]);
            } elseif ($request->source === MedicationRequestRequest::SOURCE_LOCAL && $resourceType !== MedicationRequestRequest::TYPE_REQUEST) {
                throw new InvalidArgumentException('A local request cannot be overwritten by a prescription.');
            }
            if ($request->detailsSyncedAt !== null && $request->resourceType !== $resourceType) {
                throw new InvalidArgumentException('A clinical medication resource cannot change resource type during list import.');
            }

            // List responses are partial; do not erase the local author, references or medication details.
            $request->fill(array_filter([
                'status' => $eHealthData['status'] ?? null,
                'request_number' => $eHealthData['request_number'] ?? ($eHealthData['requisition'] ?? null),
                'started_at' => $eHealthData['started_at'] ?? null,
                'ended_at' => $eHealthData['ended_at'] ?? null,
                'medication_id' => $eHealthData['medication_id'] ?? data_get($eHealthData, 'medication_info.medication_id'),
                'medication_qty' => $eHealthData['medication_qty'] ?? data_get($eHealthData, 'medication_info.medication_qty'),
                'medication_program_id' => $eHealthData['medical_program_id'] ?? data_get($eHealthData, 'medical_program.id'),
            ], static fn ($value) => $value !== null));
            $request->resourceType = $resourceType;
            // Replace supplied arrays as a whole, so removed dosage entries cannot survive a refresh.
            $request->ehealthPayload = array_replace($request->ehealthPayload ?? [], $eHealthData);
            $request->save();

            return $request;
        });
    }

    /**
     * Search locally-stored eHealth-sourced MedicationRequests for a patient
     * (these are the signed prescriptions, as opposed to request-requests which are drafts).
     *
     * @param  int  $personId
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function searchEHealthPrescriptionsByPersonId(int $personId, array $filters = []): array
    {
        return $this->searchByPersonId($personId, array_merge($filters, [
            'resource_type' => MedicationRequestRequest::TYPE_PRESCRIPTION,
        ]));
    }

    public function issuedCarePlanQuantity(string $activityUuid, array $excludedStatuses = MedicalEventsRequestStatuses::EXCLUDED_FROM_ISSUED_SUM, ?string $exceptUuid = null): float
    {
        $query = $this->quantityRecords($activityUuid)->whereNotIn('status', $excludedStatuses);
        if ($exceptUuid !== null) {
            $query->where('uuid', '!=', $exceptUuid);
        }

        return (float) $query->sum('medication_qty');
    }

    public function lastActiveForTreatment(int $personId, string $medicationUuid): ?MedicationRequestRequest
    {
        return $this->model->newQuery()->wherePersonId($personId)->whereMedicationId($medicationUuid)->whereIn('status', ['active', 'signed'])->orderByDesc('ended_at')->first();
    }

    public function saveStatus(MedicationRequestRequest $record, string $status): void
    {
        $record->status = $status;
        $record->save();
    }

    public function clinicalContext(MedicationRequestRequest $record): CarePlan|Encounter|null
    {
        $planReference = $record->references()->wherePivot('field', 'basedOn')->with('type.coding')->get()
            ->first(fn ($reference): bool => (new MedicationRequestMapper())->referenceType(MedicalEventsRepository::identifier()->toReference($reference)) === 'care_plan');
        if ($planReference !== null) {
            return CarePlan::query()->whereUuid($planReference->value)->wherePersonId($record->personId)->first();
        }

        return Encounter::query()->whereUuid($record->context?->value)->wherePersonId($record->personId)->first();
    }

    /** Import every patient-checked care-plan document, including records issued by another MIS. */
    public function importCarePlanDocuments(CarePlan $carePlan, array $activeItems, array $draftItems): int
    {
        $personUuid = $this->personUuid((int) $carePlan->personId);
        $rows = [];
        foreach ([MedicationRequestRequest::TYPE_PRESCRIPTION => $activeItems, MedicationRequestRequest::TYPE_REQUEST => $draftItems] as $type => $items) {
            foreach ($items as $item) {
                if (data_get($item, 'person.id') !== $personUuid) {
                    throw new InvalidArgumentException('The care-plan medication response belongs to another patient.');
                }
                $resource = (new MedicationRequestMapper())->fromEHealth($item);
                // Keep local lifecycle values canonical while retaining untouched native status for signing.
                $status = MedicationRequestStatus::resolve($resource['status']);
                if ($status === null) {
                    throw new InvalidArgumentException('The care-plan medication response returned an invalid status.');
                }
                $resource['status'] = $status->value;
                $plan = collect($resource['basedOn'] ?? [])->first(fn (array $ref): bool => (new MedicationRequestMapper())->referenceType($ref) === 'care_plan');
                if ($plan !== null && data_get($plan, 'identifier.value') !== $carePlan->uuid) {
                    throw new InvalidArgumentException('The medication basis belongs to another care plan.');
                }
                // The patient-checked care-plan endpoint supplies the plan basis when its list omits it.
                if ($plan === null) {
                    $resource['basedOn'][] = ['identifier' => ['value' => $carePlan->uuid, 'type' => ['coding' => [['system' => 'eHealth/resources', 'code' => 'care_plan']]]]];
                }
                $rows[] = [$resource, $type, $item];
            }
        }

        return $this->storageTransaction(function () use ($carePlan, $rows): int {
            foreach ($rows as [$resource, $type, $native]) {
                $this->storeResource($resource, (int) $carePlan->personId, resourceType: $type, native: $native);
            }

            return count($rows);
        });
    }

    public function synchronizeCarePlanDocuments(Collection $localRequests, array $activeItems, array $draftItems): int
    {
        return $this->storageTransaction(function () use ($localRequests, $activeItems, $draftItems): int {
            $records = $this->model->newQuery()->whereKey($localRequests->pluck('id')->all())
                ->with('basedOn.type.coding')->lockForUpdate()->get();
            $updatedCount = 0;

            foreach ($activeItems as $item) {
                if (empty($item['id']) || empty($item['status'])) {
                    continue;
                }
                $match = $records->firstWhere('uuid', $item['id'])
                    ?? $records->first(fn ($record) => ($record->ehealthPayload['active_id'] ?? null) === $item['id']);
                if ($match === null && !empty($item['request_number'])) {
                    $candidates = $records->filter(fn ($record) => $record->resourceType === MedicationRequestRequest::TYPE_REQUEST
                        && $record->requestNumber === $item['request_number']
                        && empty($record->ehealthPayload['active_id']));
                    $match = $candidates->count() === 1 ? $candidates->first() : null;
                }
                if ($match === null) {
                    continue;
                }
                $remotePersonUuid = data_get($item, 'person.id') ?? ($item['person_id'] ?? null);
                if ($remotePersonUuid !== null && $remotePersonUuid !== (string) Person::findOrFail($match->personId)->uuid) {
                    throw new InvalidArgumentException('The medication sync belongs to another patient.');
                }
                $status = MedicationRequestStatus::resolve($item['status']);
                if ($status === null || !in_array($status, [MedicationRequestStatus::ACTIVE, MedicationRequestStatus::BLOCKED, MedicationRequestStatus::COMPLETED, MedicationRequestStatus::EXPIRED, MedicationRequestStatus::REJECTED, MedicationRequestStatus::ENTERED_IN_ERROR], true)) {
                    throw new InvalidArgumentException('The prescription list returned an invalid MR status.');
                }
                if ($match->resourceType === MedicationRequestRequest::TYPE_REQUEST && $match->uuid === $item['id']) {
                    throw new InvalidArgumentException('An MRR and its prescription must have separate identities.');
                }
                $existing = $this->model->newQuery()->whereUuid($item['id'])->lockForUpdate()->first();
                if ($existing !== null && $existing->resourceType !== MedicationRequestRequest::TYPE_PRESCRIPTION) {
                    throw new InvalidArgumentException('A prescription cannot overwrite a medication request.');
                }
                $prescription = $this->upsertFromEHealth(
                    array_replace($item, ['status' => $status->value]),
                    (int) $match->personId,
                    MedicationRequestRequest::TYPE_PRESCRIPTION
                );
                if ($existing === null || $existing->status !== $prescription->status) {
                    $updatedCount++;
                }
                if ($match->resourceType === MedicationRequestRequest::TYPE_REQUEST) {
                    // A known signing link supplies a local basis for the partial MR cache.
                    // It remains noncanonical until its complete details are imported.
                    if ($prescription->basedOnId === null && $match->basedOn !== null) {
                        $basis = MedicalEventsRepository::identifier()->update(MedicalEventsRepository::identifier()->store($match->basedOn->value), MedicalEventsRepository::identifier()->toReference($match->basedOn));
                        $prescription->basedOnId = $basis->id;
                    }
                    $prescription->medicationQty ??= $match->medicationQty;
                    $prescription->save();
                    if (strtolower($match->status) !== MedicationRequestStatus::SIGNED->value) {
                        $updatedCount++;
                    }
                    $match->fill(['status' => MedicationRequestStatus::SIGNED->value,
                        'ehealth_payload' => array_replace($match->ehealthPayload ?? [], ['active_id' => $item['id']])])->save();
                }
            }

            foreach ($draftItems as $item) {
                $match = empty($item['id']) ? null : $records->firstWhere('uuid', $item['id']);
                if ($match === null || $match->resourceType !== MedicationRequestRequest::TYPE_REQUEST
                    || !in_array(strtolower($match->status), [MedicationRequestStatus::DRAFT->value, MedicationRequestStatus::NEW->value, MedicationRequestStatus::PENDING->value, MedicationRequestStatus::PROCESSING->value], true)
                    || !empty($match->ehealthPayload['active_id']) || empty($item['status'])) {
                    continue;
                }
                $remotePersonUuid = data_get($item, 'person.id') ?? ($item['person_id'] ?? null);
                if ($remotePersonUuid !== null && $remotePersonUuid !== (string) Person::findOrFail($match->personId)->uuid) {
                    throw new InvalidArgumentException('The medication sync belongs to another patient.');
                }
                $status = MedicationRequestStatus::resolve($item['status']);
                if ($status === null || !in_array($status, [MedicationRequestStatus::NEW, MedicationRequestStatus::SIGNED, MedicationRequestStatus::REJECTED], true)) {
                    throw new InvalidArgumentException('The request list returned an invalid MRR status.');
                }
                if ($match->status !== $status->value) {
                    $match->update(['status' => $status->value]);
                    $updatedCount++;
                }
            }

            return $updatedCount;
        });
    }
}
