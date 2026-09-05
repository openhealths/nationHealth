<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents\Concerns;

use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\Division;
use App\Models\Employee\Employee;
use App\Models\MedicalEvents\Sql\Encounter;

/**
 * Resolves which employee an eHealth request is attributed to.
 *
 * The acting employee is never read from the session: callers pass $actingEmployeeId so the
 * same resolution works from HTTP, queued jobs and tests.
 */
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
    public function resolveEmployeeContext(CarePlan $carePlan, ?CarePlanActivity $activity = null, ?int $actingEmployeeId = null): array
    {
        $carePlan->loadMissing(['encounter.performer', 'encounter.division']);

        $employee = $this->resolveEmployeeByUuid($carePlan->encounter?->performer?->value);

        if (!$employee && $activity?->author_id) {
            $employee = Employee::find($activity->author_id);
        }

        if (!$employee && $actingEmployeeId) {
            $employee = Employee::find($actingEmployeeId);
        }

        return $this->buildEmployeeContext($employee, $carePlan->encounter);
    }

    /**
     * @return array{
     *     employee_id: int|null,
     *     division_id: int|null,
     *     employee_uuid: string|null,
     *     legal_entity_uuid: string|null
     * }
     */
    public function resolveEncounterEmployeeContext(Encounter $encounter, ?int $actingEmployeeId = null): array
    {
        $employee = $this->resolveEmployeeByUuid($encounter->performer?->value);

        if (!$employee && $actingEmployeeId) {
            $employee = Employee::find($actingEmployeeId);
        }

        return $this->buildEmployeeContext($employee, $encounter);
    }

    private function resolveEmployeeByUuid(?string $uuid): ?Employee
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }

        return Employee::query()->where('uuid', $uuid)->first();
    }

    /**
     * @return array{
     *     employee_id: int|null,
     *     division_id: int|null,
     *     employee_uuid: string|null,
     *     legal_entity_uuid: string|null
     * }
     */
    private function buildEmployeeContext(?Employee $employee, ?Encounter $encounter): array
    {
        return [
            'employee_id' => $employee?->id,
            'division_id' => $employee?->division_id ?? $this->resolveEncounterDivisionId($encounter),
            'employee_uuid' => $employee?->uuid,
            'legal_entity_uuid' => $employee?->legalEntity?->uuid,
        ];
    }

    /**
     * `encounters.division_id` references an identifier holding the eHealth division UUID, while
     * request tables reference `divisions`. Translate before the id leaves this trait.
     */
    private function resolveEncounterDivisionId(?Encounter $encounter): ?int
    {
        $uuid = $encounter?->division?->value;

        if ($uuid === null || $uuid === '') {
            return null;
        }

        return Division::query()->where('uuid', $uuid)->value('id');
    }
}
