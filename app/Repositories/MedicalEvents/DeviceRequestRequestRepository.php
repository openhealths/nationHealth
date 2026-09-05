<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Enums\Person\ServiceRequestStatus;
use App\Models\CarePlanActivity;
use App\Models\MedicalEvents\Sql\DeviceRequestRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Throwable;

/**
 * @property DeviceRequestRequest $model
 */
class DeviceRequestRequestRepository extends BaseRepository
{
    public function __construct(DeviceRequestRequest $model)
    {
        parent::__construct($model);
    }
    /**
     * Create or update device request request in DB for patient.
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
                    'device_id' => $data['device_id'],
                    'quantity' => $data['quantity'] ?? 1,
                    'program_id' => $data['program_id'] ?? null,
                    'intent' => $data['intent'] ?? 'order',
                    'category' => $data['category'] ?? null,
                    'based_on_id' => $data['based_on_id'] ?? null,
                    'context_id' => $data['context_id'] ?? null,
                    'priority' => $data['priority'] ?? null,
                    'note' => $data['note'] ?? null,
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
            ->map(fn (DeviceRequestRequest $request): array => $this->toPatientRegistryRow($request, $carePlanIdsByActivity))
            ->all();
    }

    /**
     * @param  array<int, int>  $carePlanIdsByActivity
     * @return array<string, mixed>
     */
    public function toPatientRegistryRow(DeviceRequestRequest $request, array $carePlanIdsByActivity = []): array
    {
        $status = strtolower((string) $request->status);
        $startedAt = $request->startedAt;
        $endedAt = $request->endedAt;
        $qty = $request->quantity;
        $qtyLabel = $qty !== null && $qty !== ''
            ? rtrim(rtrim(number_format((float) $qty, 2, '.', ''), '0'), '.')
            : '';

        $category = strtolower((string) ($request->category ?? ''));
        $categoryKey = 'care-plan.referral_category.'.$category;
        $categoryLabel = $category !== '' && Lang::has($categoryKey)
            ? __($categoryKey)
            : ($category !== '' ? $category : '—');

        $priority = strtolower((string) ($request->priority ?? ''));
        $priorityKey = 'care-plan.referral_priority.'.$priority;
        $priorityLabel = $priority !== '' && Lang::has($priorityKey)
            ? __($priorityKey)
            : ($priority !== '' ? $priority : '—');

        $deviceId = (string) ($request->deviceId ?? '');
        $itemName = $deviceId !== '' && preg_match('/^[0-9a-f-]{36}$/i', $deviceId) !== 1
            ? $deviceId
            : 'Медичний виріб';

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

        $draftStatuses = [
            ServiceRequestStatus::DRAFT->value,
            ServiceRequestStatus::NEW->value,
        ];

        $programId = trim((string) ($request->programId ?? ''));
        $programName = ($programId === '' || preg_match('/^[0-9a-f-]{36}$/i', $programId) === 1)
            ? '—'
            : $programId;

        return [
            'id' => $request->id,
            'uuid' => (string) $request->uuid,
            'kind' => 'device_request',
            'requestNumber' => (string) ($request->requestNumber ?: $request->uuid),
            'status' => (string) $request->status,
            'statusLabel' => ServiceRequestStatus::labelFor($status),
            'statusBadge' => ServiceRequestStatus::colorFor($status),
            'itemName' => $itemName,
            'quantity' => $qtyLabel !== '' ? $qtyLabel : '—',
            'startedAt' => $startedAt?->toDateString(),
            'endedAt' => $endedAt?->toDateString(),
            'periodLabel' => $periodLabel,
            'programName' => $programName,
            'categoryLabel' => $categoryLabel,
            'priorityLabel' => $priorityLabel,
            'note' => (string) ($request->note ?? ''),
            'patientInstruction' => '',
            'basisLabel' => $basisLabel,
            'encounterId' => $encounterId,
            'activityId' => $activityId,
            'carePlanId' => $carePlanId,
            'canSign' => in_array($status, $draftStatuses, true),
            'canOperate' => $status === ServiceRequestStatus::ACTIVE->value,
            'canRecall' => false,
            'canCancel' => $status === ServiceRequestStatus::ACTIVE->value,
        ];
    }

    /**
     * Get device request requests data related to the person.
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

    public function findByUuid(string $uuid): ?DeviceRequestRequest
    {
        return $this->model->newQuery()->where('uuid', $uuid)->first();
    }

    public function sumIssuedQuantityByActivity(int $activityId): float
    {
        return (float) $this->model->newQuery()
            ->where('based_on_id', $activityId)
            ->whereNotIn('status', MedicalEventsRequestStatuses::EXCLUDED_FROM_ISSUED_SUM)
            ->sum('quantity');
    }

    public function findDraftByActivity(int $activityId): ?DeviceRequestRequest
    {
        return $this->model->newQuery()
            ->where('based_on_id', $activityId)
            ->whereIn('status', ['draft', 'DRAFT'])
            ->latest('id')
            ->first();
    }
}
