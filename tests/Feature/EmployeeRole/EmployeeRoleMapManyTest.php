<?php

declare(strict_types=1);

namespace Tests\Feature\EmployeeRole;

use App\Classes\eHealth\Api\EmployeeRole;
use App\Enums\Status;
use App\Enums\User\Role;
use App\Models\Division;
use App\Models\Employee\Employee;
use App\Models\HealthcareService;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\Relations\Party;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

class EmployeeRoleMapManyTest extends TestCase
{
    use RefreshDatabase;

    protected function migrateDatabases(): void
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

    public function test_map_many_skips_roles_with_missing_local_models(): void
    {
        $typeId = DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        $legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);

        $party = Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Gregory',
            'last_name' => 'Doctor',
            'tax_id' => '1234567890',
            'birth_date' => '1970-01-01',
            'gender' => 'MALE',
        ]);

        $employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Dr. House',
            'employee_type' => Role::DOCTOR->value,
            'status' => Status::APPROVED->value,
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => 'Doctor',
            'start_date' => now()->format('Y-m-d'),
            'party_id' => $party->id,
        ]);

        $division = Division::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Main Division',
            'type' => 'CLINIC',
            'status' => 'ACTIVE',
            'email' => 'division@example.com',
            'mountain_group' => false,
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
        ]);

        $category = CodeableConcept::create();

        $healthcareService = HealthcareService::create([
            'uuid' => (string) Str::uuid(),
            'division_id' => $division->id,
            'legal_entity_id' => $legalEntity->id,
            'category_id' => $category->id,
            'status' => Status::ACTIVE->value,
            'is_active' => true,
        ]);

        $missingEmployeeUuid = (string) Str::uuid();
        $missingServiceUuid = (string) Str::uuid();

        $validated = [
            [
                'employee_id' => $employee->uuid,
                'healthcare_service_id' => $healthcareService->uuid,
            ],
            [
                'employee_id' => $missingEmployeeUuid,
                'healthcare_service_id' => $healthcareService->uuid,
            ],
            [
                'employee_id' => $employee->uuid,
                'healthcare_service_id' => $missingServiceUuid,
            ],
        ];

        $api = new EmployeeRole();
        $method = new ReflectionMethod(EmployeeRole::class, 'mapMany');
        $mapped = $method->invoke($api, $validated);

        $this->assertCount(1, $mapped);
        $this->assertSame($employee->id, $mapped[0]['employee_id']);
        $this->assertSame($healthcareService->id, $mapped[0]['healthcare_service_id']);
    }
}
