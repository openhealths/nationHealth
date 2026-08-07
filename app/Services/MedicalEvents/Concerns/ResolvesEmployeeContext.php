<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents\Concerns;

use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\Employee\Employee;
use Illuminate\Support\Facades\Auth;

trait ResolvesEmployeeContext
{
    /**
     * @return array{
     *     employee_id: int|null,
     *     division_id: int|null,
     *     employee_uuid: string|null,
     *     legal_entity_uuid: string|null
     * }
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

    /**
     * @return array{
     *     employee_id: int|null,
     *     division_id: int|null,
     *     employee_uuid: string|null,
     *     legal_entity_uuid: string|null
     * }
     */
    public function resolveEncounterEmployeeContext(\App\Models\MedicalEvents\Sql\Encounter $encounter, ?int $fallbackEmployeeId = null): array
    {
        $employee = null;

        $performerUuid = $encounter->performer?->value;
        if (is_string($performerUuid) && $performerUuid !== '') {
            $employee = Employee::query()->where('uuid', $performerUuid)->first();
        }

        if (!$employee && $fallbackEmployeeId) {
            $employee = Employee::find($fallbackEmployeeId);
        }

        if (!$employee) {
            $employee = Auth::user()?->activeDoctorEmployee();
        }

        return [
            'employee_id' => $employee?->id,
            'division_id' => $employee?->division_id ?? $encounter->division_id,
            'employee_uuid' => $employee?->uuid,
            'legal_entity_uuid' => $employee?->legalEntity?->uuid,
        ];
    }
}
