<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\MedicalEvents\Activity;

use App\Models\CarePlanActivity;
use App\Repositories\CarePlanRepository;

/** Prepare model relations and dictionary context for the pure activity DTOs. */
trait MapsCarePlanActivityPayload
{
    private function normalizeUnitCode(?string $system, ?string $code): ?string
    {
        if (empty($code)) {
            return null;
        }
        if (empty($system)) {
            return $code;
        }

        try {
            $res = dictionary()->basics()->getMultipleFormatted([$system])->toArray();
            $dict = $res[$system] ?? null;
            if ($dict && is_array($dict)) {
                foreach (array_keys($dict) as $key) {
                    if (strcasecmp((string)$key, $code) === 0) {
                        return (string)$key;
                    }
                }
            }
        } catch (\Exception $e) {
            // fallback
        }

        return $code;
    }

    private function getDeviceRequestAllowedCodeTypes(?string $programId): array
    {
        if (empty($programId)) {
            return [];
        }

        try {
            $program = dictionary()->medicalPrograms()->firstWhere('id', $programId);
            $types = $program['medical_program_settings']['device_request_allowed_code_types'] ?? [];

            return is_array($types) ? $types : [];
        } catch (\Exception) {
            return [];
        }
    }

    /** Prepare relations and dictionary metadata outside the mapper; DTOs own the signed contract. */
    protected function buildCarePlanActivityPayload(CarePlanActivity $activity): array
    {
        $activity->loadMissing(['author', 'quantityQuantity', 'dailyAmountQuantity', 'scheduledPeriod', 'carePlan']);
        $quantity = $activity->quantityQuantity;
        $dailyAmount = $activity->dailyAmountQuantity;
        $quantitySystem = $quantity ? $quantity->system : $activity->quantity_system;
        $quantityCode = $quantity ? $quantity->code : $activity->quantity_code;
        $dailySystem = $dailyAmount ? $dailyAmount->system : ($activity->daily_amount_system ?? $quantitySystem);
        $dailyCode = $dailyAmount ? $dailyAmount->code : ($activity->daily_amount_code ?? $quantityCode);
        $period = $activity->scheduledPeriod;
        $start = $period ? $period->getRawOriginal('start') : $activity->scheduled_period_start;
        $planStart = !empty($start) && !($activity->uuid && $period) && $activity->carePlan
            ? app(CarePlanRepository::class)->resolveEHealthPeriodBounds($activity->carePlan)['start'] : null;
        $allowedTypes = str_contains(strtolower((string) $activity->kind), 'device')
            ? $this->getDeviceRequestAllowedCodeTypes($activity->program) : [];
        $mapper = app(\Symfony\Component\ObjectMapper\ObjectMapperInterface::class);
        $detail = $mapper->map($activity, new \App\Dto\CarePlanActivity\EhealthDetail(
            $this->normalizeUnitCode($quantitySystem, $quantityCode),
            $this->normalizeUnitCode($dailySystem, $dailyCode),
            $allowedTypes,
            now(),
            $planStart,
        ));

        return $mapper->map($activity, new \App\Dto\CarePlanActivity\Ehealth($detail))->toArray();
    }

}
