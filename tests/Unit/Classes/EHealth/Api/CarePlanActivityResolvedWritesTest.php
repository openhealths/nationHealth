<?php

declare(strict_types=1);

namespace Tests\Unit\Classes\EHealth\Api;

use App\Classes\eHealth\Api\CarePlanActivity as CarePlanActivityApi;
use App\Classes\eHealth\EHealthResponse;
use App\Classes\eHealth\Api\Job;
use Mockery;
use Tests\TestCase;

class CarePlanActivityResolvedWritesTest extends TestCase
{
    public function test_signing_snapshot_preserves_remote_fields_and_unwraps_the_response(): void
    {
        $snapshot = [
            'id' => 'activity', 'author' => ['identifier' => ['value' => 'employee']],
            'detail' => ['do_not_perform' => false, 'remaining_quantity' => ['value' => 0]],
            'unknownClinicalField' => ['empty' => [], 'fraction' => 1.0],
        ];
        $api = Mockery::mock(CarePlanActivityApi::class)->makePartial();
        foreach ([$snapshot, ['data' => $snapshot]] as $data) {
            $response = Mockery::mock(EHealthResponse::class);
            $response->shouldReceive('getData')->once()->andReturn($data);
            $api->shouldReceive('getDetails')->once()->with('patient', 'plan', 'activity')->andReturn($response);
            $this->assertSame($snapshot, $api->getSigningSnapshot('patient', 'plan', 'activity'));
        }
    }

    public function test_submit_signed_create_posts_and_resolves_the_job(): void
    {
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('getData')->once()->andReturn(['job_id' => 'job-a']);

        $api = Mockery::mock(CarePlanActivityApi::class)->makePartial();
        $api->shouldReceive('create')
            ->once()
            ->with('person-uuid', 'plan-uuid', [
                'signed_data' => 'signed',
                'signed_data_encoding' => 'base64',
            ])
            ->andReturn($response);
        $this->instance(CarePlanActivityApi::class, $api);

        $resolver = Mockery::mock(Job::class);
        $resolver->shouldReceive('resolve')->once()->with(['job_id' => 'job-a'])->andReturn([
            'id' => 'activity-uuid',
            'status' => 'scheduled',
        ]);
        $this->app->instance(Job::class, $resolver);

        $result = app(CarePlanActivityApi::class)
            ->createSignedAndResolve('person-uuid', 'plan-uuid', 'signed');

        $this->assertSame('activity-uuid', $result['id']);
    }

    public function test_cancel_and_complete_resolve_jobs(): void
    {
        $payload = ['signed_data' => 'signed', 'signed_data_encoding' => 'base64'];
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('getData')->twice()->andReturn(['job_id' => 'job-b'], ['job_id' => 'job-c']);

        $api = Mockery::mock(CarePlanActivityApi::class)->makePartial();
        $api->shouldReceive('cancel')
            ->once()
            ->with('person-uuid', 'plan-uuid', 'activity-uuid', $payload)
            ->andReturn($response);
        $api->shouldReceive('complete')
            ->once()
            ->with('person-uuid', 'plan-uuid', 'activity-uuid', ['detail' => []])
            ->andReturn($response);
        $this->instance(CarePlanActivityApi::class, $api);

        $resolver = Mockery::mock(Job::class);
        $resolver->shouldReceive('resolve')
            ->twice()
            ->andReturn(['status' => 'cancelled'], ['status' => 'completed']);
        $this->app->instance(Job::class, $resolver);

        $service = app(CarePlanActivityApi::class);

        $this->assertSame('cancelled', $service->cancelAndResolve(
            'person-uuid',
            'plan-uuid',
            'activity-uuid',
            $payload
        )['status']);
        $this->assertSame('completed', $service->completeAndResolve(
            'person-uuid',
            'plan-uuid',
            'activity-uuid',
            ['detail' => []]
        )['status']);
    }
}
