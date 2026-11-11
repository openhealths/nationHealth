<?php

declare(strict_types=1);

namespace Tests\Unit\CarePlan;

use Illuminate\Validation\ValidationException;
use RuntimeException;

use App\Classes\eHealth\Api\Approval;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Models\CarePlan;
use App\Models\MedicalEvents\Sql\Approval as ApprovalModel;
use App\Models\Person\Person as Patient;
use App\Services\MedicalEvents\CarePlanApprovalService;
use App\Services\MedicalEvents\PatientApprovalService;
use GuzzleHttp\Psr7\Response;
use Mockery;
use Tests\TestCase;
use App\Classes\eHealth\Api\Person;
use App\Enums\Person\AuthenticationMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;

class ApprovalResponseContractTest extends TestCase
{
    public function test_inpatient_confirmation_rejects_a_non_success_response(): void
    {
        $api = Mockery::mock(Approval::class);
        $api->shouldReceive('verify')->once()->with('patient', 'approval', [])
            ->andReturn(new EHealthResponse(new Response(403)));
        $this->instance(Approval::class, $api);

        $this->expectException(EHealthResponseException::class);
        app(CarePlanApprovalService::class)->confirmWithoutOtp('patient', 'approval');
    }

    public function test_cancellation_propagates_an_esoz_error_without_updating_the_approval(): void
    {
        $patient = new Patient();
        $patient->uuid = 'patient';
        $plan = new CarePlan();
        $plan->setRelation('person', $patient);
        $approval = Mockery::mock(ApprovalModel::class)->makePartial();
        $approval->uuid = 'approval';
        $approval->setRelation('approvable', $plan);
        $approval->shouldNotReceive('update');

        $api = Mockery::mock(Approval::class);
        $api->shouldNotReceive('verify');
        $api->shouldReceive('cancelApproval')->once()->with('patient', 'approval')
            ->andThrow(new EHealthResponseException(new EHealthResponse(new Response(503))));
        $this->instance(Approval::class, $api);

        $this->expectException(EHealthResponseException::class);
        app(PatientApprovalService::class)->cancel($approval);
    }

    public function test_patient_approval_url_and_filters_are_preserved(): void
    {
        $filters = ['granted_resource_type' => 'care_plan', 'granted_resources' => 'plan'];
        $response = new EHealthResponse(new Response(200, [], '{"data":[]}'));
        $api = Mockery::mock(Approval::class)->makePartial();
        $api->shouldReceive('get')->once()->with('/api/patients/patient/approvals', $filters)->andReturn($response);

        $this->assertSame($response, $api->getPatientApprovals('patient', $filters));
    }

    public static function verificationAcknowledgements(): array
    {
        return [
            'empty 200' => [200, ''],
            'empty 204' => [204, ''],
            'empty object' => [200, '{}'],
            'empty data' => [200, '{"data":{}}'],
            'null data' => [200, '{"data":null}'],
            'metadata acknowledgement' => [200, '{"data":{},"meta":{"code":200,"type":"object","request_id":"synthetic"}}'],
        ];
    }

    public static function malformedApprovalLists(): array
    {
        return [
            'missing data' => ['{"paging":{"page_number":1,"total_pages":1}}'],
            'empty object' => ['{}'],
            'metadata only' => ['{"meta":{"code":200}}'],
            'null data' => ['{"data":null,"paging":{"page_number":1,"total_pages":1}}'],
            'missing paging' => ['{"data":[]}'],
            'missing total pages' => ['{"data":[],"paging":{"page_number":1}}'],
            'missing current page' => ['{"data":[],"paging":{"total_pages":1}}'],
            'non-list data' => ['{"data":{"unexpected":{}},"paging":{"page_number":1,"total_pages":1}}'],
        ];
    }

    #[DataProvider('malformedApprovalLists')]
    public function test_incomplete_approval_snapshots_are_rejected(string $body): void
    {
        $this->expectException(ValidationException::class);
        (new ReflectionMethod(Approval::class, 'validateApprovalList'))->invoke(
            new Approval(),
            new EHealthResponse(new Response(200, [], $body)),
        );
    }

    public static function completeApprovalPages(): array
    {
        return [
            'runtime spelling' => [['page_number' => 1, 'total_pages' => 1]],
            'documented spelling' => [['page' => 1, 'total_pages' => 1]],
            'empty collection' => [['page_number' => 1, 'total_pages' => 0]],
        ];
    }

    #[DataProvider('completeApprovalPages')]
    public function test_complete_empty_approval_snapshots_are_accepted(array $paging): void
    {
        $this->assertSame([], (new ReflectionMethod(Approval::class, 'validateApprovalList'))->invoke(
            new Approval(),
            new EHealthResponse(new Response(200, [], json_encode(['data' => [], 'paging' => $paging], JSON_THROW_ON_ERROR))),
        ));
    }

    #[DataProvider('verificationAcknowledgements')]
    public function test_verification_accepts_a_success_acknowledgement_without_a_full_resource(int $status, string $body): void
    {
        $api = new Approval();
        $response = new EHealthResponse(new Response($status, [], $body));
        $method = new ReflectionMethod(Approval::class, 'validateVerification');

        $this->assertSame([], $method->invoke($api, $response));
    }

    public function test_verification_rejects_a_malformed_acknowledgement(): void
    {
        $this->expectException(ValidationException::class);
        (new ReflectionMethod(Approval::class, 'validateVerification'))->invoke(
            new Approval(),
            new EHealthResponse(new Response(200, [], '{"data":"untrusted"}')),
        );
    }

    public function test_verification_does_not_grant_access_when_the_response_explicitly_denies_it(): void
    {
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('successful')->once()->andReturnTrue();
        $response->shouldReceive('validate')->once()->andReturn(['expires_at' => now()->addDay()->toIso8601String(), 'is_verified' => false]);
        $api = Mockery::mock(Approval::class);
        $api->shouldReceive('verify')->once()->with('patient', 'approval', ['code' => 1234])->andReturn($response);
        $this->instance(Approval::class, $api);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(__('care-plan.approval_verify_error'));
        app(CarePlanApprovalService::class)->verify('patient', 'approval', 1234);
    }

    public static function invalidConsentFields(): array
    {
        return [
            'missing verified flag' => ['is_verified', null, true],
            'null verified flag' => ['is_verified', null, false],
            'string verified flag' => ['is_verified', 'true', false],
            'missing expiry' => ['expires_at', null, true],
            'null expiry' => ['expires_at', null, false],
            'invalid expiry' => ['expires_at', 'not-a-date', false],
        ];
    }

    #[DataProvider('invalidConsentFields')]
    public function test_list_rejects_approvals_without_authoritative_consent_fields(string $field, mixed $value, bool $missing): void
    {
        $data = [
            'id' => '11111111-1111-4111-8111-111111111111',
            'granted_to' => ['identifier' => ['value' => '22222222-2222-4222-8222-222222222222']],
            'is_verified' => true,
            'expires_at' => now()->addDay()->toIso8601String(),
        ];
        if ($missing) {
            unset($data[$field]);
        } else {
            $data[$field] = $value;
        }
        $response = new EHealthResponse(new Response(200, [], json_encode([
            'data' => [$data], 'paging' => ['page_number' => 1, 'total_pages' => 1],
        ], JSON_THROW_ON_ERROR)));

        $this->expectException(ValidationException::class);
        (new ReflectionMethod(Approval::class, 'validateApprovalList'))->invoke(new Approval(), $response);
    }

    #[DataProvider('invalidConsentFields')]
    public function test_job_resource_rejects_missing_or_malformed_consent_fields(string $field, mixed $value, bool $missing): void
    {
        $data = [
            'id' => '11111111-1111-4111-8111-111111111111',
            'is_verified' => false,
            'expires_at' => now()->addDay()->toIso8601String(),
        ];
        if ($missing) {
            unset($data[$field]);
        } else {
            $data[$field] = $value;
        }

        $this->expectException(ValidationException::class);
        Approval::validateResourceData($data);
    }

    public function test_expired_approval_does_not_offer_patient_confirmation(): void
    {
        $id = '11111111-1111-4111-8111-111111111111';
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('successful')->once()->andReturnTrue();
        $response->shouldReceive('validate')->once()->andReturn([
            'id' => $id, 'is_verified' => false,
            'expires_at' => now()->subSecond()->toIso8601String(),
            'authentication_method_current' => ['type' => 'OTP'],
        ]);
        $this->mock(Approval::class)->shouldReceive('getApprovalDetails')->once()->with('patient', $id)->andReturn($response);
        $this->mock(Person::class)->shouldNotReceive('getAuthMethods');

        $this->expectException(RuntimeException::class);
        app(CarePlanApprovalService::class)->pendingConfirmation('patient', $id);
    }

    public static function confirmationMethods(): array
    {
        return [
            'patient SMS' => [['type' => 'OTP', 'number' => '+38093*****85'], true],
            'patient documents' => [['type' => 'OFFLINE'], false],
            'representative SMS' => [['type' => 'THIRD_PERSON', 'reference' => ['type' => 'OTP', 'phone_number' => '+38093*****85']], true],
            'representative documents' => [['type' => 'THIRD_PERSON', 'reference' => ['type' => 'OFFLINE']], false],
            'registry phone' => [['type' => 'OTP', 'phone_number' => '+38093*****85'], true],
        ];
    }

    #[DataProvider('confirmationMethods')]
    public function test_pending_approval_uses_the_actual_confirmation_method(array $method, bool $requiresOtp): void
    {
        $id = '11111111-1111-4111-8111-111111111111';
        $validated = Approval::validateResourceData([
            'id' => $id,
            'expires_at' => now()->addDay()->toIso8601String(), 'is_verified' => false,
            'authentication_method_current' => $method,
        ]);
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('successful')->andReturnTrue();
        $response->shouldReceive('validate')->andReturn($validated);
        $api = Mockery::mock(Approval::class);
        $api->shouldReceive('getApprovalDetails')->once()->with('patient', $id)->andReturn($response);
        $this->instance(Approval::class, $api);

        $result = app(CarePlanApprovalService::class)->pendingConfirmation('patient', $id);

        $this->assertTrue($result->requiresConfirmation());
        $this->assertFalse($result->isGranted());
        $this->assertSame($requiresOtp, $result->requiresOtp());
        $this->assertSame($method, $validated['authentication_method_current']);
        $this->assertSame($requiresOtp ? '+38093*****85' : null, AuthenticationMethod::confirmationPhone($result->authMethod));
    }

    public function test_care_plan_reuses_the_patient_approval_representative_sms_response(): void
    {
        $id = '11111111-1111-4111-8111-111111111111';
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('successful')->andReturnTrue();
        $response->shouldReceive('validate')->andReturn([
            'id' => $id, 'expires_at' => now()->addDay()->toIso8601String(), 'is_verified' => false,
            'authentication_method_current' => ['type' => 'THIRD_PERSON', 'number' => '+38093*****85'],
        ]);
        $this->mock(Approval::class)->shouldReceive('getApprovalDetails')->once()->with('patient', $id)->andReturn($response);
        $this->mock(Person::class)->shouldNotReceive('getAuthMethods');

        $result = app(CarePlanApprovalService::class)->pendingConfirmation('patient', $id);

        $this->assertTrue($result->requiresOtp());
        $this->assertSame('+38093*****85', AuthenticationMethod::confirmationPhone($result->authMethod));
    }

    public function test_representative_method_is_resolved_from_the_exact_registered_method(): void
    {
        $id = '11111111-1111-4111-8111-111111111111';
        $authId = '22222222-2222-4222-8222-222222222222';
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('successful')->andReturnTrue();
        $response->shouldReceive('validate')->andReturn([
            'id' => $id, 'expires_at' => now()->addDay()->toIso8601String(), 'is_verified' => false,
            'authentication_method_current' => ['type' => 'THIRD_PERSON'],
        ]);
        $this->mock(Approval::class)->shouldReceive('getApprovalDetails')->once()->with('patient', $id)->andReturn($response);
        $registry = Mockery::mock(EHealthResponse::class);
        $registry->shouldReceive('validate')->andReturn([
            ['id' => 'other-method', 'type' => 'OFFLINE'],
            ['id' => $authId, 'type' => 'THIRD_PERSON', 'reference' => ['type' => 'OTP', 'phone_number' => '+38093*****85']],
        ]);
        $this->mock(Person::class)->shouldReceive('getAuthMethods')->once()->with('patient')->andReturn($registry);

        $result = app(CarePlanApprovalService::class)->pendingConfirmation('patient', $id, $authId);

        $this->assertTrue($result->requiresOtp());
        $this->assertSame('+38093*****85', AuthenticationMethod::confirmationPhone($result->authMethod));
    }

    public function test_representative_without_reference_uses_the_representatives_registered_method(): void
    {
        $id = '11111111-1111-4111-8111-111111111111';
        $authId = '22222222-2222-4222-8222-222222222222';
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('successful')->andReturnTrue();
        $response->shouldReceive('validate')->andReturn([
            'id' => $id, 'expires_at' => now()->addDay()->toIso8601String(), 'is_verified' => false,
            'authentication_method_current' => ['type' => 'THIRD_PERSON'],
        ]);
        $this->mock(Approval::class)->shouldReceive('getApprovalDetails')->once()->with('patient', $id)->andReturn($response);
        $registry = Mockery::mock(EHealthResponse::class);
        $registry->shouldReceive('validate')->andReturn([['id' => $authId, 'type' => 'THIRD_PERSON', 'value' => 'representative']]);
        $representative = Mockery::mock(EHealthResponse::class);
        $representative->shouldReceive('validate')->andReturn([['id' => 'representative-method', 'type' => 'OFFLINE']]);
        $api = $this->mock(Person::class);
        $api->shouldReceive('getAuthMethods')->once()->with('patient')->andReturn($registry);
        $api->shouldReceive('getAuthMethods')->once()->with('representative')->andReturn($representative);

        $result = app(CarePlanApprovalService::class)->pendingConfirmation('patient', $id, $authId);

        $this->assertTrue($result->requiresConfirmation());
        $this->assertFalse($result->requiresOtp());
    }

    public function test_pending_approval_without_current_method_resolves_offline_from_authorize_with(): void
    {
        $id = '11111111-1111-4111-8111-111111111111';
        $authId = '33333333-3333-4333-8333-333333333333';
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('successful')->andReturnTrue();
        $response->shouldReceive('validate')->andReturn([
            'id' => $id,
            'expires_at' => now()->addDay()->toIso8601String(), 'is_verified' => false,
        ]);
        $this->mock(Approval::class)->shouldReceive('getApprovalDetails')->once()->with('patient', $id)->andReturn($response);
        $registry = Mockery::mock(EHealthResponse::class);
        $registry->shouldReceive('validate')->andReturn([
            ['id' => $authId, 'type' => 'OFFLINE', 'alias' => 'Паспорт'],
        ]);
        $this->mock(Person::class)->shouldReceive('getAuthMethods')->once()->with('patient')->andReturn($registry);

        $result = app(CarePlanApprovalService::class)->pendingConfirmation('patient', $id, $authId);

        $this->assertTrue($result->requiresConfirmation());
        $this->assertFalse($result->requiresOtp());
        $this->assertSame(AuthenticationMethod::OFFLINE, AuthenticationMethod::confirmationMethod($result->authMethod));
    }

    public function test_unknown_representative_method_cannot_be_treated_as_document_confirmation(): void
    {
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('successful')->andReturnTrue();
        $response->shouldReceive('validate')->andReturn([
            'id' => '11111111-1111-4111-8111-111111111111',
            'expires_at' => now()->addDay()->toIso8601String(), 'is_verified' => false,
            'authentication_method_current' => ['type' => 'THIRD_PERSON'],
        ]);
        $this->mock(Approval::class)->shouldReceive('getApprovalDetails')->once()->andReturn($response);

        $this->expectException(RuntimeException::class);
        app(CarePlanApprovalService::class)->pendingConfirmation('patient', '11111111-1111-4111-8111-111111111111');
    }

    public function test_person_validation_and_mapping_preserve_the_representative_confirmation_method(): void
    {
        $api = new Person();
        $response = new EHealthResponse(new Response(200, [], json_encode(['data' => [[
            'id' => '11111111-1111-4111-8111-111111111111',
            'type' => 'THIRD_PERSON',
            'value' => '22222222-2222-4222-8222-222222222222',
            'reference' => ['type' => 'OTP', 'phone_number' => '+38093*****85'],
        ]]], JSON_THROW_ON_ERROR)));

        $validated = (new ReflectionMethod(Person::class, 'validateAuthMethods'))->invoke($api, $response);
        $mapped = (new ReflectionMethod(Person::class, 'mapAuthMethods'))->invoke($api, $validated);

        $this->assertSame(AuthenticationMethod::OTP, AuthenticationMethod::confirmationMethod($mapped[0]));
        $this->assertSame('+38093*****85', AuthenticationMethod::confirmationPhone($mapped[0]));
    }

    public static function unresolvedConfirmationMethods(): array
    {
        return [
            'unknown' => [['type' => 'UNKNOWN']],
            'not available' => [['type' => 'NA']],
            'noncanonical type' => [['type' => 'offline']],
            'unresolved representative' => [['type' => 'THIRD_PERSON']],
            'unsupported reference alias' => [['type' => 'THIRD_PERSON', 'authentication_reference' => ['type' => 'OFFLINE']]],
            'unsupported camel reference alias' => [['type' => 'THIRD_PERSON', 'authenticationReference' => ['type' => 'OFFLINE']]],
        ];
    }

    #[DataProvider('unresolvedConfirmationMethods')]
    public function test_unknown_confirmation_types_never_imply_document_confirmation(array $method): void
    {
        $this->assertNull(AuthenticationMethod::confirmationMethod($method));
    }
}
