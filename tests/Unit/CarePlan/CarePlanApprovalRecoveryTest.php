<?php

declare(strict_types=1);

namespace Tests\Unit\CarePlan;

use App\Classes\eHealth\Api\Approval;
use App\Classes\eHealth\EHealthResponse;
use App\Livewire\CarePlan\CarePlanCreate;
use App\Livewire\CarePlan\Forms\CarePlanForm;
use GuzzleHttp\Psr7\Response;
use ReflectionMethod;
use Tests\TestCase;

class CarePlanApprovalRecoveryTest extends TestCase
{
    public function test_recovery_skips_expired_and_unrelated_approvals_and_checks_later_pages(): void
    {
        $component = new CarePlanCreate();
        $component->patientUuid = '11111111-1111-4111-8111-111111111111';
        $component->form = new CarePlanForm($component, 'form');
        $employeeUuid = '22222222-2222-4222-8222-222222222222';
        $planUuid = '33333333-3333-4333-8333-333333333333';
        $otherUuid = '44444444-4444-4444-8444-444444444444';
        $approvalUuid = '55555555-5555-4555-8555-555555555555';
        $component->form->author = $employeeUuid;
        $grant = [
            'id' => $approvalUuid, 'is_verified' => false,
            'expires_at' => now()->addDay()->toIso8601String(),
            'granted_to' => ['identifier' => ['value' => $employeeUuid]],
            'granted_resources' => [['identifier' => [
                'value' => $planUuid, 'type' => ['coding' => [['code' => 'care_plan']]],
            ]]],
        ];
        $otherEmployee = $grant;
        $otherEmployee['granted_to']['identifier']['value'] = $otherUuid;
        $otherPlan = $grant;
        $otherPlan['granted_resources'][0]['identifier']['value'] = $otherUuid;
        $expired = $grant;
        $expired['expires_at'] = now()->subSecond()->toIso8601String();
        $api = $this->mock(Approval::class);
        $filters = [
            'granted_resource_type' => 'care_plan', 'granted_resources' => $planUuid,
            'granted_to' => $employeeUuid, 'is_expired' => false,
        ];
        $api->shouldReceive('getPatientApprovals')->once()->with($component->patientUuid, $filters + ['page' => 1])
            ->andReturn($this->page([$otherEmployee, $otherPlan, $expired], 1));
        $api->shouldReceive('getPatientApprovals')->once()->with($component->patientUuid, $filters + ['page' => 2])
            ->andReturn($this->page([$grant], 2));

        $result = (new ReflectionMethod(CarePlanCreate::class, 'findCarePlanApproval'))->invoke($component, $planUuid);

        $this->assertSame($approvalUuid, $result['id']);
        $this->assertFalse($result['is_verified']);
        $this->assertArrayNotHasKey('status', $result);
    }

    private function page(array $grants, int $page): EHealthResponse
    {
        $validator = new ReflectionMethod(Approval::class, 'validateApprovalList');

        return new EHealthResponse(new Response(200, [], json_encode([
            'data' => $grants, 'paging' => ['page_number' => $page, 'total_pages' => 2],
        ], JSON_THROW_ON_ERROR)), fn (EHealthResponse $response): array => $validator->invoke(new Approval(), $response));
    }
}
