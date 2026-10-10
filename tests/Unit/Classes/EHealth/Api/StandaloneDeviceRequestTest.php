<?php

declare(strict_types=1);

namespace Tests\Unit\Classes\EHealth\Api;

use App\Classes\eHealth\Api\DeviceRequest;
use App\Classes\eHealth\Api\Job;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StandaloneDeviceRequestTest extends TestCase
{
    public static function signedPayloads(): iterable
    {
        yield 'legacy alias' => [['signed_content' => 'kep'], ['signed_device_request_request' => 'kep', 'signed_content_encoding' => 'base64']];
        yield 'canonical' => [['signed_device_request_request' => 'kep'], ['signed_device_request_request' => 'kep', 'signed_content_encoding' => 'base64']];
        yield 'explicit encoding' => [['signed_device_request_request' => 'kep', 'signed_content_encoding' => 'custom'], ['signed_device_request_request' => 'kep', 'signed_content_encoding' => 'custom']];
    }

    #[DataProvider('signedPayloads')]
    public function test_sign_uses_the_standalone_endpoint_and_preserves_the_existing_envelope(array $input, array $expected): void
    {
        $response = $this->response(['id' => 'device-id']);
        $api = Mockery::mock(DeviceRequest::class)->makePartial();
        $api->shouldReceive('patch')->once()->with('/api/device_requests/device-id/sign', $expected)->andReturn($response);

        $this->assertSame($response, $api->signDeviceRequest('device-id', $input));
    }

    public function test_create_and_reject_keep_their_standalone_endpoints(): void
    {
        $response = $this->response(['id' => 'device-id']);
        $api = Mockery::mock(DeviceRequest::class)->makePartial();
        $api->shouldReceive('post')->once()->with('/api/device_requests', ['quantity' => 2])->andReturn($response);
        $api->shouldReceive('patch')->once()->with('/api/device_requests/device-id/actions/reject', ['reason' => 'error'])->andReturn($response);

        $this->assertSame($response, $api->createDeviceRequest(['quantity' => 2]));
        $this->assertSame($response, $api->rejectDeviceRequest('device-id', ['reason' => 'error']));
    }

    public function test_prequalify_resolves_the_job_before_rejecting_an_invalid_verdict(): void
    {
        $api = Mockery::mock(DeviceRequest::class)->makePartial();
        $api->shouldReceive('preQualify')->once()->with(['programs' => [['id' => 'program-id']]])
            ->andReturn($this->response(['job_id' => 'qualify-job']));
        $job = Mockery::mock(Job::class)->makePartial();
        $job->shouldReceive('resolve')->once()->with(['job_id' => 'qualify-job'])
            ->andReturn(['data' => [['status' => 'INVALID', 'rejection_reason' => 'Not covered']]]);
        $this->instance(Job::class, $job);

        $this->expectException(EHealthValidationException::class);
        $api->prequalifyAndValidate(['programs' => [['id' => 'program-id']]]);
    }

    public function test_create_keeps_the_original_document_when_the_job_returns_only_metadata(): void
    {
        $raw = ['id' => 'device-id', 'code' => ['coding' => []], 'unknown_extension' => ['zero' => 0, 'flag' => false]];
        $response = ['data' => $raw, 'job_id' => 'create-job'];
        $api = Mockery::mock(DeviceRequest::class)->makePartial();
        $api->shouldReceive('createDeviceRequest')->once()->with(['quantity' => 2])->andReturn($this->response($response));
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('resolve')->once()->with($response)->andReturn(['id' => 'device-id', 'status' => 'processed']);
        $this->instance(Job::class, $job);

        $result = $api->createAndResolve(['quantity' => 2]);

        $this->assertSame('device-id', $result->uuid());
        $this->assertSame($raw, $result->document());
    }

    public function test_sign_propagates_a_failed_job_instead_of_returning_success(): void
    {
        $api = Mockery::mock(DeviceRequest::class)->makePartial();
        $api->shouldReceive('signDeviceRequest')->once()->with('device-id', ['signed_device_request_request' => 'kep'])
            ->andReturn($this->response(['job_id' => 'sign-job']));
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('resolve')->once()->with(['job_id' => 'sign-job'])
            ->andThrow(new EHealthValidationException(['error' => ['message' => 'Signing rejected']]));
        $this->instance(Job::class, $job);

        $this->expectException(EHealthValidationException::class);
        $api->signAndResolve('device-id', ['signed_device_request_request' => 'kep']);
    }

    private function response(array $data): EHealthResponse
    {
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('getData')->andReturn($data);

        return $response;
    }
}
