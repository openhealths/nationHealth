<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MedicalEvents;

use App\Classes\eHealth\Api\CarePlanActivity as CarePlanActivityApi;
use App\Classes\eHealth\EHealthResponse;
use App\Services\MedicalEvents\CarePlanActivityLifecycleService;
use App\Services\MedicalEvents\EHealthJobResolver;
use Mockery;
use Tests\TestCase;

class CarePlanActivityLifecycleServiceTest extends TestCase
{
    public function test_submit_signed_create_posts_and_resolves_the_job(): void
    {
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('getData')->once()->andReturn(['job_id' => 'job-a']);

        $api = Mockery::mock(CarePlanActivityApi::class);
        $api->shouldReceive('create')
            ->once()
            ->with('person-uuid', 'plan-uuid', [
                'signed_data' => 'signed',
                'signed_data_encoding' => 'base64',
            ])
            ->andReturn($response);
        $this->instance(CarePlanActivityApi::class, $api);

        $resolver = Mockery::mock(EHealthJobResolver::class);
        $resolver->shouldReceive('resolve')->once()->with(['job_id' => 'job-a'])->andReturn([
            'id' => 'activity-uuid',
            'status' => 'scheduled',
        ]);
        $this->app->instance(EHealthJobResolver::class, $resolver);

        $result = app(CarePlanActivityLifecycleService::class)
            ->submitSignedCreate('person-uuid', 'plan-uuid', 'signed');

        $this->assertSame('activity-uuid', $result['id']);
    }

    public function test_cancel_and_complete_resolve_jobs(): void
    {
        $payload = ['signed_data' => 'signed', 'signed_data_encoding' => 'base64'];
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('getData')->twice()->andReturn(['job_id' => 'job-b'], ['job_id' => 'job-c']);

        $api = Mockery::mock(CarePlanActivityApi::class);
        $api->shouldReceive('cancel')
            ->once()
            ->with('person-uuid', 'plan-uuid', 'activity-uuid', $payload)
            ->andReturn($response);
        $api->shouldReceive('complete')
            ->once()
            ->with('person-uuid', 'plan-uuid', 'activity-uuid', ['detail' => []])
            ->andReturn($response);
        $this->instance(CarePlanActivityApi::class, $api);

        $resolver = Mockery::mock(EHealthJobResolver::class);
        $resolver->shouldReceive('resolve')
            ->twice()
            ->andReturn(['status' => 'cancelled'], ['status' => 'completed']);
        $this->app->instance(EHealthJobResolver::class, $resolver);

        $service = app(CarePlanActivityLifecycleService::class);

        $this->assertSame('cancelled', $service->cancel(
            'person-uuid',
            'plan-uuid',
            'activity-uuid',
            $payload
        )['status']);
        $this->assertSame('completed', $service->complete(
            'person-uuid',
            'plan-uuid',
            'activity-uuid',
            ['detail' => []]
        )['status']);
    }
}
