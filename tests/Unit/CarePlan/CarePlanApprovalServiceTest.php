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
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

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

        $this->expectException(\Illuminate\Validation\ValidationException::class);
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
            'is_verified' => true,
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

    public function test_create_returns_otp_required_for_sync_otp_response(): void
    {
        [$carePlan, $legalEntity, $user] = $this->makeCarePlanContext();

        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('successful')->andReturnTrue();
        $response->shouldReceive('getStatusCode')->andReturn(201);
        $response->shouldReceive('validate')->andReturn([
            'id' => '11111111-1111-1111-1111-111111111111',
            'is_verified' => false,
            'urgent' => [
                'authentication_method_current' => ['type' => 'OTP'],
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

        $this->assertTrue($result->requiresOtp());
        $this->assertSame('11111111-1111-1111-1111-111111111111', $result->approvalId);
        $this->assertSame(CarePlanApprovalCreateOutcome::OtpRequired, $result->outcome);
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

        $this->expectException(\RuntimeException::class);
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

        $this->expectException(\RuntimeException::class);
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

        $before = app(CarePlanApprovalService::class)->resolveAsyncJob($link->id);
        $this->assertTrue($before->requiresOtp());
        $this->assertSame('33333333-3333-3333-3333-333333333333', $approval->fresh()->uuid);
        \App\Repositories\MedicalEvents\Repository::approval()->completeCarePlanRequest($link, $job->response_data);
        $status = app(CarePlanApprovalService::class)->resolveAsyncJob($link->id);

        $this->assertTrue($status->requiresOtp());
        $this->assertSame('44444444-4444-4444-4444-444444444444', $status->approvalId);
        $this->assertSame(CarePlanApprovalJobOutcome::OtpRequired, $status->outcome);
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
                'is_verified' => true,
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

        \App\Repositories\MedicalEvents\Repository::approval()->completeCarePlanRequest($link, $job->response_data);
        $status = app(CarePlanApprovalService::class)->resolveAsyncJob($link->id);

        $this->assertTrue($status->isGranted());
        $this->assertSame(CarePlanApprovalJobOutcome::Granted, $status->outcome);
    }

    public function test_sms_verification_persists_a_successful_metadata_acknowledgement(): void
    {
        [$carePlan] = $this->makeCarePlanContext();
        $approval = $carePlan->approvals()->create([
            'uuid' => (string) Str::uuid(), 'status' => 'pending',
            'access_level' => 'write', 'is_verified' => false,
        ]);
        $validator = new \ReflectionMethod(ApprovalApi::class, 'validateVerification');
        $response = new EHealthResponse(
            new \GuzzleHttp\Psr7\Response(200, [], '{"data":{},"meta":{"code":200,"type":"object"}}'),
            fn (EHealthResponse $response): array => $validator->invoke(new ApprovalApi(), $response),
        );
        $api = Mockery::mock(ApprovalApi::class);
        $api->shouldReceive('withToken')->once()->with('synthetic-token')->andReturnSelf();
        $api->shouldReceive('verify')->once()->with($carePlan->person->uuid, $approval->uuid, ['code' => 1234])->andReturn($response);
        $api->shouldNotReceive('getApprovalDetails');
        $this->instance(ApprovalApi::class, $api);

        app(CarePlanApprovalService::class)->verify($carePlan->person->uuid, $approval->uuid, 1234, 'synthetic-token');

        $this->assertTrue($approval->fresh()->getAttribute('is_verified'));
        $this->assertSame('active', $approval->fresh()->status);
    }

    public function test_consumed_sms_code_is_reconciled_by_reading_the_same_approval(): void
    {
        [$carePlan] = $this->makeCarePlanContext();
        $approval = $carePlan->approvals()->create([
            'uuid' => (string) Str::uuid(), 'status' => 'pending',
            'access_level' => 'write', 'is_verified' => false,
        ]);
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('successful')->once()->andReturnTrue();
        $response->shouldReceive('validate')->once()->andThrow(\Illuminate\Validation\ValidationException::withMessages(['body' => 'Unknown acknowledgement']));
        $details = Mockery::mock(EHealthResponse::class);
        $details->shouldReceive('successful')->once()->andReturnTrue();
        $details->shouldReceive('validate')->once()->andReturn([
            'id' => $approval->uuid, 'status' => 'active', 'is_verified' => true,
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

    public function test_sync_cancellation_stays_inactive_after_an_older_creation_result(): void
    {
        [$plan, $legalEntity, $user, $approval] = $this->cancellationContext();
        $data = ['id' => $approval->uuid, 'is_verified' => true];
        $this->cancellationApi($plan, $approval, 201, ['data' => $data]);
        $result = app(CarePlanApprovalService::class)->requestCancellation($plan, $approval->uuid, $legalEntity, $user, 'test-token');
        $this->assertSame('processed', $result['status']);
        $this->assertSame('inactive', $approval->fresh()->status);
        $this->assertTrue($approval->fresh()->getAttribute('is_verified'));

        $repository = \App\Repositories\MedicalEvents\Repository::approval();
        $oldLink = $repository->attachEhealthLink($approval, ['href' => '/jobs/old-create']);
        $employeeUuid = (string) Str::uuid();
        $identifier = \App\Models\MedicalEvents\Sql\Identifier::create(['value' => $employeeUuid]);
        $approval->update(['granted_to_id' => $identifier->id]);
        $this->assertNull($repository->reusableForCarePlan($plan, $employeeUuid, 'write'));
        $repository->completeCarePlanRequest($oldLink, $data + ['status' => 'active']);
        $this->assertSame('inactive', $approval->fresh()->status);
        $again = app(CarePlanApprovalService::class)->requestCancellation($plan, $approval->uuid, $legalEntity, $user, 'test-token');
        $this->assertSame($result, $again);
    }

    public function test_async_cancellation_does_not_revoke_until_the_remote_job_completes(): void
    {
        Bus::fake();
        [$plan, $legalEntity, $user, $approval] = $this->cancellationContext();
        $this->cancellationApi($plan, $approval, 202, ['data' => ['status' => 'pending', 'links' => [['href' => '/Jobs/remote-job']]]]);
        $service = app(CarePlanApprovalService::class);
        $result = $service->requestCancellation($plan, $approval->uuid, $legalEntity, $user, 'test-token');
        $this->assertSame('pending', $result['status']);
        $this->assertSame('active', $approval->fresh()->status);
        $this->assertSame($result, $service->requestCancellation($plan, $approval->uuid, $legalEntity, $user, 'test-token'));
        Bus::assertBatchCount(2);
        $link = EhealthLink::findOrFail($result['linkId']);
        $this->assertNull(\App\Repositories\MedicalEvents\Repository::approval()->creationLinkFor($approval));
        $worker = new \App\Jobs\RemoteEHealthLinksProcessing($link, $legalEntity, standalone: true);
        $this->assertSame('job:read', (new \ReflectionMethod($worker, 'requiredScope'))->invoke($worker));
        $payload = ['status' => 'processed', 'status_code' => 201, 'response_data' => ['id' => $approval->uuid, 'is_verified' => true]];
        $response = new EHealthResponse(new \GuzzleHttp\Psr7\Response(200), fn () => $payload);
        (new \ReflectionMethod($worker, 'processResponse'))->invoke($worker, $response);
        $this->assertSame('inactive', $approval->fresh()->status);
        $this->assertSame('PROCESSED', $link->fresh()->status);
        // Duplicate delivery and a later queue failure cannot undo confirmed cancellation.
        (new \ReflectionMethod($worker, 'processResponse'))->invoke($worker, $response);
        $worker->failed(new \RuntimeException('late failure'));
        $this->assertSame('PROCESSED', $link->fresh()->status);
        $this->assertNull($link->fresh()->error);
    }

    public function test_failed_cancellation_job_preserves_the_existing_permission(): void
    {
        [$plan, $legalEntity, , $approval] = $this->cancellationContext();
        $repository = \App\Repositories\MedicalEvents\Repository::approval();
        $link = $repository->beginCarePlanCancellation($plan, $approval->uuid);
        $worker = new \App\Jobs\RemoteEHealthLinksProcessing($link, $legalEntity, standalone: true);
        $response = new EHealthResponse(new \GuzzleHttp\Psr7\Response(200), fn () => ['status' => 'failed', 'status_code' => 403]);
        (new \ReflectionMethod($worker, 'processResponse'))->invoke($worker, $response);
        $this->assertSame('active', $approval->fresh()->status);
        $this->assertSame('FAILED', $link->fresh()->status);
    }

    public function test_cancellation_timeout_is_not_retried_as_a_second_mutation(): void
    {
        [$plan, $legalEntity, $user, $approval] = $this->cancellationContext();
        $api = Mockery::mock(ApprovalApi::class);
        $api->shouldReceive('withToken')->andReturnSelf();
        $api->shouldReceive('cancelApproval')->once()->andThrow(new \App\Exceptions\EHealth\EHealthConnectionException('timeout'));
        $this->instance(ApprovalApi::class, $api);
        $service = app(CarePlanApprovalService::class);
        try {
            $service->requestCancellation($plan, $approval->uuid, $legalEntity, $user, 'test-token');
            $this->fail('The transport failure must propagate.');
        } catch (\App\Exceptions\EHealth\EHealthConnectionException) {
            $this->assertSame('active', $approval->fresh()->status);
        }
        $this->assertSame('unknown', $service->requestCancellation($plan, $approval->uuid, $legalEntity, $user, 'test-token')['status']);
        $this->assertSame(1, $approval->ehealthLinks()->count());
    }

    public function test_cancellation_rejects_a_result_for_another_approval(): void
    {
        [$plan, , , $approval] = $this->cancellationContext();
        $repository = \App\Repositories\MedicalEvents\Repository::approval();
        $link = $repository->beginCarePlanCancellation($plan, $approval->uuid);
        try {
            $repository->finishCarePlanCancellation($link, ['status' => 'processed', 'status_code' => 201,
                'response_data' => ['id' => (string) Str::uuid(), 'is_verified' => true]]);
            $this->fail('Mismatched identity must be rejected.');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->assertSame('active', $approval->fresh()->status);
            $this->assertSame('PENDING', $link->fresh()->status);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cancellationErrors')]
    public function test_cancellation_http_errors_preserve_access_and_do_not_queue(int $status, string $operationStatus): void
    {
        Bus::fake();
        [$plan, $legalEntity, $user, $approval] = $this->cancellationContext();
        $this->cancellationApi($plan, $approval, $status, ['error' => ['message' => 'Rejected']]);
        try {
            app(CarePlanApprovalService::class)->requestCancellation($plan, $approval->uuid, $legalEntity, $user, 'test-token');
            $this->fail('HTTP failure must propagate.');
        } catch (\App\Exceptions\EHealth\EHealthResponseException $exception) {
            $this->assertSame($status, $exception->getCode());
        }
        $this->assertSame('active', $approval->fresh()->status);
        $this->assertSame($operationStatus, $approval->ehealthLinks()->sole()->status);
        Bus::assertNothingBatched();
    }

    public static function cancellationErrors(): array
    {
        return [[403, 'FAILED'], [404, 'FAILED'], [422, 'FAILED'], [503, 'PENDING']];
    }

    public function test_polling_failure_can_resume_the_same_job_without_cancelling_again(): void
    {
        Bus::fake();
        [$plan, $legalEntity, $user, $approval] = $this->cancellationContext();
        $this->cancellationApi($plan, $approval, 202, ['data' => ['links' => [['href' => '/api/jobs/remote-job']]]]);
        $service = app(CarePlanApprovalService::class);
        $result = $service->requestCancellation($plan, $approval->uuid, $legalEntity, $user, 'test-token');
        $link = EhealthLink::findOrFail($result['linkId']);
        (new \App\Jobs\RemoteEHealthLinksProcessing($link, $legalEntity, standalone: true))->failed(new \RuntimeException('polling timeout'));
        $state = \App\Repositories\MedicalEvents\Repository::approval()->carePlanCancellationState($plan, $approval->uuid, $link->id);
        $this->assertSame('unknown', $state['status']);
        $this->assertSame('active', $approval->fresh()->status);
        $this->assertSame($result, $service->requestCancellation($plan, $approval->uuid, $legalEntity, $user, 'test-token'));
        $this->assertNull($link->fresh()->error);
        $this->assertSame('/api/jobs/remote-job', $link->fresh()->href);
        Bus::assertBatchCount(2);
    }

    public function test_cancel_receipt_without_a_job_link_remains_unknown(): void
    {
        Bus::fake();
        [$plan, $legalEntity, $user, $approval] = $this->cancellationContext();
        $this->cancellationApi($plan, $approval, 202, ['data' => ['status' => 'pending']]);
        $service = app(CarePlanApprovalService::class);
        try {
            $service->requestCancellation($plan, $approval->uuid, $legalEntity, $user, 'test-token');
            $this->fail('A missing job receipt is not success.');
        } catch (\RuntimeException) {
            $this->assertSame('active', $approval->fresh()->status);
        }
        $this->assertSame('unknown', $service->requestCancellation($plan, $approval->uuid, $legalEntity, $user, 'test-token')['status']);
        Bus::assertNothingBatched();
    }

    public function test_cancellation_cannot_resolve_an_approval_from_another_plan(): void
    {
        [$plan, $legalEntity, $user] = $this->cancellationContext();
        $this->mock(ApprovalApi::class)->shouldNotReceive('cancelApproval');
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(CarePlanApprovalService::class)->requestCancellation($plan, (string) Str::uuid(), $legalEntity, $user, 'test-token');
    }

    private function cancellationContext(): array
    {
        [$plan, $legalEntity, $user] = $this->makeCarePlanContext();
        $this->instance('legalEntity', $legalEntity);
        $this->grantMedicalEventAbilities($user, ['approval:cancel', 'job:read']);
        $this->actingAs($user);
        $approval = $plan->approvals()->create(['uuid' => (string) Str::uuid(), 'status' => 'active', 'is_verified' => true, 'access_level' => 'write']);

        return [$plan, $legalEntity, $user, $approval];
    }

    private function cancellationApi(CarePlan $plan, Approval $approval, int $status, array $body): void
    {
        $method = new \ReflectionMethod(ApprovalApi::class, 'validateCancellation');
        $response = new EHealthResponse(new \GuzzleHttp\Psr7\Response($status, [], json_encode($body)),
            fn (EHealthResponse $response): array => $method->invoke(new ApprovalApi(), $response));
        $api = Mockery::mock(ApprovalApi::class);
        $api->shouldReceive('withToken')->with('test-token')->andReturnSelf();
        $api->shouldReceive('cancelApproval')->once()->with($plan->person->uuid, $approval->uuid)->andReturn($response);
        $api->shouldNotReceive('verify');
        $this->instance(ApprovalApi::class, $api);
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
            'status' => 'new',
        ]);

        return [$carePlan, $legalEntity, $user];
    }
}
