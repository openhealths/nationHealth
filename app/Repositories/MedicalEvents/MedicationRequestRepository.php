<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Enums\Person\MedicationRequestStatus;
use App\Models\CarePlanActivity;
use App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * @property MedicationRequestRequest $model
 */
class MedicationRequestRepository extends BaseRepository
{
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
    public function store(array $data, int $personId): int
    {
        return DB::transaction(function () use ($data, $personId) {
            $request = $this->model->updateOrCreate(
                ['uuid' => $data['uuid'] ?? $data['id']],
                [
                'employee_id' => $data['employee_id'],
                'person_id' => $personId,
                'division_id' => $data['division_id'] ?? null,
                'status' => $data['status'],
                'request_number' => $data['request_number'] ?? null,
                'started_at' => $data['started_at'] ?? null,
                'ended_at' => $data['ended_at'] ?? null,
                'medication_id' => $data['medication_id'],
                'medication_qty' => $data['medication_qty'],
                'medication_program_id' => $data['medication_program_id'] ?? null,
                'intent_id' => !empty($data['intent']) ? \App\Models\MedicalEvents\Sql\Coding::firstOrCreate(['code' => $data['intent'], 'system' => 'http://snomed.info/sct'])->id : null,
                'category_id' => !empty($data['category']) ? \App\Models\MedicalEvents\Sql\CodeableConcept::firstOrCreate(['text' => $data['category']])->id : null,
                'based_on_id' => !empty($data['based_on_uuid']) ? \App\Repositories\MedicalEvents\Repository::identifier()->store($data['based_on_uuid'])->id : null,
                'context_id' => !empty($data['context_uuid']) ? \App\Repositories\MedicalEvents\Repository::identifier()->store($data['context_uuid'])->id : null,
                'priority_id' => !empty($data['priority']) ? \App\Models\MedicalEvents\Sql\CodeableConcept::firstOrCreate(['text' => $data['priority']])->id : null,
                'prior_prescription_id' => $data['prior_prescription_id'] ?? null,
                'container_dosage' => $data['container_dosage'] ?? null,
                'note' => $data['note'] ?? null,
                'inform_with' => $data['inform_with'] ?? null,
                'ehealth_payload' => $data['ehealth_payload'] ?? null,
                ]
            );

            // Re-submitting the same draft replaces its dosage instructions wholesale.
            if (!$request->wasRecentlyCreated) {
                foreach ($request->dosageInstructions as $existing) {
                    $existing->doseRate()->delete();
                    $existing->delete();
                }
            }

            if (!empty($data['dosage_instructions'])) {
                foreach ($data['dosage_instructions'] as $inst) {
                    $instruction = $request->dosageInstructions()->create([
                        'medication_request_id' => $inst['medication_request_id'] ?? null,
                        'sequence' => $inst['sequence'] ?? null,
                        'text' => $inst['text'] ?? null,
                        'patient_instruction' => $inst['patient_instruction'] ?? null,
                        'timing' => !empty($inst['timing']) ? json_encode($inst['timing']) : null,
                        'as_needed_boolean' => $inst['as_needed_boolean'] ?? false,
                        'route' => $inst['route'] ?? null,
                        'method' => $inst['method'] ?? null,
                        'dose_and_rate' => !empty($inst['dose_and_rate']) ? json_encode($inst['dose_and_rate']) : null,
                        'max_dose_per_period' => $inst['max_dose_per_period'] ?? null,
                        'max_dose_per_administration' => $inst['max_dose_per_administration'] ?? null,
                        'max_dose_per_lifetime' => $inst['max_dose_per_lifetime'] ?? null,
                    ]);

                    if (!empty($inst['dose_and_rate'])) {
                        foreach ($inst['dose_and_rate'] as $dr) {
                            $instruction->doseRate()->create([
                                'rate_ratio' => $dr['rate_ratio'] ?? null,
                            ]);
                        }
                    }
                }
            }

            return (int) $request->id;
        });
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
            ->with(['dosageInstructions.doseRate', 'basedOn', 'context', 'category'])
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

        // Filter by source ('local' or 'ehealth'); defaults to 'local' if not specified
        $source = $filters['source'] ?? null;
        if ($source !== null) {
            $query->where('source', $source);
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
            : \App\Models\MedicalEvents\Sql\Encounter::query()
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
            ? rtrim(rtrim(number_format((float) $qty, 2, '.', ''), '0'), '.')
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

        $categoryValue = $request->category?->text ?: data_get($payload, 'category') ?: '';
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

    public function sumIssuedQuantityByActivity(string $activityUuid): float
    {
        return (float) $this->model->newQuery()
            ->whereHas('basedOn', fn ($q) => $q->where('value', $activityUuid))
            ->where('status', '!=', MedicationRequestStatus::ENTERED_IN_ERROR->value)
            ->sum('medication_qty');
    }

    /**
     * Upsert a MedicationRequest record returned from the eHealth API into the local DB.
     * The eHealth `medication_request` (signed prescription) is stored in `medication_request_requests`
     * with source = 'ehealth' so it can be listed on the patient card without re-querying eHealth.
     *
     * @param  array<string, mixed>  $eHealthData  Raw payload from GET /persons/{id}/medication_requests or similar.
     * @param  int  $personId  Local person ID.
     * @return MedicationRequestRequest
     */
    public function upsertFromEHealth(array $eHealthData, int $personId): MedicationRequestRequest
    {
        $uuid = $eHealthData['id'] ?? $eHealthData['uuid'] ?? null;

        if (empty($uuid)) {
            throw new \InvalidArgumentException('eHealth MedicationRequest record must have an id/uuid field.');
        }

        return $this->model->updateOrCreate(
            ['uuid' => $uuid],
            [
                'person_id'             => $personId,
                'employee_id'           => null, // not always available for external records
                'status'                => $eHealthData['status'] ?? 'unknown',
                'request_number'        => $eHealthData['request_number'] ?? $eHealthData['requisition'] ?? null,
                'started_at'            => $eHealthData['started_at'] ?? null,
                'ended_at'              => $eHealthData['ended_at'] ?? null,
                'medication_id'         => $eHealthData['medication_id'] ?? data_get($eHealthData, 'medication_info.id') ?? '',
                'medication_qty'        => (float) ($eHealthData['medication_qty'] ?? 1),
                'medication_program_id' => $eHealthData['medical_program_id'] ?? data_get($eHealthData, 'medical_program.id') ?? null,
                'ehealth_payload'       => $eHealthData,
                'source'                => MedicationRequestRequest::SOURCE_EHEALTH,
            ]
        );
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
        $query = $this->model->newQuery()
            ->where('person_id', $personId)
            ->where('source', MedicationRequestRequest::SOURCE_EHEALTH);

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $query->whereRaw('LOWER(status) = ?', [strtolower($status)]);
        }

        if (!empty($filters['request_number'])) {
            $query->where('request_number', 'like', '%' . $filters['request_number'] . '%');
        }

        return $query->orderByDesc('started_at')->orderByDesc('id')->get()
            ->map(fn (MedicationRequestRequest $r): array => $this->toPatientRegistryRow($r))
            ->all();
    }
}
