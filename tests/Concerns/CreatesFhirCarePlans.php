<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Repositories\CarePlanActivityRepository;
use App\Repositories\CarePlanRepository;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/** Explicit normalized fixtures for tests that previously inserted retired flat columns. */
trait CreatesFhirCarePlans
{
    protected function assertFhirCarePlanStored(array $expected): void
    {
        $query = CarePlan::query();
        foreach (['id', 'uuid', 'title', 'person_id'] as $key) {
            if (array_key_exists($key, $expected)) {
                $query->where($key, $expected[$key]);
            }
        }
        $plan = $query->firstOrFail();
        $resource = app(CarePlanRepository::class)->getResource($plan->id);
        foreach ($expected as $key => $value) {
            $actual = match ($key) {
                'terms_of_service' => data_get($resource, 'termsOfService.coding.0.code'),
                'inform_with' => data_get($resource, 'informWith.authMethodId'),
                'context' => $resource['context'] ?? null,
                default => $plan->getAttribute($key),
            };
            $this->assertEquals($value, $actual, $key);
        }
    }

    protected function assertFhirActivityStored(array $expected): void
    {
        $query = CarePlanActivity::query();
        foreach (['id', 'uuid', 'care_plan_id'] as $key) {
            if (array_key_exists($key, $expected)) {
                $query->where($key, $expected[$key]);
            }
        }
        $activity = $query->firstOrFail();
        $resource = app(CarePlanActivityRepository::class)->getResource($activity->id);
        foreach ($expected as $key => $value) {
            $actual = match ($key) {
                'quantity' => data_get($resource, 'detail.quantity.value'),
                'product_reference' => data_get($resource, 'detail.productReference.identifier.value'),
                'program' => data_get($resource, 'detail.program.identifier.value'),
                default => $activity->getAttribute($key),
            };
            $this->assertEquals($value, $actual, $key);
        }
    }

    protected function createFhirCarePlan(array $attributes): CarePlan
    {
        foreach ($attributes as $key => $value) {
            if ($value instanceof CarbonInterface) {
                $attributes[$key] = $value->toIso8601String();
            }
        }

        return app(CarePlanRepository::class)->create(array_replace([
            'uuid' => (string) Str::uuid(), 'status' => 'draft', 'category' => 'TEST',
            'title' => 'Synthetic fixture', 'terms_of_service' => 'OUTPATIENT',
            'period_start' => now()->toDateString(),
        ], $attributes));
    }

    protected function createFhirCarePlanActivity(array $attributes): CarePlanActivity
    {
        if (is_string($attributes['status_reason'] ?? null)) {
            $attributes['status_reason'] = ['coding' => [['system' => 'synthetic/activity-status-reasons', 'code' => $attributes['status_reason']]]];
        }
        foreach ($attributes as $key => $value) {
            if ($value instanceof CarbonInterface) {
                $attributes[$key] = $value->toIso8601String();
            }
        }

        return app(CarePlanActivityRepository::class)->create(array_replace([
            'uuid' => (string) Str::uuid(), 'status' => 'draft', 'kind' => 'service_request',
        ], $attributes));
    }
}
