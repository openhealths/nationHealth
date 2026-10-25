<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\MedicalEvents\Referral;

use App\Dto\DeviceRequest\Model as DeviceRequestModelData;
use App\Dto\ServiceRequest\Model as ServiceRequestModelData;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\MedicalEvents\Sql\DeviceRequestRequest;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Repositories\EmployeeRepository;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;

trait PreparesReferralSigning
{
    protected function referralSignData(
        ServiceRequestRequest|DeviceRequestRequest $record,
        ?CarePlanActivity $activity = null,
        CarePlan|Encounter|null $context = null,
        ?array $employeeContext = null
    ): array {
        $record->loadMissing(['intent', 'category', 'priority', 'basedOn', 'context']);
        if ($employeeContext === null) {
            $employees = app(EmployeeRepository::class);
            $actingId = $record->employeeId !== null ? (int) $record->employeeId : null;
            $employeeContext = match (true) {
                $context instanceof Encounter => $employees->resolveEncounterEmployeeContext($context, $actingId),
                $context instanceof CarePlan => $employees->resolveEmployeeContext($context, $activity, $actingId),
                default => [],
            };
        }

        $data = app(ObjectMapperInterface::class)->map($record, $record instanceof ServiceRequestRequest ? ServiceRequestModelData::class : DeviceRequestModelData::class)->toArray();
        unset($data['status'], $data['request_number']);
        $data = array_replace($data, [
            'employee_id' => $employeeContext['employee_id'] ?? $record->employeeId,
            'division_id' => $employeeContext['division_id'] ?? $record->divisionId,
            'intent' => $data['intent'] ?? 'order',
            'priority' => $data['priority'] ?? 'routine',
            'started_at' => self::referralSignDate($record->startedAt ?? $activity?->scheduledPeriodStart),
            'ended_at' => self::referralSignDate($record->endedAt ?? $activity?->scheduledPeriodEnd),
            'based_on_uuid' => $record->basedOn?->value ?? $activity?->uuid,
            'context_uuid' => $record->context?->value ?? ($context instanceof CarePlan ? $context->encounter?->uuid : $context?->uuid),
        ]);

        if ($record instanceof ServiceRequestRequest) {
            return array_replace($data, [
                'quantity_system' => $activity?->quantitySystem ?: 'SERVICE_UNIT',
                'quantity_code' => $activity?->quantityCode ?: 'PIECE',
                'service_id' => $record->serviceId ?: $activity?->productReference,
            ]);
        }

        $classification = $activity !== null && empty($activity->productReference) && !empty($activity->productCodeableConcept);

        return array_replace($data, [
            'quantity_system' => $activity?->quantitySystem ?: 'device_unit',
            'quantity_code' => strtolower((string) ($activity?->quantityCode ?: 'piece')),
            'device_id' => $record->deviceId ?: ($classification ? $activity->productCodeableConcept : $activity?->productReference),
            'device_code_type' => $classification ? 'CLASSIFICATION_TYPE' : 'DEVICE_DEFINITION',
        ]);
    }

    private static function referralSignDate(mixed $value): string
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (string) $value;
    }
}
