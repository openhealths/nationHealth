<?php

declare(strict_types=1);

namespace Tests\Unit\CarePlan;

use App\Classes\eHealth\Api\Approval as ApprovalApi;
use App\Classes\eHealth\EHealthResponse;
use App\Models\CarePlan;
use App\Models\EhealthJob;
use App\Models\EhealthLink;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Approval;
use App\Models\Person\Person;
use App\Models\User;
use App\Services\MedicalEvents\CarePlanApprovalCreateOutcome;
use App\Services\MedicalEvents\CarePlanApprovalJobOutcome;
use App\Services\MedicalEvents\CarePlanApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class CarePlanApprovalServiceTest extends TestCase
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

    public function test_build_create_payload_for_care_plan_employee_grant(): void
    {
        $carePlan = new CarePlan(['uuid' => 'care-plan-uuid']);
        $payload = app(CarePlanApprovalService::class)->buildCreatePayload(
            $carePlan,
            'employee-uuid',
            'write',
            'auth-method-uuid',
        );

        $this->assertSame('care_plan', $payload['resources'][0]['identifier']['type']['coding'][0]['code']);
        $this->assertSame('care-plan-uuid', $payload['resources'][0]['identifier']['value']);
        $this->assertSame('employee', $payload['granted_to']['identifier']['type']['coding'][0]['code']);
        $this->assertSame('employee-uuid', $payload['granted_to']['identifier']['value']);
        $this->assertSame('write', $payload['access_level']);
        $this->assertSame('auth-method-uuid', $payload['authorize_with']);
    }

    public function test_build_create_payload_omits_empty_authorize_with(): void
    {
        $carePlan = new CarePlan(['uuid' => 'care-plan-uuid']);
        $payload = app(CarePlanApprovalService::class)->buildCreatePayload(
            $carePlan,
            'employee-uuid',
            'read',
            null,
        );

        $this->assertArrayNotHasKey('authorize_with', $payload);
        $this->assertSame('read', $payload['access_level']);
    }

    public function test_resolve_access_level_write_for_same_legal_entity(): void
    {
        $legalEntity = new LegalEntity();
        $legalEntity->id = 10;

        $carePlan = new CarePlan(['legal_entity_id' => 10]);

        $this->assertSame(
            'write',
            app(CarePlanApprovalService::class)->resolveAccessLevel($carePlan, $legalEntity)
        );
    }

    public function test_resolve_access_level_read_for_other_legal_entity(): void
    {
        $legalEntity = new LegalEntity();
        $legalEntity->id = 10;

        $carePlan = new CarePlan(['legal_entity_id' => 99]);

        $this->assertSame(
            'read',
            app(CarePlanApprovalService::class)->resolveAccessLevel($carePlan, $legalEntity)
        );
    }

    public function test_create_returns_otp_required_for_sync_otp_response(): void
    {
        [$carePlan, $legalEntity, $user] = $this->makeCarePlanContext();

        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('getStatusCode')->andReturn(201);
        $response->shouldReceive('getData')->andReturn([
            'id' => '11111111-1111-1111-1111-111111111111',
            'urgent' => [
                'authentication_method_current' => ['type' => 'OTP'],
            ],
        ]);

        $api = Mockery::mock(ApprovalApi::class);
        $api->shouldReceive('createApproval')
            ->once()
            ->with('patient-uuid', Mockery::type('array'))
            ->andReturn($response);
        $this->instance(ApprovalApi::class, $api);

        $result = app(CarePlanApprovalService::class)->create(
            carePlan: $carePlan,
            patientUuid: 'patient-uuid',
            employeeUuid: 'employee-uuid',
            accessLevel: 'write',
            authorizeWith: 'otp-uuid',
            legalEntity: $legalEntity,
            user: $user,
        );

        $this->assertTrue($result->requiresOtp());
        $this->assertSame('11111111-1111-1111-1111-111111111111', $result->approvalId);
        $this->assertSame(CarePlanApprovalCreateOutcome::OtpRequired, $result->outcome);
    }

    public function test_create_dispatches_async_job_and_returns_polling_link(): void
    {
        Bus::fake();

        [$carePlan, $legalEntity, $user] = $this->makeCarePlanContext();

        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('getStatusCode')->andReturn(202);
        $response->shouldReceive('getData')->andReturn([
            'id' => '22222222-2222-2222-2222-222222222222',
            'status' => 'pending',
            'links' => [['href' => '/jobs/job-1', 'entity' => 'approval']],
        ]);

        $api = Mockery::mock(ApprovalApi::class);
        $api->shouldReceive('createApproval')->once()->andReturn($response);
        $this->instance(ApprovalApi::class, $api);

        $result = app(CarePlanApprovalService::class)->create(
            carePlan: $carePlan,
            patientUuid: 'patient-uuid',
            employeeUuid: '77777777-7777-7777-7777-777777777777',
            accessLevel: 'write',
            authorizeWith: 'otp-uuid',
            legalEntity: $legalEntity,
            user: $user,
        );

        $this->assertTrue($result->isAsync());
        $this->assertSame('22222222-2222-2222-2222-222222222222', $result->approvalId);
        $this->assertNotNull($result->pollingLinkId);

        $this->assertDatabaseHas('approvals', [
            'uuid' => '22222222-2222-2222-2222-222222222222',
            'approvable_type' => CarePlan::class,
            'approvable_id' => $carePlan->id,
        ]);

        Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 1);
    }

    public function test_resolve_async_job_swaps_uuid_and_requests_otp_when_unverified(): void
    {
        [$carePlan] = $this->makeCarePlanContext();

        $approval = Approval::create([
            'uuid' => '33333333-3333-3333-3333-333333333333',
            'approvable_type' => CarePlan::class,
            'approvable_id' => $carePlan->id,
            'status' => 'NEW',
        ]);

        $job = EhealthJob::create([
            'processing_method' => 'ASYNC',
            'status' => 'PROCESSED',
            'response_data' => [
                'id' => '44444444-4444-4444-4444-444444444444',
                'is_verified' => false,
                'authentication_method_current' => ['type' => 'OTP'],
            ],
        ]);

        $link = EhealthLink::create([
            'linkable_type' => Approval::class,
            'linkable_id' => $approval->id,
            'ehealth_job_id' => $job->id,
            'entity' => 'approval',
            'href' => '/jobs/job-1',
        ]);

        $status = app(CarePlanApprovalService::class)->resolveAsyncJob($link->id);

        $this->assertTrue($status->requiresOtp());
        $this->assertSame('44444444-4444-4444-4444-444444444444', $status->approvalId);
        $this->assertSame(CarePlanApprovalJobOutcome::OtpRequired, $status->outcome);
        $this->assertDatabaseHas('approvals', [
            'id' => $approval->id,
            'uuid' => '44444444-4444-4444-4444-444444444444',
        ]);
    }

    /**
     * @return array{0: CarePlan, 1: LegalEntity, 2: User}
     */
    private function makeCarePlanContext(): array
    {
        $typeId = \Illuminate\Support\Facades\DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? \Illuminate\Support\Facades\DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        $legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);

        $party = \App\Models\Relations\Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Doctor',
            'last_name' => 'Who',
            'tax_id' => (string) random_int(1000000000, 9999999999),
            'birth_date' => '1970-01-01',
            'gender' => 'MALE',
        ]);

        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'doctor-'.Str::random(8).'@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $employee = \App\Models\Employee\Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Dr. Test',
            'employee_type' => \App\Enums\User\Role::DOCTOR->value,
            'status' => \App\Enums\Status::APPROVED->value,
            'legal_entity_id' => $legalEntity->id,
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

        $carePlan = CarePlan::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $person->id,
            'author_id' => $employee->id,
            'legal_entity_id' => $legalEntity->id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Test Care Plan',
            'status' => 'draft',
        ]);

        return [$carePlan, $legalEntity, $user];
    }
}
