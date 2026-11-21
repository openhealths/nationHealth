<?php

declare(strict_types=1);

namespace Tests\Unit\CarePlan;

use App\Livewire\CarePlan\CarePlanComponent;
use App\Livewire\CarePlan\CarePlanShow;
use App\Livewire\CarePlan\Concerns\CarePlanManager;
use App\Models\CarePlan;
use App\Repositories\CarePlanActivityRepository;
use App\Repositories\CarePlanRepository;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class CarePlanFhirWorkflowTest extends TestCase
{
    public function test_allocated_draft_uuid_still_dispatches_the_plan_creation_action(): void
    {
        $component = new class extends CarePlanComponent
        {
            use CarePlanManager;

            public bool $signedPlan = false;

            protected function authorizeCarePlanWrite(): void
            {
            }
            protected function rulesForSigning(): array
            {
                return ['actionType' => 'in:sign_plan'];
            }
            protected function requiresDigitalSignature(): bool
            {
                return false;
            }
            protected function signPlan(CarePlanRepository $repository): void
            {
                $this->signedPlan = true;
            }
            protected function renderCarePlan()
            {
                return null;
            }
        };
        $component->carePlan = new CarePlan(['uuid' => '8d08ce22-82dd-4ac4-bdb9-432fd5aad5dd', 'status' => 'draft']);
        $component->actionType = 'sign_plan';
        $repository = Mockery::mock(CarePlanRepository::class);
        $repository->shouldNotReceive('updateById');

        $component->sign($repository, Mockery::mock(CarePlanActivityRepository::class));

        $this->assertTrue($component->signedPlan);
        $this->assertSame('draft', $component->carePlan->status);
    }

    public function test_completion_does_not_require_signing_credentials_but_cancellation_does(): void
    {
        $component = new CarePlanShow();
        $rules = new ReflectionMethod($component, 'rulesForSigning');
        $requiresSignature = new ReflectionMethod($component, 'requiresDigitalSignature');
        foreach (['complete', 'complete_activity'] as $action) {
            $component->actionType = $action;
            $this->assertSame('required|string', $rules->invoke($component)['statusReason']);
            $this->assertFalse($requiresSignature->invoke($component));
        }
        $component->actionType = 'cancel';
        $this->assertTrue($requiresSignature->invoke($component));
    }
}
