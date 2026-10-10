<?php

declare(strict_types=1);

namespace Tests\Unit\Classes\EHealth\Api;

use App\Classes\eHealth\Api\CarePlan as CarePlanApi;
use App\Classes\eHealth\EHealthResponse;
use App\Classes\eHealth\Api\Job;
use Mockery;
use Tests\TestCase;

class CarePlanResolvedWritesTest extends TestCase
{
    public function test_submit_signed_create_posts_and_resolves_the_job(): void
    {
        $this->bindCarePlanApi('create', [
            'person-uuid',
            [
                'signed_data' => 'signed',
                'signed_data_encoding' => 'base64',
            ],
        ], ['job_id' => 'job-1']);

        $this->bindResolver(['job_id' => 'job-1'], ['id' => 'care-plan-uuid', 'status' => 'new']);

        $result = app(CarePlanApi::class)->createSignedAndResolve('person-uuid', 'signed');

        $this->assertSame('care-plan-uuid', $result['id']);
        $this->assertSame('new', $result['status']);
    }

    public function test_cancel_posts_and_resolves_the_job(): void
    {
        $payload = ['signed_data' => 'signed', 'signed_data_encoding' => 'base64'];
        $this->bindCarePlanApi('cancel', ['person-uuid', 'plan-uuid', $payload], ['job_id' => 'job-2']);
        $this->bindResolver(['job_id' => 'job-2'], ['status' => 'cancelled']);

        $result = app(CarePlanApi::class)->cancelAndResolve('person-uuid', 'plan-uuid', $payload);

        $this->assertSame('cancelled', $result['status']);
    }

    public function test_complete_posts_and_resolves_the_job(): void
    {
        $payload = ['status_reason' => ['coding' => []]];
        $this->bindCarePlanApi('complete', ['person-uuid', 'plan-uuid', $payload], ['job_id' => 'job-3']);
        $this->bindResolver(['job_id' => 'job-3'], ['status' => 'completed']);

        $result = app(CarePlanApi::class)->completeAndResolve('person-uuid', 'plan-uuid', $payload);

        $this->assertSame('completed', $result['status']);
    }

    /**
     * @param  list<mixed>  $expectedArgs
     * @param  array<string, mixed>  $jobEnvelope
     */
    private function bindCarePlanApi(string $method, array $expectedArgs, array $jobEnvelope): void
    {
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('getData')->once()->andReturn($jobEnvelope);

        $api = Mockery::mock(CarePlanApi::class)->makePartial();
        $api->shouldReceive($method)->once()->with(...$expectedArgs)->andReturn($response);

        $this->instance(CarePlanApi::class, $api);
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @param  array<string, mixed>  $resolved
     */
    private function bindResolver(array $envelope, array $resolved): void
    {
        $resolver = Mockery::mock(Job::class);
        $resolver->shouldReceive('resolve')->once()->with($envelope)->andReturn($resolved);
        $this->app->instance(Job::class, $resolver);
    }
}
