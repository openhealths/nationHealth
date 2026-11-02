<?php

declare(strict_types=1);

namespace Tests\Unit\CarePlan;

use App\Classes\eHealth\Api\Approval;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Services\MedicalEvents\CarePlanApprovalService;
use GuzzleHttp\Psr7\Response;
use Mockery;
use Tests\TestCase;

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

    public function test_cancellation_rejects_a_non_success_response(): void
    {
        $api = Mockery::mock(Approval::class);
        $api->shouldReceive('withToken')->andReturnSelf();
        $api->shouldReceive('cancelApproval')->once()->with('patient', 'approval')
            ->andReturn(new EHealthResponse(new Response(503)));
        $this->instance(Approval::class, $api);
        $user = Mockery::mock(\App\Models\User::class);
        $user->shouldReceive('cannot')->with('cancelApproval', Mockery::type(\App\Models\CarePlan::class))->andReturn(false);
        $plan = new \App\Models\CarePlan(['legal_entity_id' => 1]);
        $plan->setRelation('person', (new \App\Models\Person\Person())->forceFill(['uuid' => 'patient']));
        $legalEntity = new \App\Models\LegalEntity();
        $legalEntity->id = 1;
        $link = Mockery::mock(\App\Models\EhealthLink::class)->makePartial();
        $link->wasRecentlyCreated = true;
        $link->shouldReceive('update')->once();
        $repository = $this->mock(\App\Repositories\MedicalEvents\ApprovalRepository::class);
        $repository->shouldReceive('beginCarePlanCancellation')->once()->andReturn($link);
        $repository->shouldNotReceive('finishCarePlanCancellation');

        $this->expectException(EHealthResponseException::class);
        app(CarePlanApprovalService::class)->requestCancellation($plan, 'approval', $legalEntity, $user, 'test-token');
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

    #[\PHPUnit\Framework\Attributes\DataProvider('verificationAcknowledgements')]
    public function test_verification_accepts_a_success_acknowledgement_without_a_full_resource(int $status, string $body): void
    {
        $api = new Approval();
        $response = new EHealthResponse(new Response($status, [], $body));
        $method = new \ReflectionMethod(Approval::class, 'validateVerification');

        $this->assertSame([], $method->invoke($api, $response));
    }

    public function test_verification_rejects_a_malformed_acknowledgement(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        (new \ReflectionMethod(Approval::class, 'validateVerification'))->invoke(
            new Approval(),
            new EHealthResponse(new Response(200, [], '{"data":"untrusted"}')),
        );
    }

    public function test_verification_does_not_grant_access_when_the_response_explicitly_denies_it(): void
    {
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('successful')->once()->andReturnTrue();
        $response->shouldReceive('validate')->once()->andReturn(['is_verified' => false]);
        $api = Mockery::mock(Approval::class);
        $api->shouldReceive('verify')->once()->with('patient', 'approval', ['code' => 1234])->andReturn($response);
        $this->instance(Approval::class, $api);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(__('care-plan.approval_verify_error'));
        app(CarePlanApprovalService::class)->verify('patient', 'approval', 1234);
    }

}
