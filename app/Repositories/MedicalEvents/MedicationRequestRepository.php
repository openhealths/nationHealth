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
                'intent' => $data['intent'] ?? 'order',
                'category' => $data['category'] ?? null,
                'based_on_id' => $data['based_on_id'] ?? null,
                'context_id' => $data['context_id'] ?? null,
                'priority' => $data['priority'] ?? null,
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
            ->with(['dosageInstructions.doseRate'])
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

        $activityIds = $requests
            ->pluck('basedOnId')
            ->filter(static fn ($id): bool => (int) $id > 0)
            ->unique()
            ->values()
            ->all();

        $carePlanIdsByActivity = $activityIds === []
            ? []
            : CarePlanActivity::query()
                ->whereIn('id', $activityIds)
                ->pluck('care_plan_id', 'id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

        return $requests
            ->map(fn (MedicationRequestRequest $request): array => $this->toPatientRegistryRow($request, $carePlanIdsByActivity))
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
    public function toPatientRegistryRow(MedicationRequestRequest $request, array $carePlanIdsByActivity = []): array
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

        $category = strtolower((string) ($request->category ?: data_get($payload, 'category') ?: ''));
        $categoryLabel = match ($category) {
            'community' => 'Амбулаторно',
            'inpatient' => 'Стаціонар',
            default => $category !== '' ? $category : '—',
        };

        $activityId = $request->basedOnId !== null ? (int) $request->basedOnId : null;
        $encounterId = $request->contextId !== null ? (int) $request->contextId : null;
        $carePlanId = ($activityId !== null && $activityId > 0)
            ? (int) ($carePlanIdsByActivity[$activityId] ?? 0)
            : 0;
        $carePlanId = $carePlanId > 0 ? $carePlanId : null;
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

    public function sumIssuedQuantityByActivity(int $activityId): float
    {
        return (float) $this->model->newQuery()
            ->where('based_on_id', $activityId)
            ->where('status', '!=', MedicationRequestStatus::ENTERED_IN_ERROR->value)
            ->sum('medication_qty');
    }
}
