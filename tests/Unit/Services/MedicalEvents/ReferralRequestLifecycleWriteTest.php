<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MedicalEvents;

use App\Classes\eHealth\Api\Patient\DeviceRequest as DeviceRequestApi;
use App\Classes\eHealth\Api\Patient\ServiceRequest as ServiceRequestApi;
use App\Classes\eHealth\EHealthResponse;
use App\Repositories\MedicalEvents\ServiceRequestRequestRepository;

use App\Services\MedicalEvents\EHealthJobResolver;
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class ReferralRequestLifecycleWriteTest extends TestCase
{
    public function test_submit_signed_create_routes_service_and_device_requests(): void
    {
        $payload = [
            'signed_data' => 'signed',
            'signed_data_encoding' => 'base64',
        ];

        $serviceResponse = Mockery::mock(EHealthResponse::class);
        $serviceResponse->shouldReceive('getStatusCode')->andReturn(200);
        $serviceResponse->shouldReceive('validate')->once()->andReturn(['id' => 'sr-uuid', 'status' => 'active']);
        $serviceApi = Mockery::mock(ServiceRequestApi::class)->makePartial();
        $serviceApi->shouldReceive('createSigned')->once()->with('person-uuid', $payload)->andReturn($serviceResponse);
        $this->instance(ServiceRequestApi::class, $serviceApi);

        $deviceResponse = Mockery::mock(EHealthResponse::class);
        $deviceResponse->shouldReceive('validate')->once()->andReturn(['job_id' => 'job-dr']);
        $deviceApi = Mockery::mock(DeviceRequestApi::class);
        $deviceApi->shouldReceive('createSigned')->once()->with('person-uuid', $payload)->andReturn($deviceResponse);
        $this->instance(DeviceRequestApi::class, $deviceApi);

        $resolver = Mockery::mock(EHealthJobResolver::class);
        $resolver->shouldReceive('resolve')
            ->once()
            ->andReturn(['id' => 'dr-uuid']);
        $this->app->instance(EHealthJobResolver::class, $resolver);

        $service = app(ReferralRequestLifecycleService::class);

        $this->assertSame('sr-uuid', $service->submitSignedCreate('service_request', 'person-uuid', 'signed')['id']);
        $this->assertSame('dr-uuid', $service->submitSignedCreate('device_request', 'person-uuid', 'signed')['id']);
    }

    public function test_confirmed_signed_cancel_and_recall_do_not_poll_jobs(): void
    {
        $this->mock(ServiceRequestRequestRepository::class)
            ->shouldReceive('findByUuid')->with('sr-uuid')->twice()->andReturnNull();
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
        $cancelResponse->shouldReceive('getStatusCode')->andReturn(200);
        $cancelResponse->shouldReceive('validate')->once()->andReturn(['status' => 'entered-in-error']);
        $recallResponse = Mockery::mock(EHealthResponse::class);
        $recallResponse->shouldReceive('getStatusCode')->andReturn(200);
        $recallResponse->shouldReceive('validate')->once()->andReturn(['status' => 'recalled']);

        $api = Mockery::mock(ServiceRequestApi::class)->makePartial();
        $api->shouldReceive('cancel')->once()->with('person-uuid', 'sr-uuid', array_intersect_key($cancelPayload, array_flip(['signed_data', 'signed_data_encoding'])))->andReturn($cancelResponse);
        $api->shouldReceive('recall')->once()->with('person-uuid', 'sr-uuid', array_intersect_key($recallPayload, array_flip(['signed_data', 'signed_data_encoding'])))->andReturn($recallResponse);
        $this->instance(ServiceRequestApi::class, $api);

        $resolver = Mockery::mock(EHealthJobResolver::class);
        $resolver->shouldNotReceive('resolve');
        $this->app->instance(EHealthJobResolver::class, $resolver);

        $service = app(ReferralRequestLifecycleService::class);

        $this->assertSame('entered-in-error', $service->submitSignedCancel(
            'service_request',
            'person-uuid',
            'sr-uuid',
            $cancelPayload
        )['status']);
        $this->assertSame('recalled', $service->submitSignedRecall('person-uuid', 'sr-uuid', $recallPayload)['status']);
    }

    public function test_remote_only_signed_mutations_return_pending_receipts_without_polling(): void
    {
        $this->mock(ServiceRequestRequestRepository::class)
            ->shouldReceive('findByUuid')->with('sr-uuid')->andReturnNull();
        $resolver = Mockery::mock(EHealthJobResolver::class);
        $resolver->shouldNotReceive('resolve');
        $this->instance(EHealthJobResolver::class, $resolver);
        $api = Mockery::mock(ServiceRequestApi::class);
        $pending = [
            ['id' => 'sr-uuid', 'status' => 'active'],
            ['job_id' => 'synthetic-job', 'status' => 'pending'],
            ['links' => [['href' => '/service_requests/sr-uuid'], ['href' => '/jobs/synthetic-job']], 'status' => 'pending'],
            ['status' => 'pending'],
            ['status' => 'accepted'],
            ['status' => 'queued'],
        ];
        foreach ($pending as $index => $data) {
            $response = new EHealthResponse(new Response($index === 0 ? 202 : 200, [], json_encode(['data' => $data], JSON_THROW_ON_ERROR)), ServiceRequestApi::validateSubmission(...));
            $api->shouldReceive('createSigned')->once()->andReturn($response);
            $api->shouldReceive('cancel')->once()->andReturn($response);
            $api->shouldReceive('recall')->once()->andReturn($response);
        }
        $this->instance(ServiceRequestApi::class, $api);
        $service = app(ReferralRequestLifecycleService::class);
        foreach ($pending as $data) {
            $payload = ['signed_data' => 'synthetic-signature', 'explanatory_letter' => 'Synthetic explanation'];
            $this->assertSame(['submissionPending' => true] + $data, $service->submitSignedCreate('service_request', 'person-uuid', 'synthetic-signature'));
            $this->assertSame(['submissionPending' => true] + $data, $service->submitSignedCancel('service_request', 'person-uuid', 'sr-uuid', $payload));
            $this->assertSame(['submissionPending' => true] + $data, $service->submitSignedRecall('person-uuid', 'sr-uuid', $payload));
        }
    }

    public function test_recall_requires_an_explanatory_letter(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ReferralRequestLifecycleService::class)->submitSignedRecall('person-uuid', 'sr-uuid', [
            'signed_data' => 'signed',
            'explanatory_letter' => '   ',
        ]);
    }

    public function test_extract_signed_create_entity_unwraps_job_result_data(): void
    {
        $service = app(ReferralRequestLifecycleService::class);
        $method = new ReflectionMethod(ReferralRequestLifecycleService::class, 'extractSignedCreateEntity');
        $method->setAccessible(true);

        $entity = $method->invoke($service, [
            'status' => 'processed',
            'result' => [
                'data' => [
                    'id' => 'sr-uuid',
                    'status' => 'active',
                    'requisition' => '0000-AAAA-BBBB-CCCC',
                ],
            ],
        ]);

        $this->assertSame('sr-uuid', $entity['id']);
        $this->assertSame('active', $entity['status']);
        $this->assertSame('0000-AAAA-BBBB-CCCC', $entity['requisition']);
    }
}
