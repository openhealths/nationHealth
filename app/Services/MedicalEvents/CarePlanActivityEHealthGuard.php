<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents;

use App\Classes\eHealth\EHealth;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\Employee\Employee;
use Illuminate\Support\Facades\Auth;
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

    /**
     * @return array{employee_id: int|null, division_id: int|null, employee_uuid: string|null, legal_entity_uuid: string|null}
     */
    public function resolveEmployeeContext(CarePlan $carePlan, ?CarePlanActivity $activity = null, ?int $fallbackEmployeeId = null): array
    {
        $employee = null;

        $carePlan->loadMissing('encounter.performer');
        $performerUuid = $carePlan->encounter?->performer?->value;
        if (is_string($performerUuid) && $performerUuid !== '') {
            $employee = Employee::query()->where('uuid', $performerUuid)->first();
        }

        if (!$employee && $activity?->author_id) {
            $employee = Employee::find($activity->author_id);
        }

        if (!$employee && $fallbackEmployeeId) {
            $employee = Employee::find($fallbackEmployeeId);
        }

        if (!$employee) {
            $employee = Auth::user()?->activeDoctorEmployee();
        }

        return [
            'employee_id' => $employee?->id,
            'division_id' => $employee?->division_id ?? $carePlan->encounter?->division_id,
            'employee_uuid' => $employee?->uuid,
            'legal_entity_uuid' => $employee?->legalEntity?->uuid,
        ];
    }
}
