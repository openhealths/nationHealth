<?php

declare(strict_types=1);

namespace Tests\Unit\CarePlan;

use App\Classes\eHealth\EHealthResponse;
use App\Livewire\CarePlan\CarePlanApprovals;
use App\Classes\eHealth\Api\Approval as ApprovalApi;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Session;
use Mockery;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class InpatientApprovalConfirmationTest extends TestCase
{
    public function test_failed_confirmation_does_not_flash_success_or_refresh_as_granted(): void
    {
        $service = Mockery::mock(ApprovalApi::class);
        $service->shouldReceive('confirmWithoutOtp')->once()->with('patient', 'approval')
            ->andThrow(new RuntimeException('API unavailable'));
        $this->instance(ApprovalApi::class, $service);
        $component = new InpatientApprovalHarness();
        $component->patientUuid = 'patient';

        (new ReflectionMethod(CarePlanApprovals::class, 'confirmInpatientApproval'))->invoke($component, 'approval');

        $this->assertSame(__('care-plan.approval_verify_error'), $component->errorMessage);
        $this->assertSame(__('care-plan.approval_verify_error'), Session::get('error'));
        $this->assertFalse(Session::has('success'));
        $this->assertFalse($component->refreshed);
    }

    public function test_unsuccessful_response_does_not_flash_success(): void
    {
        $service = Mockery::mock(ApprovalApi::class);
        $service->shouldReceive('confirmWithoutOtp')->once()
            ->andReturn(new EHealthResponse(new Response(403, [], '{"error":{"message":"Forbidden"}}')));
        $this->instance(ApprovalApi::class, $service);
        $component = new InpatientApprovalHarness();
        $component->patientUuid = 'patient';

        (new ReflectionMethod(CarePlanApprovals::class, 'confirmInpatientApproval'))->invoke($component, 'approval');

        $this->assertFalse(Session::has('success'));
        $this->assertNotNull($component->errorMessage);
        $this->assertFalse($component->refreshed);
        $this->assertSame(__('care-plan.approval_verify_error'), Session::get('error'));
    }
}

class InpatientApprovalHarness extends CarePlanApprovals
{
    public bool $refreshed = false;

    public function fetchApprovals(): void
    {
        $this->refreshed = true;
    }

}
