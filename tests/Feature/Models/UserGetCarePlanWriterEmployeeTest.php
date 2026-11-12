<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Enums\EmployeeRole\Status as EmployeeRoleStatus;
use App\Enums\Person\EncounterStatus;
use App\Enums\Status;
use App\Enums\User\Role;
use App\Models\CarePlan;
use App\Models\Division;
use App\Models\EmployeeRole;
use App\Models\Employee\Employee;
use App\Models\HealthcareService;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\Permission;
use App\Models\Person\Person;
use App\Models\Relations\Party;
use App\Models\Role as RoleModel;
use App\Models\User;
use App\Repositories\CarePlanRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Ramsey\Uuid\Uuid;
use Tests\Support\CarePlanWorkflowHarness;
use Tests\TestCase;

class UserGetCarePlanWriterEmployeeTest extends TestCase
{
    use DatabaseTransactions;

    private LegalEntity $legalEntity;

    private Division $division;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $typeId = DB::table('legal_entity_types')->where('name', 'OUTPATIENT')->value('id')
            ?? DB::table('legal_entity_types')->insertGetId(['name' => 'OUTPATIENT']);

        $this->legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);
        $this->instance('legalEntity', $this->legalEntity);

        $this->division = Division::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Main Division',
            'type' => 'CLINIC',
            'status' => 'ACTIVE',
            'email' => 'division@example.com',
            'mountain_group' => false,
            'legal_entity_id' => $this->legalEntity->id,
        ]);

        $party = Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Andriy',
            'last_name' => 'Kopylets',
            'tax_id' => '1234567890',
            'birth_date' => '1970-01-01',
            'gender' => 'MALE',
        ]);

        $this->user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'writer@example.com',
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        setPermissionsTeamId($this->legalEntity->id);
    }

    private function createEmployee(string $position, string $employeeType = 'SPECIALIST'): Employee
    {
        $employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'employee_type' => $employeeType,
            'status' => Status::APPROVED->value,
            'legal_entity_id' => $this->legalEntity->id,
            'division_id' => $this->division->id,
            'is_active' => true,
            'position' => $position,
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $this->user->id,
            'party_id' => $this->user->party_id,
        ]);
        $this->user->employees()->attach($employee->id);
        $permission = Permission::findOrCreate('care_plan:write', 'web');
        $role = RoleModel::findOrCreate($employeeType, 'web');
        $role->givePermissionTo($permission);
        $this->user->givePermissionToParent(...$role->permissions);
        $this->user->assignRoleParent($role);
        $this->user->unsetRelation('roles');

        return $employee;
    }

    private function createHealthcareService(string $specialityType, string $providingCondition): HealthcareService
    {
        $categoryId = CodeableConcept::create()->id;

        return HealthcareService::create([
            'uuid' => (string) Str::uuid(),
            'division_id' => $this->division->id,
            'legal_entity_id' => $this->legalEntity->id,
            'speciality_type' => $specialityType,
            'providing_condition' => $providingCondition,
            'status' => 'ACTIVE',
            'category_id' => $categoryId,
            'is_active' => true,
        ]);
    }

    private function createActiveRole(Employee $employee, HealthcareService $healthcareService): EmployeeRole
    {
        return EmployeeRole::create([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $employee->id,
            'healthcare_service_id' => $healthcareService->id,
            'start_date' => now(),
            'status' => EmployeeRoleStatus::ACTIVE,
            'is_active' => true,
            'ehealth_inserted_at' => now(),
            'ehealth_inserted_by' => (string) Str::uuid(),
            'ehealth_updated_at' => now(),
            'ehealth_updated_by' => (string) Str::uuid(),
        ]);
    }

    public function test_without_terms_of_service_it_returns_first_employee_by_role_priority(): void
    {
        $doctor = $this->createEmployee('P1', Role::DOCTOR->value);
        $this->createEmployee('P2', Role::SPECIALIST->value);

        $result = $this->user->getCarePlanWriterEmployee();

        $this->assertNotNull($result);
        $this->assertSame($doctor->id, $result->id);
    }

    public function test_it_prefers_employee_with_active_role_matching_terms_of_service(): void
    {
        // First (by role priority) employee has no matching role - simulates the therapist
        // who happens to be listed first but isn't set up for the requested terms_of_service.
        $therapist = $this->createEmployee('P10', Role::SPECIALIST->value);
        $endocrinologist = $this->createEmployee('P56', Role::SPECIALIST->value);

        $healthcareService = $this->createHealthcareService('ENDOCRINOLOGY', 'INPATIENT');
        $this->createActiveRole($endocrinologist, $healthcareService);

        $result = $this->user->getCarePlanWriterEmployee('INPATIENT');

        $this->assertNotNull($result);
        $this->assertSame($endocrinologist->id, $result->id);
        $this->assertNotSame($therapist->id, $result->id);
    }

    public function test_it_rejects_when_no_employee_matches_terms_of_service(): void
    {
        $doctor = $this->createEmployee('P1', Role::DOCTOR->value);

        $healthcareService = $this->createHealthcareService('ENDOCRINOLOGY', 'INPATIENT');
        $this->createActiveRole($doctor, $healthcareService);

        // A writer without the required healthcare-service role must not be substituted.
        $result = $this->user->getCarePlanWriterEmployee('OUTPATIENT');

        $this->assertNull($result);
    }

    public function test_it_ignores_inactive_roles_when_matching_terms_of_service(): void
    {
        $doctor = $this->createEmployee('P1', Role::DOCTOR->value);
        $endocrinologist = $this->createEmployee('P56', Role::SPECIALIST->value);

        $healthcareService = $this->createHealthcareService('ENDOCRINOLOGY', 'INPATIENT');
        $role = $this->createActiveRole($endocrinologist, $healthcareService);
        $role->update(['status' => EmployeeRoleStatus::INACTIVE]);

        $result = $this->user->getCarePlanWriterEmployee('INPATIENT');

        $this->assertNull($result);
    }

    public function test_medical_coordinator_can_write_with_a_matching_active_role(): void
    {
        $employee = $this->createEmployee('P1', Role::MED_COORDINATOR->value);
        $this->createActiveRole($employee, $this->createHealthcareService('THERAPY', 'OUTPATIENT'));

        $this->assertSame($employee->id, $this->user->getCarePlanWriterEmployee('OUTPATIENT', $employee->uuid)?->id);
    }

    public function test_selected_encounter_author_is_never_replaced_by_another_matching_employee(): void
    {
        $other = $this->createEmployee('P1', Role::DOCTOR->value);
        $author = $this->createEmployee('P2', Role::SPECIALIST->value);
        $service = $this->createHealthcareService('THERAPY', 'OUTPATIENT');
        $this->createActiveRole($other, $service);
        $this->createActiveRole($author, $service);

        $this->assertSame($author->id, $this->user->getCarePlanWriterEmployee('OUTPATIENT', $author->uuid)?->id);
        $this->assertNull($this->user->getCarePlanWriterEmployee('OUTPATIENT', (string) Str::uuid()));
        $author->update(['is_active' => false]);
        $this->assertNull($this->user->getCarePlanWriterEmployee('OUTPATIENT', $author->uuid));
    }

    public function test_unlinked_employee_of_the_same_party_is_not_a_writer(): void
    {
        $author = $this->createEmployee('P1', Role::DOCTOR->value);
        $this->createActiveRole($author, $this->createHealthcareService('THERAPY', 'OUTPATIENT'));
        $this->user->employees()->detach($author->id);
        $author->update(['user_id' => null]);

        $this->assertNull($this->user->getCarePlanWriterEmployee('OUTPATIENT', $author->uuid));
    }
    public function test_future_employee_start_date_is_not_an_active_care_plan_writer(): void
    {
        $employee = $this->createEmployee('P103', 'MED_COORDINATOR');
        $employee->update(['start_date' => now()->addDay()->toDateString()]);
        $this->assertNull($this->user->getCarePlanWriterEmployee());
    }

    public function test_a_linked_position_and_direct_scope_do_not_replace_an_unassigned_login_role(): void
    {
        $employee = $this->createEmployee('P103', 'MED_COORDINATOR');
        $service = $this->createHealthcareService('SPECIALITY', 'OUTPATIENT');
        $this->createActiveRole($employee, $service);
        $this->user->roles()->detach();
        $user = $this->user->fresh();
        $this->assertTrue($user->permissions()->whereName('care_plan:write')->exists());
        $this->assertNull($user->getCarePlanWriterEmployee('OUTPATIENT', $employee->uuid));
    }

    public function test_creation_author_is_the_selected_finished_encounter_performer(): void
    {
        $this->createEmployee('P1', 'DOCTOR');
        $author = $this->createEmployee('P103', 'MED_COORDINATOR');
        $service = $this->createHealthcareService('SPECIALITY', 'OUTPATIENT');
        $this->createActiveRole($author, $service);
        $this->actingAs($this->user);
        $encounter = new Encounter(['uuid' => (string) Str::uuid(), 'status' => EncounterStatus::FINISHED->value]);
        $encounter->setRelation('performer', new Identifier(['value' => $author->uuid]));
        $encounter->setRelation('class', null);
        $repository = Mockery::mock(CarePlanRepository::class, [new CarePlan()])->makePartial();
        $repository->shouldReceive('findEncounter')->with($encounter->uuid, 1)->andReturn($encounter);
        $this->assertSame($author->uuid, $repository->creationAuthorForEncounter($encounter->uuid, 1)->uuid);
        $author->update(['is_active' => false]);
        $this->expectException(ValidationException::class);
        $repository->creationAuthorForEncounter($encounter->uuid, 1);
    }

    public function test_draft_clinical_ids_are_v1_and_persist_after_reopening(): void
    {
        $person = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'FEMALE', 'birth_date' => '1990-01-01', 'status' => 'ACTIVE']);
        $author = $this->createEmployee('P1', 'DOCTOR');
        $plan = (new CarePlanWorkflowHarness())->saveCarePlanDraft([
            'title' => 'Synthetic plan', 'status' => 'draft', 'category' => 'TEST',
            'termsOfService' => 'OUTPATIENT', 'author' => $author->uuid,
            'periodStart' => '2026-11-11', 'periodEnd' => '2026-11-12',
        ], $person->id, $this->legalEntity->id);
        $this->assertSame(1, Uuid::fromString($plan->uuid)->getFields()->getVersion());
        $reopened = (new CarePlanWorkflowHarness())->saveCarePlanDraft(['title' => 'Changed title'], $person->id, $this->legalEntity->id, $plan->id);
        $this->assertSame($plan->uuid, $reopened->uuid);
        $activity = (new CarePlanWorkflowHarness())->saveActivityDraft([
            'kind' => 'service_request', 'status' => 'draft', 'authorId' => $author->id,
            'productReference' => (string) Str::uuid(), 'quantity' => 2,
            'scheduledPeriodStart' => '2026-11-11', 'scheduledPeriodEnd' => '2026-11-12',
        ], $plan);
        $this->assertSame(1, Uuid::fromString($activity->uuid)->getFields()->getVersion());
        $updated = (new CarePlanWorkflowHarness())->saveActivityDraft(['quantity' => 3], $plan, $activity->id);
        $this->assertSame($activity->uuid, $updated->uuid);
    }

}
