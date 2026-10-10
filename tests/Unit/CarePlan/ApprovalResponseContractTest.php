<?php

declare(strict_types=1);

namespace Tests\Unit\CarePlan;

use App\Classes\eHealth\Api\Approval;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Classes\eHealth\Api\Approval as ApprovalApi;
use GuzzleHttp\Psr7\Response;
use Mockery;
use Tests\TestCase;

class ApprovalResponseContractTest extends TestCase
{
    public function test_inpatient_confirmation_rejects_a_non_success_response(): void
    {
        $api = Mockery::mock(Approval::class)->makePartial();
        $api->shouldReceive('verify')->once()->with('patient', 'approval', [])
            ->andReturn(new EHealthResponse(new Response(403)));
        $this->instance(Approval::class, $api);

        $this->expectException(EHealthResponseException::class);
        app(ApprovalApi::class)->confirmWithoutOtp('patient', 'approval');
    }

    public function test_deactivation_rejects_a_non_success_response(): void
    {
        $api = Mockery::mock(Approval::class)->makePartial();
        $api->shouldReceive('verify')->once()->with('patient', 'approval', ['status' => 'inactive'])
            ->andReturn(new EHealthResponse(new Response(503)));
        $this->instance(Approval::class, $api);

        $this->expectException(EHealthResponseException::class);
        app(ApprovalApi::class)->deactivate('patient', 'approval');
    }

    public function test_patient_approval_url_and_filters_are_preserved(): void
    {
        $filters = ['granted_resource_type' => 'care_plan', 'granted_resources' => 'plan'];
        $response = new EHealthResponse(new Response(200, [], '{"data":[]}'));
        $api = Mockery::mock(Approval::class)->makePartial();
        $api->shouldReceive('get')->once()->with('/api/patients/patient/approvals', $filters)->andReturn($response);

        $this->assertSame($response, $api->getPatientApprovals('patient', $filters));
    }
}
