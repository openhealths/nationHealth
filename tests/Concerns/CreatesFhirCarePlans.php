<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Enums\EmployeeRole\Status as EmployeeRoleStatus;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\Division;
use App\Models\EmployeeRole;
use App\Models\Employee\Employee;
use App\Models\HealthcareService;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\Permission;
use App\Models\Role as RoleModel;
use App\Repositories\Repository;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Tests\Support\CarePlanWorkflowHarness;

/** Explicit normalized fixtures for tests that previously inserted retired flat columns. */
trait CreatesFhirCarePlans
{
    protected function assertFhirCarePlanStored(array $expected): void
    {
        $query = CarePlan::query();
        foreach (['id', 'uuid', 'title', 'person_id'] as $key) {
            if (array_key_exists($key, $expected)) {
                $query->where($key, $expected[$key]);
            }
        }
        $plan = $query->firstOrFail();
        $resource = Repository::carePlan()->getResource($plan->id);
        foreach ($expected as $key => $value) {
            $actual = match ($key) {
                'terms_of_service' => data_get($resource, 'termsOfService.coding.0.code'),
                'inform_with' => data_get($resource, 'informWith.authMethodId'),
                'context' => $resource['context'] ?? null,
                default => $plan->getAttribute($key),
            };
            $this->assertEquals($value, $actual, $key);
        }
    }

    protected function assertFhirActivityStored(array $expected): void
    {
        $query = CarePlanActivity::query();
        foreach (['id', 'uuid', 'care_plan_id'] as $key) {
            if (array_key_exists($key, $expected)) {
                $query->where($key, $expected[$key]);
            }
        }
        $activity = $query->firstOrFail();
        $resource = Repository::carePlanActivity()->getResource($activity->id);
        foreach ($expected as $key => $value) {
            $actual = match ($key) {
                'quantity' => data_get($resource, 'detail.quantity.value'),
                'product_reference' => data_get($resource, 'detail.productReference.identifier.value'),
                'program' => data_get($resource, 'detail.program.identifier.value'),
                default => $activity->getAttribute($key),
            };
            $this->assertEquals($value, $actual, $key);
        }
    }

    protected function createFhirCarePlan(array $attributes): CarePlan
    {
        foreach ($attributes as $key => $value) {
            if ($value instanceof CarbonInterface) {
                $attributes[$key] = $value->toIso8601String();
            }
        }

        $plan = (new CarePlanWorkflowHarness())->createCarePlanDraft(array_replace([
            'uuid' => (string) Str::uuid(), 'status' => 'draft', 'category' => 'TEST',
            'title' => 'Synthetic fixture', 'terms_of_service' => 'OUTPATIENT',
            'period_start' => now()->toDateString(),
        ], $attributes));
        $plan->update(['status' => $attributes['status'] ?? 'draft']);

        return $plan;
    }

    protected function grantCarePlanWriteApproval(CarePlan $plan, Employee $employee): void
    {
        $this->grantCarePlanEmployeeRole($employee, $plan->termsOfServiceCode());
        $identifier = Identifier::create(['value' => $employee->uuid]);
        $plan->approvals()->create([
            'uuid' => (string) Str::uuid(),
            'granted_to_id' => $identifier->id,
            'granted_to_type' => 'employee',
            'access_level' => 'write',
            'status' => 'active',
            'is_verified' => true,
            'expires_at' => now()->addYear(),
        ]);
        $plan->unsetRelation('approvals');
    }

    protected function grantCarePlanEmployeeRole(Employee $employee, string $providingCondition): void
    {
        // An employee position and token scopes alone do not grant that role in the current login.
        setPermissionsTeamId($employee->legalEntityId);
        $role = RoleModel::findOrCreate($employee->employeeType, 'web');
        $role->givePermissionTo(Permission::findOrCreate('care_plan:write', 'web'));
        $users = $employee->users()->get();
        foreach ($users as $user) {
            $user->givePermissionToParent(...$role->permissions);
            $user->assignRoleParent($role);
            $user->unsetRelation('roles');
        }

        if (EmployeeRole::whereEmployeeId($employee->id)->whereStatus(EmployeeRoleStatus::ACTIVE)
            ->whereIsActive(true)->whereHas('healthcareService', static fn ($query) => $query->whereProvidingCondition($providingCondition)->whereIsActive(true))->exists()) {
            return;
        }

        $division = Division::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Synthetic care plan division',
            'email' => 'care-plan-fixture@example.com', 'mountain_group' => false,
            'legal_entity_id' => $employee->legalEntityId, 'status' => 'ACTIVE', 'is_active' => true,
        ]);
        $service = HealthcareService::create([
            'uuid' => (string) Str::uuid(), 'division_id' => $division->id,
            'legal_entity_id' => $employee->legalEntityId, 'category_id' => CodeableConcept::create()->id,
            'providing_condition' => $providingCondition, 'speciality_type' => 'THERAPY',
            'status' => 'ACTIVE', 'is_active' => true,
        ]);
        EmployeeRole::create([
            'uuid' => (string) Str::uuid(), 'employee_id' => $employee->id,
            'healthcare_service_id' => $service->id, 'start_date' => now(),
            'status' => EmployeeRoleStatus::ACTIVE, 'is_active' => true,
            'ehealth_inserted_at' => now(), 'ehealth_updated_at' => now(),
            'ehealth_inserted_by' => (string) Str::uuid(), 'ehealth_updated_by' => (string) Str::uuid(),
        ]);
    }

    protected function createFhirCarePlanActivity(array $attributes): CarePlanActivity
    {
        if (is_string($attributes['status_reason'] ?? null)) {
            $attributes['status_reason'] = ['coding' => [['system' => 'synthetic/activity-status-reasons', 'code' => $attributes['status_reason']]]];
        }
        foreach ($attributes as $key => $value) {
            if ($value instanceof CarbonInterface) {
                $attributes[$key] = $value->toIso8601String();
            }
        }

        return (new CarePlanWorkflowHarness())->createActivityDraft(array_replace([
            'uuid' => (string) Str::uuid(), 'status' => 'draft', 'kind' => 'service_request',
        ], $attributes));
    }
}
