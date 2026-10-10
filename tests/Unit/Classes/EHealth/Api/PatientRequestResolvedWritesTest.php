<?php

declare(strict_types=1);

namespace Tests\Unit\Classes\EHealth\Api;

use App\Classes\eHealth\Api\Patient\DeviceRequest as DeviceRequestApi;
use App\Classes\eHealth\Api\Patient\ServiceRequest as ServiceRequestApi;
use App\Classes\eHealth\EHealthResponse;
use App\Classes\eHealth\Api\Job;
use Mockery;
use Tests\TestCase;

class PatientRequestResolvedWritesTest extends TestCase
{
    public function test_signed_create_routes_service_and_device_requests(): void
    {
        $payload = [
            'signed_data' => 'signed',
            'signed_data_encoding' => 'base64',
        ];

        $serviceResponse = Mockery::mock(EHealthResponse::class);
        $serviceResponse->shouldReceive('getData')->once()->andReturn(['job_id' => 'job-sr']);
        $serviceApi = Mockery::mock(ServiceRequestApi::class)->makePartial();
        $serviceApi->shouldReceive('createSigned')->once()->with('person-uuid', $payload)->andReturn($serviceResponse);
        $this->instance(ServiceRequestApi::class, $serviceApi);

        $deviceResponse = Mockery::mock(EHealthResponse::class);
        $deviceResponse->shouldReceive('getData')->once()->andReturn(['job_id' => 'job-dr']);
        $deviceApi = Mockery::mock(DeviceRequestApi::class)->makePartial();
        $deviceApi->shouldReceive('createSigned')->once()->with('person-uuid', $payload)->andReturn($deviceResponse);
        $this->instance(DeviceRequestApi::class, $deviceApi);

        $resolver = Mockery::mock(Job::class);
        $resolver->shouldReceive('resolve')
            ->twice()
            ->andReturn(['id' => 'sr-uuid'], ['id' => 'dr-uuid']);
        $this->app->instance(Job::class, $resolver);

        $this->assertSame('sr-uuid', app(ServiceRequestApi::class)->createSignedAndResolve('person-uuid', 'signed')['id']);
        $this->assertSame('dr-uuid', app(DeviceRequestApi::class)->createSignedAndResolve('person-uuid', 'signed')['id']);
    }

    public function test_signed_cancel_and_recall_resolve_jobs(): void
    {
        $cancelPayload = [
            'signed_data' => 'signed',
            'signed_data_encoding' => 'base64',
            'status_reason' => 'entered-in-error',
        ];
        $recallPayload = [
            'signed_data' => 'signed',
            'signed_data_encoding' => 'base64',
            'explanatory_letter' => 'Не потрібно',
        ];

        $cancelResponse = Mockery::mock(EHealthResponse::class);
        $cancelResponse->shouldReceive('getData')->once()->andReturn(['job_id' => 'job-cancel']);
        $recallResponse = Mockery::mock(EHealthResponse::class);
        $recallResponse->shouldReceive('getData')->once()->andReturn(['job_id' => 'job-recall']);

        $api = Mockery::mock(ServiceRequestApi::class)->makePartial();
        $api->shouldReceive('cancel')->once()->with('person-uuid', 'sr-uuid', $cancelPayload)->andReturn($cancelResponse);
        $api->shouldReceive('recall')->once()->with('person-uuid', 'sr-uuid', $recallPayload)->andReturn($recallResponse);
        $this->instance(ServiceRequestApi::class, $api);

        $resolver = Mockery::mock(Job::class);
        $resolver->shouldReceive('resolve')
            ->twice()
            ->andReturn(['status' => 'entered-in-error'], ['status' => 'recalled']);
        $this->app->instance(Job::class, $resolver);

        $this->assertSame('entered-in-error', app(ServiceRequestApi::class)->cancelAndResolve(
            'person-uuid',
            'sr-uuid',
            $cancelPayload
        )['status']);
        $this->assertSame('recalled', app(ServiceRequestApi::class)->recallAndResolve('person-uuid', 'sr-uuid', $recallPayload)['status']);
    }

    public function test_recall_requires_an_explanatory_letter(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(ServiceRequestApi::class)->recallAndResolve('person-uuid', 'sr-uuid', [
            'signed_data' => 'signed',
            'explanatory_letter' => '   ',
        ]);
    }

}
