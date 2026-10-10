<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Enums\MedicalEvents\ReferralCompletionResourceType;
use App\Enums\Person\ServiceRequestStatus;
use App\Classes\eHealth\Api\Responses\Collections\ServiceRequestUse;
use App\Dto\ServiceRequest\Model as ServiceRequestModelData;
use App\Models\CarePlanActivity;
use App\Models\Employee\Employee;
use App\Models\MedicalEvents\Sql\DeviceRequestRequest;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Repositories\MedicalEvents\Concerns\FindsOpenActivityRequests;
use App\Repositories\MedicalEvents\Concerns\FindsOwnedRequests;
use App\Repositories\MedicalEvents\Concerns\ResolvesRequestFhirRefs;
use App\Classes\eHealth\Api\Responses\Collections\ServiceRequestSearch;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Throwable;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;

/**
 * @property ServiceRequestRequest $model
 */
class ServiceRequestRequestRepository extends BaseRepository
{
    use ResolvesRequestFhirRefs;
    use FindsOpenActivityRequests;
    use FindsOwnedRequests;

    public function assertCompletionResourceOwned(string $referralUuid, string $resourceUuid, ReferralCompletionResourceType $type): void
    {
        $personId = $this->findByUuid($referralUuid)?->personId;
        $modelClass = $type->modelClass();
        $resource = $modelClass::query()->where('uuid', $resourceUuid)->first();

        if ($resource === null) {
            throw new \InvalidArgumentException(__('care-plan.referral_complete_emz_required'));
        }

        $resourcePersonId = $resource->personId ?? $resource->person_id ?? null;
        if ($personId !== null && $resourcePersonId !== null && (int) $resourcePersonId !== (int) $personId) {
            throw new \InvalidArgumentException(__('care-plan.referral_complete_emz_mismatch'));
        }
    }

    public function setExecutionStatus(string $uuid, ServiceRequestStatus $status): void
    {
        $this->findByUuid($uuid)?->update(['status' => $status->value]);
    }

    /** Persist only after eHealth has successfully resolved the use action. */
    public function persistExecution(string $uuid, Employee $employee, ?string $patientUuid, mixed $programId, array $response): void
    {
        $model = $this->findByUuid($uuid);
        if ($model !== null) {
            $model->update([
                'status' => ServiceRequestStatus::IN_PROGRESS->value,
                'program_id' => $programId ?? $model->programId,
            ]);

            return;
        }

        $person = $patientUuid ? \App\Models\Person\Person::where('uuid', $patientUuid)->first() : null;
        if ($person === null) {
            return;
        }

        $data = $response['data'] ?? $response;
        $fields = app(ObjectMapperInterface::class)->map(new ServiceRequestUse($data), ServiceRequestModelData::class)->toUseRecord();
        $this->store(array_replace($fields, [
            'uuid' => $uuid,
            'status' => ServiceRequestStatus::IN_PROGRESS->value,
            'employee_id' => $employee->id,
            'division_id' => $employee->divisionId,
            'program_id' => $programId ?? $fields['program_id'],
        ]), $person->id);
    }

    public function findOwnedReferralByPerson(string $uuid, int $personId, ?int $legalEntityId): ServiceRequestRequest|DeviceRequestRequest
    {
        try {
            return $this->findOwnedByPerson($uuid, $personId, $legalEntityId);
        } catch (ModelNotFoundException) {
            return Repository::deviceRequest()->findOwnedByPerson($uuid, $personId, $legalEntityId);
        }
    }

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

    /**
     * @param  array<string, mixed>  $referral
     */
    public function storeExternalIfMissing(array $referral, Employee $employee, int $personId): void
    {
        $data = app(ObjectMapperInterface::class)->map(new ServiceRequestSearch($referral), ServiceRequestModelData::class)->toExternalRecord();
        $uuid = $data['uuid'] ?? null;

        if (blank($uuid) || $this->findByUuid($uuid) !== null) {
            return;
        }

        $this->store([
            ...$data,
            'employee_id' => $employee->id,
            'division_id' => $employee->divisionId,
            'status' => ServiceRequestStatus::ACTIVE->value,
            'intent' => 'order',
        ], $personId);
    }

    public function storeExternalManyIfMissing(array $referrals, int $personId): void
    {
        if ($referrals === []) {
            return;
        }

        $mappedReferrals = collect($referrals)
            ->map(function (array $item): array {
                $data = app(ObjectMapperInterface::class)->map(new ServiceRequestSearch($item['referral']), ServiceRequestModelData::class)->toExternalRecord();

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
            ->pluck('uuid')
            ->all();

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
                'status' => ServiceRequestStatus::ACTIVE->value,
                'intent' => 'order',
            ], $personId);
        }
    }

    public function sumIssuedQuantityByActivity(string $activityUuid): float
    {
        return (float) $this->model->newQuery()
            ->whereHas('basedOn', fn ($q) => $q->where('value', $activityUuid))
            ->whereNotIn('status', \App\Enums\MedicalEvents\RequestQuantityStatus::excluded())
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

    /**
     * @param  array<int, string>  $columns
     * @return Collection<int, ServiceRequestRequest>
     */
    public function getByPersonIdAndStatus(int $personId, string $status, array $columns = ['*']): Collection
    {
        return $this->model
            ->newQuery()
            ->where('person_id', $personId)
            ->where('status', $status)
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
}
