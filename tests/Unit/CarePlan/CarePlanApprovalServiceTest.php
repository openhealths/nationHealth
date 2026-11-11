<?php

declare(strict_types=1);

namespace Tests\Unit\CarePlan;

use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\DB;
use App\Models\Relations\Party;
use Illuminate\Support\Facades\Hash;
use App\Models\Employee\Employee;
use App\Enums\User\Role;
use App\Enums\Status;

use App\Classes\eHealth\Api\Approval as ApprovalApi;
use App\Classes\eHealth\EHealthResponse;
use App\Models\CarePlan;
use App\Models\EhealthJob;
use App\Models\EhealthLink;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Approval;
use App\Models\Person\Person;
use App\Models\User;
use App\Repositories\MedicalEvents\Repository;
use App\Services\MedicalEvents\CarePlanApprovalCreateOutcome;
use App\Services\MedicalEvents\CarePlanApprovalJobOutcome;
use App\Services\MedicalEvents\CarePlanApprovalService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use RuntimeException;

class CarePlanApprovalServiceTest extends TestCase
{
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

    public function test_create_rejects_an_unregistered_draft_before_calling_esoz(): void
    {
        [$carePlan, $legalEntity, $user] = $this->makeCarePlanContext();
        $carePlan->update(['status' => 'draft']);
        $this->mock(ApprovalApi::class)->shouldNotReceive('createApproval');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(__('care-plan.cannot_grant_unregistered'));
        app(CarePlanApprovalService::class)->create($carePlan, 'patient-uuid', 'employee-uuid', 'write', null, $legalEntity, $user);
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

    public function test_skips_patient_otp_for_inpatient_plan_at_same_legal_entity(): void
    {
        $legalEntity = new LegalEntity();
        $legalEntity->id = 10;

        $inpatient = new CarePlan([
            'legal_entity_id' => 10,
            'terms_of_service' => 'INPATIENT',
        ]);
        $outpatient = new CarePlan([
            'legal_entity_id' => 10,
            'terms_of_service' => 'OUTPATIENT',
        ]);
        $otherFacility = new CarePlan([
            'legal_entity_id' => 99,
            'terms_of_service' => 'INPATIENT',
        ]);

        $service = app(CarePlanApprovalService::class);

        $this->assertTrue($service->skipsPatientOtp($inpatient, $legalEntity));
        $this->assertFalse($service->skipsPatientOtp($outpatient, $legalEntity));
        $this->assertFalse($service->skipsPatientOtp($otherFacility, $legalEntity));
    }

    public function test_does_not_skip_patient_otp_when_plan_organization_is_unknown(): void
    {
        $legalEntity = new LegalEntity();
        $legalEntity->id = 10;

        $newInpatient = new CarePlan([
            'terms_of_service' => 'INPATIENT',
        ]);
        $newOutpatient = new CarePlan([
            'terms_of_service' => 'OUTPATIENT',
        ]);

        $service = app(CarePlanApprovalService::class);

        $this->assertFalse($service->skipsPatientOtp($newInpatient, $legalEntity));
        $this->assertFalse($service->skipsPatientOtp($newOutpatient, $legalEntity));
    }

    public function test_skips_patient_otp_reads_coded_terms_of_service(): void
    {
        $legalEntity = new LegalEntity();
        $legalEntity->id = 10;

        $carePlan = new CarePlan([
            'legal_entity_id' => 10,
            'terms_of_service' => [
                'coding' => [['code' => 'INPATIENT']],
            ],
        ]);

        $this->assertTrue(app(CarePlanApprovalService::class)->skipsPatientOtp($carePlan, $legalEntity));
    }

    public function test_build_create_payload_omits_authorize_with_for_inpatient_same_org(): void
    {
        $legalEntity = new LegalEntity();
        $legalEntity->id = 10;
        $this->instance('legalEntity', $legalEntity);

        $carePlan = new CarePlan([
            'uuid' => 'care-plan-uuid',
            'legal_entity_id' => 10,
            'terms_of_service' => 'INPATIENT',
        ]);

        $payload = app(CarePlanApprovalService::class)->buildCreatePayload(
            $carePlan,
            'employee-uuid',
            'write',
            'auth-method-uuid',
            $legalEntity,
        );

        $this->assertArrayNotHasKey('authorize_with', $payload);
    }

    public function test_create_returns_granted_for_inpatient_even_when_otp_is_in_response(): void
    {
        [$carePlan, $legalEntity, $user] = $this->makeCarePlanContext();
        $carePlan->update(['terms_of_service' => 'INPATIENT']);

        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('successful')->andReturnTrue();
        $response->shouldReceive('getStatusCode')->andReturn(201);
        $response->shouldReceive('validate')->andReturn([
            'id' => '11111111-1111-1111-1111-111111111111',
            'expires_at' => now()->addDay()->toIso8601String(), 'is_verified' => true,
            'status' => 'active',
            'urgent' => [
                'authentication_method_current' => ['type' => 'OTP'],
            ],
        ]);

        $api = Mockery::mock(ApprovalApi::class);
        $api->shouldReceive('withToken')->andReturnSelf();
        $api->shouldReceive('createApproval')
            ->once()
            ->with($carePlan->person->uuid, Mockery::on(function (array $payload): bool {
                return !array_key_exists('authorize_with', $payload);
            }))
            ->andReturn($response);
        $this->instance(ApprovalApi::class, $api);

        $result = app(CarePlanApprovalService::class)->create(
            carePlan: $carePlan->fresh(),
            patientUuid: $carePlan->person->uuid,
            employeeUuid: $carePlan->author->uuid,
            accessLevel: 'write',
            authorizeWith: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            legalEntity: $legalEntity,
            user: $user,
            bearerToken: "synthetic-token",
        );

        $this->assertTrue($result->isGranted());
        $this->assertSame(CarePlanApprovalCreateOutcome::Granted, $result->outcome);
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

    public static function confirmationMethods(): array
    {
        return [
            'OTP' => [['type' => 'OTP'], true],
            'OFFLINE' => [['type' => 'OFFLINE'], false],
            'representative OTP' => [['type' => 'THIRD_PERSON', 'reference' => ['type' => 'OTP']], true],
            'representative OFFLINE' => [['type' => 'THIRD_PERSON', 'reference' => ['type' => 'OFFLINE']], false],
        ];
    }

    #[DataProvider('confirmationMethods')]
    public function test_sync_creation_preserves_the_required_confirmation(array $method, bool $requiresOtp): void
    {
        [$carePlan, $legalEntity, $user] = $this->makeCarePlanContext();

        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('successful')->andReturnTrue();
        $response->shouldReceive('getStatusCode')->andReturn(201);
        $response->shouldReceive('validate')->andReturn([
            'id' => '11111111-1111-1111-1111-111111111111',
            'expires_at' => now()->addDay()->toIso8601String(), 'is_verified' => false,
            'urgent' => [
                'authentication_method_current' => $method,
            ],
        ]);

        $api = Mockery::mock(ApprovalApi::class);
        $api->shouldReceive('withToken')->andReturnSelf();
        $api->shouldReceive('createApproval')
            ->once()
            ->with($carePlan->person->uuid, Mockery::type('array'))
            ->andReturn($response);
        $this->instance(ApprovalApi::class, $api);

        $result = app(CarePlanApprovalService::class)->create(
            carePlan: $carePlan,
            patientUuid: $carePlan->person->uuid,
            employeeUuid: $carePlan->author->uuid,
            accessLevel: 'write',
            authorizeWith: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            legalEntity: $legalEntity,
            user: $user,
            bearerToken: "synthetic-token",
        );

        $this->assertTrue($result->requiresConfirmation());
        $this->assertSame($requiresOtp, $result->requiresOtp());
        $this->assertSame('11111111-1111-1111-1111-111111111111', $result->approvalId);
        $this->assertSame($requiresOtp ? CarePlanApprovalCreateOutcome::OtpRequired : CarePlanApprovalCreateOutcome::OfflineRequired, $result->outcome);
    }

    public function test_create_dispatches_async_job_and_returns_polling_link(): void
    {
        Bus::fake();

        [$carePlan, $legalEntity, $user] = $this->makeCarePlanContext();

        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('successful')->andReturnTrue();
        $response->shouldReceive('getStatusCode')->andReturn(202);
        $response->shouldReceive('validate')->andReturn([
            'id' => '22222222-2222-2222-2222-222222222222',
            'status' => 'pending',
            'links' => [['href' => '/jobs/job-1', 'entity' => 'approval']],
        ]);

        $api = Mockery::mock(ApprovalApi::class);
        $api->shouldReceive('withToken')->andReturnSelf();
        $api->shouldReceive('createApproval')->once()->andReturn($response);
        $this->instance(ApprovalApi::class, $api);

        $result = app(CarePlanApprovalService::class)->create(
            carePlan: $carePlan,
            patientUuid: $carePlan->person->uuid,
            employeeUuid: $carePlan->author->uuid,
            accessLevel: 'write',
            authorizeWith: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            legalEntity: $legalEntity,
            user: $user,
            bearerToken: 'caller-bearer-token',
        );

        $this->assertTrue($result->isAsync());
        $this->assertNull($result->approvalId);
        $this->assertNotNull($result->pollingLinkId);

        $this->assertDatabaseHas('approvals', [
            'id' => EhealthLink::findOrFail($result->pollingLinkId)->linkable->id,
            'approvable_type' => CarePlan::class,
            'approvable_id' => $carePlan->id,
        ]);

        Bus::assertBatched(function ($batch) use ($legalEntity, $user): bool {
            return $batch->jobs->count() === 1
                && $batch->options['legal_entity_id'] === $legalEntity->id
                && $batch->options['user']->is($user)
                && Crypt::decryptString($batch->options['token']) === 'caller-bearer-token';
        });
    }

    public function test_create_refuses_async_processing_without_a_bearer_token(): void
    {
        Bus::fake();

        [$carePlan, $legalEntity, $user] = $this->makeCarePlanContext();

        // A session token would silently work today; the queued job must be handed one explicitly.
        Session::put(config('ehealth.api.oauth.bearer_token'), 'session-bearer-token');

        $this->instance(ApprovalApi::class, $this->mockAsyncCreateApi());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(__('care-plan.approval_create_error'));

        try {
            app(CarePlanApprovalService::class)->create(
                carePlan: $carePlan,
                patientUuid: $carePlan->person->uuid,
                employeeUuid: $carePlan->author->uuid,
                legalEntity: $legalEntity,
                user: $user,
            );
        } finally {
            Bus::assertNothingBatched();
        }
    }

    public function test_create_refuses_async_processing_without_a_user(): void
    {
        Bus::fake();

        [$carePlan, $legalEntity, $user] = $this->makeCarePlanContext();

        // Being logged in is not the same as telling the service who to act as.
        $this->actingAs($user);

        $this->instance(ApprovalApi::class, $this->mockAsyncCreateApi());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(__('care-plan.approval_create_error'));

        try {
            app(CarePlanApprovalService::class)->create(
                carePlan: $carePlan,
                patientUuid: $carePlan->person->uuid,
                employeeUuid: $carePlan->author->uuid,
                legalEntity: $legalEntity,
                bearerToken: 'caller-bearer-token',
            );
        } finally {
            Bus::assertNothingBatched();
        }
    }

    #[DataProvider('confirmationMethods')]
    public function test_async_creation_preserves_the_required_confirmation(array $method, bool $requiresOtp): void
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
                'expires_at' => now()->addDay()->toIso8601String(), 'is_verified' => false,
                'authentication_method_current' => $method,
            ],
        ]);

        $link = EhealthLink::create([
            'linkable_type' => Approval::class,
            'linkable_id' => $approval->id,
            'ehealth_job_id' => $job->id,
            'entity' => 'approval',
            'href' => '/jobs/job-1',
        ]);

        $before = app(CarePlanApprovalService::class)->resolveAsyncJob($link->id);
        $this->assertTrue($before->requiresConfirmation());
        $this->assertSame($requiresOtp, $before->requiresOtp());
        $this->assertSame('33333333-3333-3333-3333-333333333333', $approval->fresh()->uuid);
        Repository::approval()->completeCarePlanRequest($link, $job->response_data);
        $status = app(CarePlanApprovalService::class)->resolveAsyncJob($link->id);

        $this->assertTrue($status->requiresConfirmation());
        $this->assertSame($requiresOtp, $status->requiresOtp());
        $this->assertSame('44444444-4444-4444-4444-444444444444', $status->approvalId);
        $this->assertSame($requiresOtp ? CarePlanApprovalJobOutcome::OtpRequired : CarePlanApprovalJobOutcome::OfflineRequired, $status->outcome);
        $this->assertDatabaseHas('approvals', [
            'id' => $approval->id,
            'uuid' => '44444444-4444-4444-4444-444444444444',
        ]);
    }

    public function test_resolve_async_job_rejects_an_incomplete_confirmation(): void
    {
        [$carePlan] = $this->makeCarePlanContext();

        $approval = Approval::create([
            'uuid' => '66666666-6666-6666-6666-666666666666',
            'approvable_type' => CarePlan::class,
            'approvable_id' => $carePlan->id,
            'status' => 'NEW',
        ]);

        $job = EhealthJob::create([
            'processing_method' => 'ASYNC',
            'status' => 'PROCESSED',
            'response_data' => [
                'id' => '77777777-7777-7777-7777-777777777777',
                'authentication_method_current' => ['type' => 'OTP'],
            ],
        ]);

        $link = EhealthLink::create([
            'linkable_type' => Approval::class,
            'linkable_id' => $approval->id,
            'ehealth_job_id' => $job->id,
            'entity' => 'approval',
            'href' => '/jobs/job-missing-verified',
        ]);

        $status = app(CarePlanApprovalService::class)->resolveAsyncJob($link->id);

        $this->assertTrue($status->isFailed());
        $this->assertNull($status->approvalId);
        $this->assertSame(CarePlanApprovalJobOutcome::Failed, $status->outcome);
    }

    public function test_resolve_async_job_grants_inpatient_same_org_without_otp(): void
    {
        [$carePlan, $legalEntity] = $this->makeCarePlanContext();
        $carePlan->update(['terms_of_service' => 'INPATIENT']);
        $this->instance('legalEntity', $legalEntity);

        $approval = Approval::create([
            'uuid' => '88888888-8888-8888-8888-888888888888',
            'approvable_type' => CarePlan::class,
            'approvable_id' => $carePlan->id,
            'status' => 'NEW',
        ]);

        $job = EhealthJob::create([
            'processing_method' => 'ASYNC',
            'status' => 'PROCESSED',
            'response_data' => [
                'id' => '99999999-9999-9999-9999-999999999999',
                'expires_at' => now()->addDay()->toIso8601String(), 'is_verified' => true,
                'status' => 'active',
                'authentication_method_current' => ['type' => 'OTP'],
            ],
        ]);

        $link = EhealthLink::create([
            'linkable_type' => Approval::class,
            'linkable_id' => $approval->id,
            'ehealth_job_id' => $job->id,
            'entity' => 'approval',
            'href' => '/jobs/job-inpatient',
        ]);

        Repository::approval()->completeCarePlanRequest($link, $job->response_data);
        $status = app(CarePlanApprovalService::class)->resolveAsyncJob($link->id);

        $this->assertTrue($status->isGranted());
        $this->assertSame(CarePlanApprovalJobOutcome::Granted, $status->outcome);
    }

    public function test_sms_verification_persists_a_successful_metadata_acknowledgement(): void
    {
        [$carePlan] = $this->makeCarePlanContext();
        $approval = $carePlan->approvals()->create([
            'uuid' => (string) Str::uuid(), 'status' => 'pending',
            'access_level' => 'write', 'expires_at' => now()->addDay()->toIso8601String(), 'is_verified' => false,
        ]);
        $validator = new ReflectionMethod(ApprovalApi::class, 'validateVerification');
        $response = new EHealthResponse(
            new Response(200, [], '{"data":{},"meta":{"code":200,"type":"object"}}'),
            fn (EHealthResponse $response): array => $validator->invoke(new ApprovalApi(), $response),
        );
        $api = Mockery::mock(ApprovalApi::class);
        $api->shouldReceive('withToken')->once()->with('synthetic-token')->andReturnSelf();
        $api->shouldReceive('verify')->once()->with($carePlan->person->uuid, $approval->uuid, ['code' => 1234])->andReturn($response);
        $details = Mockery::mock(EHealthResponse::class);
        $details->shouldReceive('successful')->once()->andReturnTrue();
        $details->shouldReceive('validate')->once()->andReturn([
            'id' => $approval->uuid, 'is_verified' => true, 'expires_at' => now()->addYear()->toIso8601String(),
        ]);
        $api->shouldReceive('getApprovalDetails')->once()->with($carePlan->person->uuid, $approval->uuid)->andReturn($details);
        $this->instance(ApprovalApi::class, $api);

        app(CarePlanApprovalService::class)->verify($carePlan->person->uuid, $approval->uuid, 1234, 'synthetic-token');

        $this->assertTrue($approval->fresh()->getAttribute('is_verified'));
        $this->assertSame('pending', $approval->fresh()->status);
        $this->assertTrue($approval->fresh()->expiresAt->isFuture());
    }

    public function test_verification_acknowledgement_cannot_promote_an_unverified_remote_approval(): void
    {
        [$plan] = $this->makeCarePlanContext();
        $approval = $plan->approvals()->create([
            'uuid' => (string) Str::uuid(), 'is_verified' => false,
            'access_level' => 'write', 'expires_at' => now()->addDay(),
        ]);
        $acknowledgement = Mockery::mock(EHealthResponse::class);
        $acknowledgement->shouldReceive('successful')->once()->andReturnTrue();
        $acknowledgement->shouldReceive('validate')->once()->andReturn([]);
        $details = Mockery::mock(EHealthResponse::class);
        $details->shouldReceive('successful')->once()->andReturnTrue();
        $details->shouldReceive('validate')->once()->andReturn([
            'id' => $approval->uuid, 'is_verified' => false,
            'expires_at' => now()->addDay()->toIso8601String(),
        ]);
        $api = $this->mock(ApprovalApi::class);
        $api->shouldReceive('verify')->once()->with($plan->person->uuid, $approval->uuid, ['code' => 1234])->andReturn($acknowledgement);
        $api->shouldReceive('getApprovalDetails')->once()->with($plan->person->uuid, $approval->uuid)->andReturn($details);

        try {
            app(CarePlanApprovalService::class)->verify($plan->person->uuid, $approval->uuid, 1234);
            $this->fail('An acknowledgement must not grant unverified access.');
        } catch (RuntimeException $exception) {
            $this->assertSame(__('care-plan.approval_verify_error'), $exception->getMessage());
        }
        $this->assertFalse($approval->fresh()->getAttribute('is_verified'));
        $this->assertNull($approval->fresh()->status);
    }

    public function test_consumed_sms_code_is_reconciled_by_reading_the_same_approval(): void
    {
        [$carePlan] = $this->makeCarePlanContext();
        $approval = $carePlan->approvals()->create([
            'uuid' => (string) Str::uuid(), 'status' => 'pending',
            'access_level' => 'write', 'expires_at' => now()->addDay()->toIso8601String(), 'is_verified' => false,
        ]);
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('successful')->once()->andReturnTrue();
        $response->shouldReceive('validate')->once()->andThrow(ValidationException::withMessages(['body' => 'Unknown acknowledgement']));
        $details = Mockery::mock(EHealthResponse::class);
        $details->shouldReceive('successful')->once()->andReturnTrue();
        $details->shouldReceive('validate')->once()->andReturn([
            'id' => $approval->uuid, 'is_verified' => true,
            'access_level' => 'write', 'expires_at' => now()->addYear()->toIso8601String(),
        ]);
        $api = Mockery::mock(ApprovalApi::class);
        $api->shouldReceive('verify')->once()->with($carePlan->person->uuid, $approval->uuid, ['code' => 1234])->andReturn($response);
        $api->shouldReceive('getApprovalDetails')->once()->with($carePlan->person->uuid, $approval->uuid)->andReturn($details);
        $this->instance(ApprovalApi::class, $api);

        app(CarePlanApprovalService::class)->verify($carePlan->person->uuid, $approval->uuid, 1234);

        $this->assertTrue($approval->fresh()->getAttribute('is_verified'));
        $this->assertSame($approval->uuid, $approval->fresh()->uuid);
    }

    private function mockAsyncCreateApi(): ApprovalApi
    {
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('successful')->andReturnTrue();
        $response->shouldReceive('getStatusCode')->andReturn(202);
        $response->shouldReceive('validate')->andReturn([
            'id' => '55555555-5555-5555-5555-555555555555',
            'links' => [['href' => '/jobs/job-2', 'entity' => 'approval']],
        ]);

        $api = Mockery::mock(ApprovalApi::class);
        $api->shouldReceive('withToken')->andReturnSelf();
        $api->shouldNotReceive('createApproval');

        return $api;
    }

    public function test_a_different_method_resumes_an_inflight_request_without_creating_a_duplicate(): void
    {
        [$plan, $legalEntity, $user] = $this->makeCarePlanContext();
        $employeeUuid = $plan->author->uuid;
        $firstMethod = (string) Str::uuid();
        $secondMethod = (string) Str::uuid();
        $link = Repository::approval()->beginCarePlanRequest(
            $plan,
            app(CarePlanApprovalService::class)->buildCreatePayload($plan, $employeeUuid, 'write', $firstMethod),
        );
        $this->mock(ApprovalApi::class)->shouldNotReceive('createApproval', 'verify', 'getApprovalDetails');
        Bus::fake();

        $result = app(CarePlanApprovalService::class)->create($plan, $plan->person->uuid, $employeeUuid, 'write', $secondMethod, $legalEntity, $user, 'synthetic-token');

        $this->assertTrue($result->isAsync());
        $this->assertSame($link->id, $result->pollingLinkId);
        $this->assertSame($firstMethod, $link->linkable->fresh()->authorizeWith);
        $this->assertDatabaseCount('approvals', 1);
        Bus::assertNothingBatched();
    }

    public static function completedRequestMethods(): array
    {
        return [
            'same known method' => [true, true],
            'different known method' => [true, false],
            'unknown previous method' => [false, false],
        ];
    }

    #[DataProvider('completedRequestMethods')]
    public function test_completed_request_checks_esoz_before_reusing_or_changing_the_method(bool $knownMethod, bool $sameMethod): void
    {
        [$plan, $legalEntity, $user] = $this->makeCarePlanContext();
        $employeeUuid = $plan->author->uuid;
        $firstMethod = $knownMethod ? (string) Str::uuid() : null;
        $selectedMethod = $sameMethod ? $firstMethod : (string) Str::uuid();
        $service = app(CarePlanApprovalService::class);
        $link = Repository::approval()->beginCarePlanRequest(
            $plan,
            $service->buildCreatePayload($plan, $employeeUuid, 'write', $firstMethod),
        );
        $existingData = [
            'id' => (string) Str::uuid(),
            'status' => 'pending',
            'is_verified' => false,
            'expires_at' => now()->addDay()->toIso8601String(),
            'authentication_method_current' => ['type' => 'OFFLINE'],
        ];
        Repository::approval()->completeCarePlanRequest($link, $existingData);
        $details = Mockery::mock(EHealthResponse::class);
        $details->shouldReceive('successful')->once()->andReturnTrue();
        $details->shouldReceive('validate')->once()->andReturn($existingData);
        $api = $this->mock(ApprovalApi::class);
        $api->shouldReceive('withToken')->with('synthetic-token')->andReturnSelf();
        $api->shouldReceive('getApprovalDetails')->once()->with($plan->person->uuid, $existingData['id'])->andReturn($details);
        $api->shouldNotReceive('verify');
        $newApprovalId = (string) Str::uuid();
        if ($sameMethod) {
            $api->shouldNotReceive('createApproval');
        } else {
            $created = Mockery::mock(EHealthResponse::class);
            $created->shouldReceive('successful')->once()->andReturnTrue();
            $created->shouldReceive('getStatusCode')->once()->andReturn(201);
            $created->shouldReceive('validate')->once()->andReturn([
                'id' => $newApprovalId,
                'status' => 'pending',
                'expires_at' => now()->addDay()->toIso8601String(), 'is_verified' => false,
                'authentication_method_current' => ['type' => 'OTP', 'phone_number' => '+380991112233'],
            ]);
            $api->shouldReceive('createApproval')->once()
                ->with($plan->person->uuid, $service->buildCreatePayload($plan, $employeeUuid, 'write', $selectedMethod))
                ->andReturn($created);
        }
        Bus::fake();

        $result = $service->create($plan, $plan->person->uuid, $employeeUuid, 'write', $selectedMethod, $legalEntity, $user, 'synthetic-token');

        $this->assertTrue($result->requiresConfirmation());
        $this->assertSame(!$sameMethod, $result->requiresOtp());
        $this->assertSame($sameMethod ? $existingData['id'] : $newApprovalId, $result->approvalId);
        $this->assertSame($firstMethod, $link->linkable->fresh()->authorizeWith);
        $this->assertDatabaseCount('approvals', $sameMethod ? 1 : 2);
        Bus::assertNothingBatched();
    }

    /**
     * @return array{0: CarePlan, 1: LegalEntity, 2: User}
     */
    private function makeCarePlanContext(): array
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
            'first_name' => 'Doctor',
            'last_name' => 'Who',
            'tax_id' => (string) random_int(1000000000, 9999999999),
            'birth_date' => '1970-01-01',
            'gender' => 'MALE',
        ]);

        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'doctor-'.Str::random(8).'@example.com',
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Dr. Test',
            'employee_type' => Role::DOCTOR->value,
            'status' => Status::APPROVED->value,
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
            'status' => 'new',
        ]);

        return [$carePlan, $legalEntity, $user];
    }
}
