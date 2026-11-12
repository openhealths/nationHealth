<?php

declare(strict_types=1);

namespace Tests\Unit\CarePlan;

use App\Livewire\CarePlan\CarePlanComponent;
use App\Models\CarePlan;
use App\Repositories\CarePlanActivityRepository;
use App\Repositories\CarePlanRepository;
use Mockery;
use ReflectionMethod;
use ReflectionProperty;
use Tests\Support\CarePlanShowHarness;
use Tests\Support\CarePlanWorkflowHarness;
use Tests\TestCase;

class CarePlanFhirWorkflowTest extends TestCase
{
    public function test_allocated_draft_uuid_still_dispatches_the_plan_creation_action(): void
    {
        $component = new class extends CarePlanWorkflowHarness
        {

            public bool $signedPlan = false;

            protected function authorizeCarePlanWrite(): void
            {
            }
            protected function rulesForSigning(): array
            {
                return ['actionType' => 'in:sign_plan'];
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
        $component->setCarePlan(new CarePlan(['uuid' => '8d08ce22-82dd-4ac4-bdb9-432fd5aad5dd', 'status' => 'draft']));
        $component->actionType = 'sign_plan';
        $repository = Mockery::mock(CarePlanRepository::class);
        $repository->shouldNotReceive('updateById');

        $this->instance(CarePlanRepository::class, $repository);
        $this->instance(CarePlanActivityRepository::class, Mockery::mock(CarePlanActivityRepository::class));
        $component->sign();

        $this->assertTrue($component->signedPlan);
        $this->assertSame('draft', (new ReflectionProperty(CarePlanComponent::class, 'carePlan'))->getValue($component)->status);
    }

    public function test_completion_does_not_require_signing_credentials_but_cancellation_does(): void
    {
        $component = new CarePlanShowHarness();
        $rules = new ReflectionMethod($component, 'rulesForSigning');
        foreach (['complete', 'complete_activity'] as $action) {
            $component->actionType = $action;
            $actual = $rules->invoke($component);
            $this->assertSame('required|string', $actual['statusReason']);
            $this->assertArrayNotHasKey('form.keyContainerUpload', $actual);
            $this->assertArrayNotHasKey('form.password', $actual);
        }
        $component->actionType = 'cancel';
        $this->assertArrayHasKey('form.keyContainerUpload', $rules->invoke($component));
    }
}
