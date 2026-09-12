<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Enums\Person\ServiceRequestStatus;
use App\Models\CarePlanActivity;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Repositories\MedicalEvents\Concerns\ResolvesRequestFhirRefs;
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
    public function store(array $data, int $personId): int
    {
        return DB::transaction(function () use ($data, $personId) {
            $fhirRefs = $this->resolveRequestFhirRefs([
                'intent' => $data['intent'] ?? 'order',
                'category' => $data['category'] ?? null,
                'priority' => $data['priority'] ?? null,
                'based_on_uuid' => $data['based_on_uuid'] ?? null,
                'context_uuid' => $data['context_uuid'] ?? null,
            ]);

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
                    'service_id' => $data['service_id'],
                    'quantity' => $data['quantity'] ?? 1,
                    'program_id' => $data['program_id'] ?? null,
                    'intent_id' => $fhirRefs['intent_id'],
                    'category_id' => $fhirRefs['category_id'],
                    'based_on_id' => $fhirRefs['based_on_id'],
                    'context_id' => $fhirRefs['context_id'],
                    'priority_id' => $fhirRefs['priority_id'],
                    'note' => $data['note'] ?? null,
                    'patient_instruction' => $data['patient_instruction'] ?? null,
                    'reason_reference' => $data['reason_reference'] ?? null,
                    'inform_with' => $data['inform_with'] ?? null,
                    'supporting_info' => $data['supporting_info'] ?? null,
                ]
            );

            return (int) $request->id;
        });
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
            ->with(['basedOn', 'context', 'category', 'priority'])
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
            : \App\Models\MedicalEvents\Sql\Encounter::query()
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

        $category = strtolower((string) ($request->category?->text ?? ''));
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

    public function sumIssuedQuantityByActivity(string $activityUuid): float
    {
        return (float) $this->model->newQuery()
            ->whereHas('basedOn', fn ($q) => $q->where('value', $activityUuid))
            ->whereNotIn('status', MedicalEventsRequestStatuses::EXCLUDED_FROM_ISSUED_SUM)
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

    private function displayProgramName(mixed $programId): string
    {
        $value = trim((string) ($programId ?? ''));
        if ($value === '' || preg_match('/^[0-9a-f-]{36}$/i', $value) === 1) {
            return '—';
        }

        return $value;
    }
}
