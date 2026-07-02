<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Enums\EmployeeRole\Status as EmployeeRoleStatus;
use App\Enums\Status;
use App\Enums\User\Role;
use App\Models\Division;
use App\Models\Employee\Employee;
use App\Models\EmployeeRole;
use App\Models\HealthcareService;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\Relations\Party;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserGetCarePlanWriterEmployeeTest extends TestCase
{
    use RefreshDatabase;

    private LegalEntity $legalEntity;

    private Division $division;

    private User $user;

    protected function migrateDatabases()
    {
        $this->artisan('migrate:fresh', [
            '--path' => [
                database_path('migrations'),
                database_path('migrations/install'),
                database_path('migrations/update/0_1'),
            ],
            '--realpath' => true,
        ]);
    }

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

    public function test_it_falls_back_to_role_priority_when_no_employee_matches_terms_of_service(): void
    {
        $doctor = $this->createEmployee('P1', Role::DOCTOR->value);

        $healthcareService = $this->createHealthcareService('ENDOCRINOLOGY', 'INPATIENT');
        $this->createActiveRole($doctor, $healthcareService);

        // Requesting OUTPATIENT while the only role available is INPATIENT: no exact match,
        // must still fall back to a candidate instead of returning null.
        $result = $this->user->getCarePlanWriterEmployee('OUTPATIENT');

        $this->assertNotNull($result);
        $this->assertSame($doctor->id, $result->id);
    }

    public function test_it_ignores_inactive_roles_when_matching_terms_of_service(): void
    {
        $therapist = $this->createEmployee('P10', Role::SPECIALIST->value);
        $endocrinologist = $this->createEmployee('P56', Role::SPECIALIST->value);

        $healthcareService = $this->createHealthcareService('ENDOCRINOLOGY', 'INPATIENT');
        $role = $this->createActiveRole($endocrinologist, $healthcareService);
        $role->update(['status' => EmployeeRoleStatus::INACTIVE]);

        $result = $this->user->getCarePlanWriterEmployee('INPATIENT');

        $this->assertNotNull($result);
        $this->assertSame($therapist->id, $result->id);
    }
}
