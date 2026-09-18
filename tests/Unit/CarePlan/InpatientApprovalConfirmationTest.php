<?php

declare(strict_types=1);

namespace Tests\Unit\CarePlan;

use App\Classes\eHealth\EHealthResponse;
use App\Livewire\CarePlan\CarePlanApprovals;
use App\Services\MedicalEvents\CarePlanApprovalService;
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
        $service = Mockery::mock(CarePlanApprovalService::class);
        $service->shouldReceive('confirmWithoutOtp')->once()->with('patient', 'approval')
            ->andThrow(new RuntimeException('API unavailable'));
        $this->instance(CarePlanApprovalService::class, $service);
        $component = new class extends CarePlanApprovals
        {
            public bool $refreshed = false;

            public function fetchApprovals(): void
            {
                $this->refreshed = true;
            }
        };
        $component->patientUuid = 'patient';

        (new ReflectionMethod(CarePlanApprovals::class, 'confirmInpatientApproval'))->invoke($component, 'approval');

        $this->assertSame(__('care-plan.approval_verify_error'), Session::get('error'));
        $this->assertFalse(Session::has('success'));
        $this->assertFalse($component->refreshed);
    }

    public function test_unsuccessful_response_does_not_flash_success(): void
    {
        $service = Mockery::mock(CarePlanApprovalService::class);
        $service->shouldReceive('confirmWithoutOtp')->once()
            ->andReturn(new EHealthResponse(new Response(403, [], '{"error":{"message":"Forbidden"}}')));
        $this->instance(CarePlanApprovalService::class, $service);
        $component = new CarePlanApprovals();
        $component->patientUuid = 'patient';

        (new ReflectionMethod(CarePlanApprovals::class, 'confirmInpatientApproval'))->invoke($component, 'approval');

        $this->assertFalse(Session::has('success'));
        $this->assertNotNull($component->errorMessage);
    }
}
