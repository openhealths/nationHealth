<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Enums\Person\MedicationRequestStatus;
use App\Models\CarePlanActivity;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest;
use App\Models\Person\Person;
use App\Repositories\MedicalEvents\Concerns\ResolvesRequestFhirRefs;
use App\Models\Employee\Employee;
use App\Models\Division;
use App\Models\MedicalEvents\Sql\Coding;
use App\Services\MedicalEvents\Mappers\MedicationRequestMapper;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
    private const REFERENCE_FIELDS = ['subject', 'requester', 'division', 'medicationReference', 'program', 'context', 'priorPrescription', 'basedOn'];

    public function store(array $data, int $personId): int
    {
        $existing = $this->findByUuid($data['uuid'] ?? $data['id']);
        if ($existing !== null && (int) $existing->personId !== $personId) {
            throw new InvalidArgumentException('The medication record belongs to another patient.');
        }
        $person = Person::findOrFail($personId);
        $employee = isset($data['employee_id']) ? Employee::findOrFail($data['employee_id']) : null;
        $division = isset($data['division_id']) ? Division::findOrFail($data['division_id']) : null;
        $original = $existing?->fhirVersion === 1 ? $this->getResource($existing) : [];
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

        return DB::transaction(function () use ($resource, $personId, $employeeId, $divisionId, $source, $resourceType, $native): MedicationRequestRequest {
            $request = $this->model->newQuery()->whereUuid($resource['id'])->lockForUpdate()->first();
            if ($request !== null && ((int) $request->personId !== $personId || $request->resourceType !== $resourceType)) {
                throw new InvalidArgumentException('Medication identity or resource type does not match.');
            }
            $canonical = $request?->fhirVersion === 1;
            $request ??= $this->model->newInstance();
            $existingReferences = $canonical ? $request->fhirReferences()->with('type.coding')->get() : collect();
            $referenceIds = [];
            $activityId = null;
            $contextId = null;
            foreach (self::REFERENCE_FIELDS as $field) {
                $values = $field === 'basedOn' ? ($resource[$field] ?? []) : (isset($resource[$field]) ? [$resource[$field]] : []);
                $existing = $existingReferences->filter(fn ($reference) => $reference->pivot->field === $field)->values();
                foreach (array_values($values) as $position => $value) {
                    $reference = Repository::identifier()->replaceReference($existing->get($position), $value);
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
                ? Repository::codeableConcept()->replaceComplete($canonical ? $request->category : null, $resource['category']) : null;
            $known = ['id', 'status', 'requestNumber', 'intent', 'priority', 'category', 'quantity', 'period', 'dosageInstruction', ...self::REFERENCE_FIELDS];
            $request->forceFill([
                'uuid' => $resource['id'], 'personId' => $personId,
                'employeeId' => $employeeId ?? $request->employeeId, 'divisionId' => $divisionId ?? $request->divisionId,
                'status' => $resource['status'], 'requestNumber' => $resource['requestNumber'] ?? null,
                'medicationId' => data_get($resource, 'medicationReference.identifier.value'),
                'medicationQty' => data_get($resource, 'quantity.value'),
                'medicationProgramId' => data_get($resource, 'program.identifier.value'),
                'startedAt' => data_get($resource, 'period.start'), 'endedAt' => data_get($resource, 'period.end'),
                'intentId' => isset($resource['intent']) ? Coding::firstOrCreate(['system' => self::REQUEST_INTENT_SYSTEM, 'code' => $resource['intent']])->id : null,
                'priorityId' => isset($resource['priority']) ? \App\Models\MedicalEvents\Sql\CodeableConcept::firstOrCreate(['text' => $resource['priority']])->id : null,
                'categoryId' => $category?->id, 'basedOnId' => $activityId, 'contextId' => $contextId,
                'note' => $resource['note'] ?? null,
                'containerDosage' => is_string($resource['containerDosage'] ?? null) ? $resource['containerDosage'] : null,
                'informWith' => \App\Services\MedicalEvents\InformWith::authMethodId($resource['informWith'] ?? null),
                'source' => $request->exists ? $request->source : $source, 'resourceType' => $resourceType,
                'fhirVersion' => 1,
                'fhirMetadata' => ['extra' => array_diff_key($resource, array_flip($known)),
                    'quantityAttributes' => array_diff_key($resource['quantity'] ?? [], ['value' => true]),
                    'present' => array_keys($resource)],
            ]);
            if ($native !== null) {
                $request->ehealthPayload = $native;
            }
            $request->save();
            $request->fhirReferences()->sync($referenceIds);
            foreach ($existingReferences as $reference) {
                if (!array_key_exists($reference->id, $referenceIds)) {
                    foreach ($reference->type as $concept) {
                        Repository::codeableConcept()->delete($concept);
                    }
                    $reference->delete();
                }
            }
            $existingDosages = $request->dosageInstructions()->orderBy('position')->orderBy('id')->get();
            $kept = [];
            foreach (array_values($resource['dosageInstruction'] ?? []) as $position => $dosage) {
                $row = $existingDosages->get($position) ?? $request->dosageInstructions()->make();
                $row->fill([
                    'position' => $position, 'fhir_data' => $dosage,
                    'sequence' => $dosage['sequence'] ?? null, 'text' => $dosage['text'] ?? null,
                    'patient_instruction' => $dosage['patientInstruction'] ?? null,
                    'timing' => isset($dosage['timing']) ? json_encode($dosage['timing'], JSON_THROW_ON_ERROR) : null,
                    'as_needed_boolean' => $dosage['asNeededBoolean'] ?? false,
                    'route' => data_get($dosage, 'route.coding.0.code'), 'method' => data_get($dosage, 'method.coding.0.code'),
                    'dose_and_rate' => isset($dosage['doseAndRate']) ? json_encode($dosage['doseAndRate'], JSON_THROW_ON_ERROR) : null,
                    'max_dose_per_administration' => data_get($dosage, 'maxDosePerAdministration.value'),
                    'max_dose_per_period' => data_get($dosage, 'maxDosePerPeriod.numerator.value'),
                    'max_dose_per_lifetime' => data_get($dosage, 'maxDosePerLifetime.value'),
                ])->save();
                $kept[] = $row->id;
            }
            $request->dosageInstructions()->whereNotIn('id', $kept)->delete();

            return $request->unsetRelations();
        });
    }

    public function getResource(MedicationRequestRequest|int $request): array
    {
        $request = is_int($request) ? $this->model->newQuery()->findOrFail($request) : $request;
        if ($request->fhirVersion !== 1) {
            throw new InvalidArgumentException('Medication record requires full detail import before clinical use.');
        }
        $request->load(['fhirReferences.type.coding', 'dosageInstructions', 'intent', 'category.coding', 'priority']);
        $metadata = $request->fhirMetadata ?? [];
        $resource = $metadata['extra'] ?? [];
        $resource['id'] = $request->uuid;
        $resource['status'] = $request->status;
        foreach (['requestNumber' => $request->requestNumber, 'intent' => $request->intent?->code, 'priority' => $request->priority?->text] as $field => $value) {
            if ($value !== null) {
                $resource[$field] = $value;
            }
        }
        if ($request->category !== null) {
            $resource['category'] = Repository::codeableConcept()->toResource($request->category);
        }
        if ($request->medicationQty !== null) {
            $resource['quantity'] = array_replace($metadata['quantityAttributes'] ?? [], ['value' => (float) $request->medicationQty]);
        }
        if (in_array('period', $metadata['present'] ?? [], true)) {
            $resource['period'] = array_filter(['start' => $request->startedAt?->toDateString(), 'end' => $request->endedAt?->toDateString()], static fn ($value) => $value !== null);
        }
        foreach ($request->fhirReferences as $reference) {
            $value = Repository::identifier()->toReference($reference);
            $field = $reference->pivot->field;
            if ($field === 'basedOn') {
                $resource[$field][] = $value;
            } else {
                $resource[$field] = $value;
            }
        }
        foreach (['basedOn', 'dosageInstruction'] as $field) {
            if (in_array($field, $metadata['present'] ?? [], true)) {
                $resource[$field] ??= [];
            }
        }
        if ($request->dosageInstructions->isNotEmpty()) {
            $resource['dosageInstruction'] = $request->dosageInstructions->sortBy('position')->map(fn ($row) => $row->fhirData)->values()->all();
        }

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

        return $this->model->newQuery()->wherePersonId($personId)->orderBy('id')->get(['id', 'uuid', 'resource_type', 'fhir_version'])
            ->map(fn ($record) => ['localId' => $record->id, 'uuid' => $record->uuid, 'resourceType' => $record->resourceType,
                'action' => $record->fhirVersion === 1 ? 'ready' : 'importFullDetails'])->all();
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
            $quantity = BigDecimal::of((string) $value);
            if (!$quantity->isEqualTo((string) (float) $value)) {
                throw new InvalidArgumentException('Medication quantity cannot round-trip through the JSON numeric contract.');
            }
            if (!$quantity->isEqualTo($quantity->toScale(10, \Brick\Math\RoundingMode::DOWN)) || $quantity->abs()->isGreaterThanOrEqualTo('100000000000000')) {
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

    public function assertActivityBasis(\App\Models\CarePlan $plan, CarePlanActivity $activity, array $formData): void
    {
        if ((int) $activity->carePlanId !== (int) $plan->id) {
            throw new InvalidArgumentException('The medication activity belongs to another Care Plan.');
        }
        $repository = app(\App\Repositories\CarePlanActivityRepository::class);
        if (($activity->getAttributes()['fhir_version'] ?? null) === 1 && method_exists($repository, 'getResource')) {
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
        DB::transaction(function () use ($request, $prescription): void {
            $locked = $this->model->newQuery()->whereKey($request->id)->wherePersonId($request->personId)->lockForUpdate()->firstOrFail();
            $payload = $locked->ehealthPayload ?? [];
            if (isset($payload['active_id']) && $payload['active_id'] !== $prescription['id']) {
                throw new InvalidArgumentException('The signed request is already linked to another prescription.');
            }
            $payload['active_id'] = $prescription['id'];
            $metadata = $locked->fhirMetadata ?? [];
            $metadata['signedPrescriptionPending'] = true;
            $locked->forceFill(['status' => MedicationRequestStatus::SIGNED->value, 'ehealthPayload' => $payload, 'fhirMetadata' => $metadata])->save();
        });
        $request->refresh();
    }

    /** A GET-only recovery path; importing details must never submit another signature. */
    public function completeSignedPrescription(MedicationRequestRequest $request, array $detail): void
    {
        DB::transaction(function () use ($request, $detail): void {
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
            $metadata = $locked->fhirMetadata ?? [];
            unset($metadata['signedPrescriptionPending']);
            $locked->fhirMetadata = $metadata;
            $locked->save();
        });
        $request->refresh();
    }

    public function pendingSignedPrescriptions(int $personId): \Illuminate\Support\Collection
    {
        return $this->model->newQuery()->wherePersonId($personId)
            ->where('resource_type', MedicationRequestRequest::TYPE_REQUEST)
            ->where('fhir_metadata->signedPrescriptionPending', true)->get();
    }

    public function occupiedQuantity(string $activityUuid, ?string $exceptUuid = null): float
    {
        return (float) $this->quantityRecords($activityUuid)
            ->when($exceptUuid, fn ($query) => $query->where('uuid', '!=', $exceptUuid))
            ->whereNotIn('status', \App\Services\MedicalEvents\ActivityRemainingQuantityGuard::occupyingStatusesExcluded())
            ->sum('medication_qty');
    }

    public function eligibleEncounters(int $personId, ?string $employeeUuid): \Illuminate\Support\Collection
    {
        if ($employeeUuid === null || $employeeUuid === '') {
            return collect();
        }

        $localDay = \Carbon\CarbonImmutable::now((string) config('app.timezone'))->startOfDay();
        $startUtc = $localDay->utc()->format('Y-m-d H:i:s');
        $endUtc = $localDay->addDay()->utc()->format('Y-m-d H:i:s');

        return Encounter::query()->where('person_id', $personId)
            ->where('status', \App\Enums\Person\EncounterStatus::FINISHED)
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
    private function quantityRecords(string $activityUuid): \Illuminate\Database\Eloquent\Builder
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
        $uuid = $eHealthData['id'] ?? $eHealthData['uuid'] ?? null;

        if (!is_string($uuid) || !Str::isUuid($uuid)) {
            throw new InvalidArgumentException('eHealth medication record must have a UUID.');
        }
        if (!in_array($resourceType, [MedicationRequestRequest::TYPE_REQUEST, MedicationRequestRequest::TYPE_PRESCRIPTION], true)) {
            throw new InvalidArgumentException('Unknown medication resource type.');
        }

        return DB::transaction(function () use ($eHealthData, $personId, $resourceType, $uuid) {
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
            if ($request->fhirVersion === 1 && $request->resourceType !== $resourceType) {
                throw new InvalidArgumentException('A clinical medication resource cannot change resource type during list import.');
            }

            // List responses are partial; do not erase the local author, references or medication details.
            $request->fill(array_filter([
                'status' => $eHealthData['status'] ?? null,
                'request_number' => $eHealthData['request_number'] ?? $eHealthData['requisition'] ?? null,
                'started_at' => $eHealthData['started_at'] ?? null,
                'ended_at' => $eHealthData['ended_at'] ?? null,
                'medication_id' => $eHealthData['medication_id'] ?? data_get($eHealthData, 'medication_info.id'),
                'medication_qty' => $eHealthData['medication_qty'] ?? null,
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

    public function synchronizeCarePlanDocuments(\Illuminate\Support\Collection $localRequests, array $activeItems, array $draftItems): int
    {
        return DB::transaction(function () use ($localRequests, $activeItems, $draftItems): int {
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
                        $basis = Repository::identifier()->replaceReference(null, Repository::identifier()->toReference($match->basedOn));
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
