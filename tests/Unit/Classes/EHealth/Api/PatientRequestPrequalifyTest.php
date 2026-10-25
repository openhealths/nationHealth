<?php

declare(strict_types=1);

namespace Tests\Unit\Classes\EHealth\Api;

use App\Classes\eHealth\Api\Job;
use App\Classes\eHealth\Api\Patient\DeviceRequest;
use App\Classes\eHealth\Api\Patient\ServiceRequest;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PatientRequestPrequalifyTest extends TestCase
{
    public static function requestApis(): array
    {
        return [
            'service request' => [ServiceRequest::class],
            'device request' => [DeviceRequest::class],
        ];
    }

    #[DataProvider('requestApis')]
    public function test_returns_the_resolved_prequalify_verdict(string $apiClass): void
    {
        $result = ['data' => [['status' => 'VALID']]];
        $api = $this->mockPrequalify($apiClass, $result);

        $this->assertSame($result, $api->prequalifyAndValidate('patient-uuid', ['programs' => ['program-uuid']]));
    }

    #[DataProvider('requestApis')]
    public function test_rejects_an_invalid_verdict_after_job_resolution(string $apiClass): void
    {
        $api = $this->mockPrequalify($apiClass, [
            'data' => [['status' => 'INVALID', 'rejection_reason' => 'Program does not cover this request']],
        ]);

        $this->expectException(EHealthValidationException::class);
        $api->prequalifyAndValidate('patient-uuid', ['programs' => ['program-uuid']]);
    }

    private function mockPrequalify(string $apiClass, array $result): ServiceRequest|DeviceRequest
    {
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('getData')->once()->andReturn(['job_id' => 'prequalify-job']);
        $api = Mockery::mock($apiClass)->makePartial();
        $api->shouldReceive('prequalify')->once()
            ->with('patient-uuid', ['programs' => ['program-uuid']])->andReturn($response);

        $job = Mockery::mock(Job::class)->makePartial();
        $job->shouldReceive('resolve')->once()->with(['job_id' => 'prequalify-job'])->andReturn($result);
        $this->instance(Job::class, $job);

        return $api;
    }
}
