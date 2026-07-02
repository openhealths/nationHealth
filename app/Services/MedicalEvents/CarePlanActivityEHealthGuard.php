<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents;

use App\Classes\eHealth\EHealth;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use Illuminate\Support\Facades\Log;

class CarePlanActivityEHealthGuard
{
    public function assertRegisteredInEHealth(CarePlan $carePlan, CarePlanActivity $activity): void
    {
        $personUuid = $carePlan->person?->uuid;
        $carePlanUuid = $carePlan->uuid;
        $activityUuid = $activity->uuid;

        if (!$personUuid || !$carePlanUuid || !$activityUuid) {
            throw new \RuntimeException(__('care-plan.activity_ehealth_missing_identifiers'));
        }

        try {
            $response = EHealth::carePlanActivity()->getDetails($personUuid, $carePlanUuid, $activityUuid);
            if (!$response->successful()) {
                throw new \RuntimeException(__('care-plan.activity_not_in_ehealth'));
            }
        } catch (EHealthResponseException $exception) {
            Log::warning('CarePlanActivityEHealthGuard: activity not found in eHealth', [
                'activity_uuid' => $activityUuid,
                'care_plan_uuid' => $carePlanUuid,
                'message' => $exception->getMessage(),
            ]);

            throw new \RuntimeException(__('care-plan.activity_not_in_ehealth'));
        }
    }
}
