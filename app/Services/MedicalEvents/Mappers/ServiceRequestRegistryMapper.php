<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents\Mappers;

use App\Enums\Person\ServiceRequestStatus;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use Illuminate\Support\Facades\Lang;
use Throwable;

/** Project persisted referral data into registry and encounter-card display values. */
class ServiceRequestRegistryMapper
{
    private ?array $serviceNames = null;

    private ?array $programNames = null;

    /**
     * @param  array<string, object>  $carePlanIdsByActivityUuid
     * @param  array<string, int>  $encounterIdsByUuid
     * @return array<string, mixed>
     */
    public function map(
        ServiceRequestRequest $request,
        array $carePlanIdsByActivityUuid = [],
        array $encounterIdsByUuid = [],
        bool $pending = false,
        bool $canComplete = false
    ): array {
        $status = strtolower((string) $request->status);
        $startedAt = $request->startedAt;
        $endedAt = $request->endedAt;
        $qty = $request->quantity;
        $qtyLabel = $qty !== null && $qty !== ''
            ? rtrim(rtrim(number_format((float) $qty, 2, '.', ''), '0'), '.')
            : '';

        $category = strtolower((string) ($request->category?->coding?->first()?->code ?? ($request->category?->text ?? '')));
        $categoryKey = 'care-plan.referral_category.'.match ($category) {
            'counselling' => 'counseling',
            'laboratory' => 'laboratory_procedure',
            'transfer_of_care' => 'transfer',
            default => $category,
        };
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
            : ($this->displayServiceName($serviceId) ?: $categoryLabel);

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
            'remainingQuantity' => (float) ($request->remainingQuantity?->value ?? 0),
            'startedAt' => $startedAt?->toDateString(),
            'endedAt' => $endedAt?->toDateString(),
            'periodLabel' => $periodLabel,
            'programName' => $this->displayProgramName($request),
            'categoryLabel' => $categoryLabel,
            'priorityLabel' => $priorityLabel,
            'note' => (string) ($request->note ?? ''),
            'patientInstruction' => (string) ($request->patientInstruction ?? ''),
            'basisLabel' => $basisLabel,
            'encounterId' => $encounterId,
            'activityId' => $activityId,
            'carePlanId' => $carePlanId,
            'canSign' => !$pending && in_array($status, $draftStatuses, true),
            'canOperate' => !$pending && $status === ServiceRequestStatus::ACTIVE->value,
            'canRecall' => !$pending && $status === ServiceRequestStatus::ACTIVE->value,
            'canCancel' => !$pending && $status === ServiceRequestStatus::ACTIVE->value,
            'canComplete' => !$pending && $canComplete,
        ];
    }

    private function displayServiceName(string $serviceId): ?string
    {
        if ($serviceId === '') {
            return null;
        }

        try {
            $this->serviceNames ??= dictionary()->services()->flattened()->pluck('name', 'id')->all();

            return $this->serviceNames[$serviceId] ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    private function displayProgramName(ServiceRequestRequest $request): string
    {
        $value = trim((string) ($request->programId ?? ''));
        if ($value === '') {
            return __('referrals.registry.program_not_specified');
        }
        $display = trim((string) ($request->programReference?->displayValue ?? ''));
        if ($display !== '' && strtolower($display) !== 'null') {
            return $display;
        }
        if ($this->programNames === null) {
            $this->programNames = [];
            try {
                $this->programNames = dictionary()->medicalPrograms()->pluck('name', 'id')->all();
            } catch (Throwable) {
                // Preserve the identifier when the program dictionary is temporarily unavailable.
            }
        }

        return $this->programNames[$value] ?? $value;
    }

}
