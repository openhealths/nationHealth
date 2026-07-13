<?php

declare(strict_types=1);

namespace Tests\Feature\CarePlan;

use App\Models\CarePlan;
use App\Models\LegalEntity;
use App\Models\Employee\Employee;
use App\Models\Relations\Party;
use App\Models\User;
use App\Models\Person\Person;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;
use Mockery;
use Illuminate\Support\Str;

class CarePlanApprovalsTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_employees_are_filtered_by_active_legal_entity(): void
    {
        // 1. Setup initial active legal entity and user/employee
        $typeId = \Illuminate\Support\Facades\DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? \Illuminate\Support\Facades\DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        $activeLegalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);
        $this->instance('legalEntity', $activeLegalEntity);

        $otherLegalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);

        $party = Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Doctor',
            'last_name' => 'Who',
            'tax_id' => '1234567890',
            'birth_date' => '1970-01-01',
            'gender' => 'MALE',
        ]);

        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'doctor@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'party_id' => $party->id,
        ]);

        // Employee belonging to active legal entity
        $employeeActive = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Dr. Active',
            'employee_type' => \App\Enums\User\Role::DOCTOR->value,
            'status' => \App\Enums\Status::APPROVED->value,
            'legal_entity_id' => $activeLegalEntity->id,
            'is_active' => true,
            'position' => 'Doctor',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $user->id,
            'party_id' => $party->id,
        ]);

        // Employee belonging to other legal entity
        $employeeOther = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Dr. Other',
            'employee_type' => \App\Enums\User\Role::DOCTOR->value,
            'status' => \App\Enums\Status::APPROVED->value,
            'legal_entity_id' => $otherLegalEntity->id,
            'is_active' => true,
            'position' => 'Doctor',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $user->id,
            'party_id' => $party->id,
        ]);

        $person = Person::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'John',
            'last_name' => 'Doe',
            'birth_date' => '1990-01-01',
            'gender' => 'MALE',
            'patient_signed' => true,
            'process_disclosure_data_consent' => true,
        ]);

        // Care plan owned by other legal entity
        $carePlan = CarePlan::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $person->id,
            'author_id' => $employeeOther->id,
            'legal_entity_id' => $otherLegalEntity->id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'External Care Plan',
            'status' => 'draft',
        ]);

        // Mock dependencies for Livewire mount
        $mockPatientApi = Mockery::mock(\App\Classes\eHealth\Api\Person::class);
        $this->instance(\App\Classes\eHealth\Api\Person::class, $mockPatientApi);

        $authResponse = Mockery::mock(\App\Classes\eHealth\EHealthResponse::class);
        $authResponse->shouldReceive('getData')->andReturn([
            [
                'id' => 'otp-uuid',
                'type' => 'OTP',
                'phone_number' => '+380991112233'
            ]
        ]);
        $authResponse->shouldReceive('getStatusCode')->andReturn(200);
        $mockPatientApi->shouldReceive('getAuthMethods')->andReturn($authResponse);

        $mockApprovalApi = Mockery::mock(\App\Classes\eHealth\Api\Approval::class);
        $this->instance(\App\Classes\eHealth\Api\Approval::class, $mockApprovalApi);

        $approvalsResponse = Mockery::mock(\App\Classes\eHealth\EHealthResponse::class);
        $approvalsResponse->shouldReceive('getData')->andReturn([]);
        $approvalsResponse->shouldReceive('getStatusCode')->andReturn(200);
        $mockApprovalApi->shouldReceive('getMany')->andReturn($approvalsResponse);

        // Test Livewire mount - verify employees lists ONLY employees of activeLegalEntity
        $component = Livewire::test(\App\Livewire\CarePlan\CarePlanApprovals::class, [
            'legalEntity' => $activeLegalEntity,
            'carePlan' => $carePlan,
        ]);

        $employees = $component->get('employees');

        $this->assertCount(1, $employees);
        $this->assertEquals($employeeActive->uuid, $employees[0]['uuid']);
    }

    public function test_create_approval_uses_write_access_level_for_same_legal_entity(): void
    {
        $typeId = \Illuminate\Support\Facades\DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? \Illuminate\Support\Facades\DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        $activeLegalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);
        $this->instance('legalEntity', $activeLegalEntity);

        $party = Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Doctor',
            'last_name' => 'Who',
            'tax_id' => '1234567890',
            'birth_date' => '1970-01-01',
            'gender' => 'MALE',
        ]);

        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'doctor@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $employeeActive = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Dr. Active',
            'employee_type' => \App\Enums\User\Role::DOCTOR->value,
            'status' => \App\Enums\Status::APPROVED->value,
            'legal_entity_id' => $activeLegalEntity->id,
            'is_active' => true,
            'position' => 'Doctor',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $user->id,
            'party_id' => $party->id,
        ]);

        $person = Person::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'John',
            'last_name' => 'Doe',
            'birth_date' => '1990-01-01',
            'gender' => 'MALE',
            'patient_signed' => true,
            'process_disclosure_data_consent' => true,
        ]);

        // Care plan owned by active legal entity
        $carePlan = CarePlan::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $person->id,
            'author_id' => $employeeActive->id,
            'legal_entity_id' => $activeLegalEntity->id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Internal Care Plan',
            'status' => 'draft',
        ]);

        // Mock dependencies
        $mockPatientApi = Mockery::mock(\App\Classes\eHealth\Api\Person::class);
        $this->instance(\App\Classes\eHealth\Api\Person::class, $mockPatientApi);

        $authResponse = Mockery::mock(\App\Classes\eHealth\EHealthResponse::class);
        $authResponse->shouldReceive('getData')->andReturn([
            [
                'id' => 'otp-uuid',
                'type' => 'OTP',
                'phone_number' => '+380991112233'
            ]
        ]);
        $authResponse->shouldReceive('getStatusCode')->andReturn(200);
        $mockPatientApi->shouldReceive('getAuthMethods')->andReturn($authResponse);

        $mockApprovalApi = Mockery::mock(\App\Classes\eHealth\Api\Approval::class);
        $this->instance(\App\Classes\eHealth\Api\Approval::class, $mockApprovalApi);

        $approvalsResponse = Mockery::mock(\App\Classes\eHealth\EHealthResponse::class);
        $approvalsResponse->shouldReceive('getData')->andReturn([]);
        $approvalsResponse->shouldReceive('getStatusCode')->andReturn(200);
        $mockApprovalApi->shouldReceive('getMany')->andReturn($approvalsResponse);

        // Expected payload with access_level => write
        $expectedPayload = [
            'resources' => [
                [
                    'identifier' => [
                        'type' => [
                            'coding' => [['system' => 'eHealth/resources', 'code' => 'care_plan']],
                        ],
                        'value' => $carePlan->uuid,
                    ],
                ],
            ],
            'granted_to' => [
                'identifier' => [
                    'type' => [
                        'coding' => [['system' => 'eHealth/resources', 'code' => 'employee']],
                    ],
                    'value' => $employeeActive->uuid,
                ],
            ],
            'access_level' => 'write',
            'authorize_with' => 'otp-uuid',
        ];

        $approvalCreateResponse = Mockery::mock(\App\Classes\eHealth\EHealthResponse::class);
        $approvalCreateResponse->shouldReceive('getData')->andReturn(['id' => (string) Str::uuid(), 'status' => 'NEW']);
        $approvalCreateResponse->shouldReceive('getStatusCode')->andReturn(201);

        $mockApprovalApi->shouldReceive('createApproval')
            ->once()
            ->with($person->uuid, $expectedPayload)
            ->andReturn($approvalCreateResponse);

        Livewire::test(\App\Livewire\CarePlan\CarePlanApprovals::class, [
            'legalEntity' => $activeLegalEntity,
            'carePlan' => $carePlan,
        ])
            ->set('newApproval.employee_uuid', $employeeActive->uuid)
            ->call('createApproval');
    }

    public function test_create_approval_uses_read_access_level_for_external_legal_entity(): void
    {
        $typeId = \Illuminate\Support\Facades\DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? \Illuminate\Support\Facades\DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        $activeLegalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);
        $this->instance('legalEntity', $activeLegalEntity);

        $otherLegalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);

        $party = Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Doctor',
            'last_name' => 'Who',
            'tax_id' => '1234567890',
            'birth_date' => '1970-01-01',
            'gender' => 'MALE',
        ]);

        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'doctor@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $employeeActive = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Dr. Active',
            'employee_type' => \App\Enums\User\Role::DOCTOR->value,
            'status' => \App\Enums\Status::APPROVED->value,
            'legal_entity_id' => $activeLegalEntity->id,
            'is_active' => true,
            'position' => 'Doctor',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $user->id,
            'party_id' => $party->id,
        ]);

        $person = Person::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'John',
            'last_name' => 'Doe',
            'birth_date' => '1990-01-01',
            'gender' => 'MALE',
            'patient_signed' => true,
            'process_disclosure_data_consent' => true,
        ]);

        // Care plan owned by other legal entity
        $carePlan = CarePlan::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $person->id,
            'author_id' => $employeeActive->id,
            'legal_entity_id' => $otherLegalEntity->id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'External Care Plan',
            'status' => 'draft',
        ]);

        // Mock dependencies
        $mockPatientApi = Mockery::mock(\App\Classes\eHealth\Api\Person::class);
        $this->instance(\App\Classes\eHealth\Api\Person::class, $mockPatientApi);

        $authResponse = Mockery::mock(\App\Classes\eHealth\EHealthResponse::class);
        $authResponse->shouldReceive('getData')->andReturn([
            [
                'id' => 'otp-uuid',
                'type' => 'OTP',
                'phone_number' => '+380991112233'
            ]
        ]);
        $authResponse->shouldReceive('getStatusCode')->andReturn(200);
        $mockPatientApi->shouldReceive('getAuthMethods')->andReturn($authResponse);

        $mockApprovalApi = Mockery::mock(\App\Classes\eHealth\Api\Approval::class);
        $this->instance(\App\Classes\eHealth\Api\Approval::class, $mockApprovalApi);

        $approvalsResponse = Mockery::mock(\App\Classes\eHealth\EHealthResponse::class);
        $approvalsResponse->shouldReceive('getData')->andReturn([]);
        $approvalsResponse->shouldReceive('getStatusCode')->andReturn(200);
        $mockApprovalApi->shouldReceive('getMany')->andReturn($approvalsResponse);

        // Expected payload with access_level => read
        $expectedPayload = [
            'resources' => [
                [
                    'identifier' => [
                        'type' => [
                            'coding' => [['system' => 'eHealth/resources', 'code' => 'care_plan']],
                        ],
                        'value' => $carePlan->uuid,
                    ],
                ],
            ],
            'granted_to' => [
                'identifier' => [
                    'type' => [
                        'coding' => [['system' => 'eHealth/resources', 'code' => 'employee']],
                    ],
                    'value' => $employeeActive->uuid,
                ],
            ],
            'access_level' => 'read',
            'authorize_with' => 'otp-uuid',
        ];

        $approvalCreateResponse = Mockery::mock(\App\Classes\eHealth\EHealthResponse::class);
        $approvalCreateResponse->shouldReceive('getData')->andReturn(['id' => (string) Str::uuid(), 'status' => 'NEW']);
        $approvalCreateResponse->shouldReceive('getStatusCode')->andReturn(201);

        $mockApprovalApi->shouldReceive('createApproval')
            ->once()
            ->with($person->uuid, $expectedPayload)
            ->andReturn($approvalCreateResponse);

        Livewire::test(\App\Livewire\CarePlan\CarePlanApprovals::class, [
            'legalEntity' => $activeLegalEntity,
            'carePlan' => $carePlan,
        ])
            ->set('newApproval.employee_uuid', $employeeActive->uuid)
            ->call('createApproval');
    }
}
