<?php

declare(strict_types=1);

namespace Tests\Feature\CarePlan;

use Tests\Concerns\CreatesFhirCarePlans;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Enums\User\Role;
use App\Enums\Status;
use Illuminate\Support\Facades\Cache;
use App\Services\Dictionary\Dictionaries\BasicDictionary;
use App\Classes\eHealth\Api\Person as ApiPerson;
use App\Classes\eHealth\EHealthResponse;
use App\Classes\eHealth\Api\Approval;
use App\Livewire\CarePlan\CarePlanApprovals;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\MedicalEvents\Sql\Approval as SqlApproval;

use App\Models\CarePlan;
use App\Models\LegalEntity;
use App\Models\Employee\Employee;
use App\Models\Relations\Party;
use App\Models\User;
use App\Models\Person\Person;
use App\Services\MedicalEvents\CarePlanApprovalService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;
use Mockery;
use Illuminate\Support\Str;

class CarePlanApprovalsTest extends TestCase
{
    use CreatesFhirCarePlans;

    use DatabaseTransactions;

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
        $typeId = DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

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
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        // Employee belonging to active legal entity
        $employeeActive = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Dr. Active',
            'employee_type' => Role::DOCTOR->value,
            'status' => Status::APPROVED->value,
            'legal_entity_id' => $activeLegalEntity->id,
            'is_active' => true,
            'position' => 'Doctor',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $user->id,
            'party_id' => $party->id,
        ]);

        Cache::forever(BasicDictionary::KEY, [
            ['name' => 'POSITION', 'values' => [['code' => 'P8', 'description' => 'Лікар-педіатр']]],
        ]);
        Cache::forever(BasicDictionary::KEY.':fresh', true);
        $employeeActive->update(['position' => 'P8']);

        // Employee belonging to other legal entity
        $employeeOther = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Dr. Other',
            'employee_type' => Role::DOCTOR->value,
            'status' => Status::APPROVED->value,
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

        // Care plan of the current legal entity (another LE's employees still exist in DB).
        $carePlan = $this->createFhirCarePlan([
            'uuid' => (string) Str::uuid(),
            'person_id' => $person->id,
            'author_id' => $employeeActive->id,
            'legal_entity_id' => $activeLegalEntity->id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'External Care Plan',
            'status' => 'new',
        ]);

        // Mock dependencies for Livewire mount
        $mockPatientApi = Mockery::mock(ApiPerson::class);
        $this->instance(ApiPerson::class, $mockPatientApi);

        $authResponse = Mockery::mock(EHealthResponse::class);
        $authResponse->shouldReceive('getData', 'validate')->andReturn([
            [
                'id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                'type' => 'OTP',
                'phone_number' => '+380991112233'
            ]
        ]);
        $authResponse->shouldReceive('getStatusCode')->andReturn(200);
        $mockPatientApi->shouldReceive('getAuthMethods')->andReturn($authResponse);

        $mockApprovalApi = Mockery::mock(Approval::class);
        $mockApprovalApi->shouldReceive('withToken')->andReturnSelf();
        $this->instance(Approval::class, $mockApprovalApi);

        $approvalsResponse = Mockery::mock(EHealthResponse::class);
        $approvalsResponse->shouldReceive('getData', 'validate')->andReturn([]);
        $approvalsResponse->shouldReceive('getStatusCode')->andReturn(200);
        $mockApprovalApi->shouldReceive('getPatientApprovals')->andReturn($approvalsResponse);

        $this->actingAs($user);
        $this->grantMedicalEventAbilities($user);

        // Test Livewire mount - verify employees lists ONLY employees of activeLegalEntity
        $component = Livewire::test(CarePlanApprovals::class, [
            'legalEntity' => $activeLegalEntity,
            'carePlan' => $carePlan,
        ]);

        $employees = $component->get('employees');

        $this->assertCount(1, $employees);
        $this->assertEquals($employeeActive->uuid, $employees[0]['uuid']);
        $this->assertSame($employeeActive->fullName.' (Лікар-педіатр)', $employees[0]['label']);
        $component->assertSee('Лікар-педіатр')->assertDontSee('(DOCTOR)')->assertDontSee('(Approvals)');
    }

    public function test_create_approval_uses_write_access_level_for_same_legal_entity(): void
    {
        $typeId = DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

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
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $employeeActive = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Dr. Active',
            'employee_type' => Role::DOCTOR->value,
            'status' => Status::APPROVED->value,
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
        $carePlan = $this->createFhirCarePlan([
            'uuid' => (string) Str::uuid(),
            'person_id' => $person->id,
            'author_id' => $employeeActive->id,
            'legal_entity_id' => $activeLegalEntity->id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Internal Care Plan',
            'status' => 'new',
        ]);

        // Mock dependencies
        $mockPatientApi = Mockery::mock(ApiPerson::class);
        $this->instance(ApiPerson::class, $mockPatientApi);

        $authResponse = Mockery::mock(EHealthResponse::class);
        $authResponse->shouldReceive('getData', 'validate')->andReturn([
            [
                'id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                'type' => 'OTP',
                'phone_number' => '+380991112233'
            ]
        ]);
        $authResponse->shouldReceive('getStatusCode')->andReturn(200);
        $mockPatientApi->shouldReceive('getAuthMethods')->andReturn($authResponse);

        $mockApprovalApi = Mockery::mock(Approval::class);
        $mockApprovalApi->shouldReceive('withToken')->andReturnSelf();
        $this->instance(Approval::class, $mockApprovalApi);

        $approvalsResponse = Mockery::mock(EHealthResponse::class);
        $approvalsResponse->shouldReceive('getData', 'validate')->andReturn([]);
        $approvalsResponse->shouldReceive('getStatusCode')->andReturn(200);
        $mockApprovalApi->shouldReceive('getPatientApprovals')->andReturn($approvalsResponse);

        session()->put(config('ehealth.api.oauth.bearer_token'), 'synthetic-token');

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
            'authorize_with' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        ];

        $approvalCreateResponse = Mockery::mock(EHealthResponse::class);
        $approvalCreateResponse->shouldReceive('getData', 'validate')->andReturn(['id' => (string) Str::uuid(), 'is_verified' => false, 'expires_at' => now()->addDay()->toIso8601String(), 'authentication_method_current' => ['type' => 'OTP']]);
        $approvalCreateResponse->shouldReceive('successful')->andReturnTrue();
        $approvalCreateResponse->shouldReceive('getStatusCode')->andReturn(201);

        $mockApprovalApi->shouldReceive('createApproval')
            ->once()
            ->with($person->uuid, $expectedPayload)
            ->andReturn($approvalCreateResponse);

        $this->actingAs($user);
        $this->grantMedicalEventAbilities($user);

        Livewire::test(CarePlanApprovals::class, [
            'legalEntity' => $activeLegalEntity,
            'carePlan' => $carePlan,
        ])
            ->set('newApproval.employee_uuid', $employeeActive->uuid)
            ->call('createApproval');
    }

    public function test_create_approval_uses_read_access_level_for_external_legal_entity(): void
    {
        $typeId = DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

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
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $employeeActive = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Dr. Active',
            'employee_type' => Role::DOCTOR->value,
            'status' => Status::APPROVED->value,
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
        $carePlan = $this->createFhirCarePlan([
            'uuid' => (string) Str::uuid(),
            'person_id' => $person->id,
            'author_id' => $employeeActive->id,
            'legal_entity_id' => $otherLegalEntity->id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'External Care Plan',
            'status' => 'new',
        ]);

        $this->actingAs($user);

        $this->assertTrue(Gate::forUser($user)->denies('view', $carePlan));
        $this->assertSame(
            'read',
            app(CarePlanApprovalService::class)
                ->resolveAccessLevel($carePlan, $activeLegalEntity)
        );
    }

    #[DataProvider('approvalVisibility')]
    public function test_granted_and_awaiting_approvals_are_visible(?string $status, bool $visible, bool $verified, bool $awaiting, bool $expired = false): void
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
        $this->instance('legalEntity', $legalEntity);

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
            'email' => 'revoke_'.Str::random(6).'@example.com',
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Dr. Active',
            'employee_type' => Role::DOCTOR->value,
            'status' => Status::APPROVED->value,
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => 'Doctor',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $user->id,
            'party_id' => $party->id,
        ]);
        $user->employees()->attach($employee->id);

        $person = Person::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'John',
            'last_name' => 'Doe',
            'birth_date' => '1990-01-01',
            'gender' => 'MALE',
            'patient_signed' => true,
            'process_disclosure_data_consent' => true,
        ]);

        $carePlan = $this->createFhirCarePlan([
            'uuid' => (string) Str::uuid(),
            'person_id' => $person->id,
            'author_id' => $employee->id,
            'legal_entity_id' => $legalEntity->id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Pending revoke plan',
            'status' => 'active',
            'terms_of_service' => 'INPATIENT',
        ]);

        $identifier = Identifier::create(['value' => $employee->uuid]);
        SqlApproval::create([
            'uuid' => (string) Str::uuid(),
            'approvable_type' => CarePlan::class,
            'approvable_id' => $carePlan->id,
            'granted_to_id' => $identifier->id,
            'granted_to_type' => 'employee',
            'status' => $status,
            'is_verified' => $verified,
            'expires_at' => $expired ? now()->subSecond() : now()->addDay(),
        ]);

        $approvalService = Mockery::mock(CarePlanApprovalService::class)->makePartial();
        $approvalService->shouldReceive('syncForCarePlan')->andReturnNull();
        $approvalService->shouldReceive('skipsPatientOtp')->andReturn(true);
        $this->instance(CarePlanApprovalService::class, $approvalService);

        $mockPatientApi = Mockery::mock(ApiPerson::class);
        $this->instance(ApiPerson::class, $mockPatientApi);
        $authResponse = Mockery::mock(EHealthResponse::class);
        $authResponse->shouldReceive('getData', 'validate')->andReturn([]);
        $mockPatientApi->shouldReceive('getAuthMethods')->andReturn($authResponse);

        $this->actingAs($user);
        $this->grantMedicalEventAbilities($user);

        $component = Livewire::test(CarePlanApprovals::class, [
            'legalEntity' => $legalEntity,
            'carePlan' => $carePlan->fresh(),
        ]);
        $this->assertFalse(array_key_exists('status', $component->get('approvals')[0] ?? []));

        if ($visible) {
            $component->assertSeeHtml('wire:click="cancelApproval')
                ->assertDontSee(__('care-plan.no_approvals_found'));
            if ($awaiting) {
                $component->assertSeeHtml('wire:click="verifyExistingApproval')
                    ->assertSeeHtml('wire:click="recreateApproval');
            } else {
                $component->assertDontSeeHtml('wire:click="verifyExistingApproval')
                    ->assertDontSeeHtml('wire:click="recreateApproval');
            }
        } else {
            $component->assertDontSeeHtml('wire:click="cancelApproval')
                ->assertSee(__('care-plan.no_approvals_found'));
        }
    }

    public static function approvalVisibility(): array
    {
        return [
            'active' => ['active', true, true, false],
            'legacy approved' => ['APPROVED', true, true, false],
            'active awaiting confirmation' => ['active', true, false, true],
            'legacy approved awaiting confirmation' => ['APPROVED', true, false, true],
            'pending' => ['pending', true, false, true],
            'new' => ['NEW', true, false, true],
            'inactive legacy value ignored' => ['inactive', true, false, true],
            'no local status' => [null, true, true, false],
            'expired verified' => ['active', false, true, false, true],
            'expired pending' => ['pending', false, false, false, true],
            'terminated legacy value ignored' => ['terminated', true, true, false],
        ];
    }
}
