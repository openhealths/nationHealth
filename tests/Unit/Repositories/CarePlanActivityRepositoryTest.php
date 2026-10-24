<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Classes\eHealth\Api\CarePlanActivity as ActivityApi;
use App\Classes\eHealth\EHealthResponse;
use App\Core\Arr;
use App\Services\MedicalEvents\Mappers\DeviceRequestMapper;
use GuzzleHttp\Psr7\Response;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Contract scenarios retained after moving payload construction out of repositories. */
class CarePlanActivityRepositoryTest extends TestCase
{
    public function test_device_request_quantity_is_integer_in_payload(): void
    {
        $resource = $this->fixture();
        $resource['detail']['kind'] = 'device_request';
        $resource['detail']['quantity']['value'] = 1;
        $payload = app(ActivityApi::class)->buildCreateContent($resource);
        $this->assertIsInt($payload['detail']['quantity']['value']);
        $this->assertSame(1, $payload['detail']['quantity']['value']);
    }

    public function test_device_payload_requires_an_explicit_product_variant(): void
    {
        $resource = $this->fixture();
        $resource['detail']['productCodeableConcept'] = ['coding' => [['system' => 'synthetic/classification', 'code' => 'TEST']]];
        $this->expectException(ValidationException::class);
        app(ActivityApi::class)->buildCreateContent($resource);
    }

    public function test_build_device_prequalify_payload_includes_occurrence_period(): void
    {
        $deviceUuid = '0fa1e6cd-7066-4881-92a5-6d747a1128f7';
        $payload = (new DeviceRequestMapper())->toPrequalifyPayload([
            'device_id' => $deviceUuid, 'quantity' => 100,
            'started_at' => '2026-06-27', 'ended_at' => '2026-07-06',
        ], ['person_uuid' => 'patient', 'encounter_uuid' => 'encounter', 'employee_uuid' => 'employee', 'legal_entity_uuid' => 'entity'], 'plan', 'activity');
        $this->assertArrayHasKey('start', $payload['device_request']['occurrence_period']);
        $this->assertArrayHasKey('end', $payload['device_request']['occurrence_period']);
        $this->assertArrayNotHasKey('code', $payload['device_request']);
        $this->assertSame($deviceUuid, $payload['device_request']['code_reference']['identifier']['value']);
    }

    public function test_precise_activity_schedule_is_not_rebuilt_during_signing(): void
    {
        $resource = $this->fixture();
        $resource['detail']['scheduledPeriod']['start'] = '2026-06-25T16:33:00.123456Z';
        $payload = app(ActivityApi::class)->buildCreateContent($resource);
        $this->assertSame('2026-06-25T16:33:00.123456Z', $payload['detail']['scheduled_period']['start']);
    }

    public function test_payload_author_comes_from_the_activity_not_the_current_session(): void
    {
        $resource = $this->fixture();
        $payload = app(ActivityApi::class)->buildCreateContent($resource);
        $this->assertSame($resource['author'][0]['identifier']['value'], $payload['author'][0]['identifier']['value']);
        $this->assertCount(2, $payload['author']);
    }

    public function test_payload_rejects_an_activity_without_an_author(): void
    {
        $resource = $this->fixture();
        $resource['author'] = [];
        $this->expectException(ValidationException::class);
        app(ActivityApi::class)->buildCreateContent($resource);
    }

    public function test_create_content_excludes_server_fields_and_preserves_false(): void
    {
        $resource = $this->fixture();
        $resource['insertedAt'] = '2026-06-25T18:14:41.040000Z';
        $payload = app(ActivityApi::class)->buildCreateContent($resource);
        $this->assertArrayNotHasKey('inserted_at', $payload);
        $this->assertArrayNotHasKey('remaining_quantity', $payload['detail']);
        $this->assertArrayNotHasKey('outcome_reference', $payload);
        $this->assertFalse($payload['detail']['do_not_perform']);
    }

    public function test_build_activity_cancel_sign_payload_adds_status_reason_to_full_snapshot(): void
    {
        $base = Arr::toSnakeCase($this->fixture());
        $base['remote_extension'] = ['false' => false, 'zero' => 0, 'empty' => []];
        $reason = ['coding' => [['system' => 'eHealth/care_plan_activity_cancel_reasons', 'code' => 'TEST']]];
        $signed = app(ActivityApi::class)->buildCancelContent($base, $reason);
        $this->assertSame($reason, $signed['detail']['status_reason']);
        unset($signed['detail']['status_reason']);
        $this->assertSame($base, $signed);
    }

    public function test_normalized_read_wraps_author_object_as_list(): void
    {
        $raw = Arr::toSnakeCase($this->fixture());
        $raw['author'] = $raw['author'][0];
        $api = new class extends ActivityApi
        {
            public function normalize(EHealthResponse $response): array
            {
                return $this->validateDetails($response);
            }
        };
        $normalized = $api->normalize(new EHealthResponse(new Response(200, [], json_encode(['data' => $raw], JSON_THROW_ON_ERROR))));
        $this->assertTrue(array_is_list($normalized['author']));
        $this->assertSame($raw['author']['identifier']['value'], $normalized['author'][0]['identifier']['value']);
    }

    public function test_build_activity_cancel_sign_payload_leaves_the_ehealth_author_shape_alone(): void
    {
        $base = Arr::toSnakeCase($this->fixture());
        $base['author'] = $base['author'][0];
        $reason = ['coding' => [['system' => 'eHealth/care_plan_activity_cancel_reasons', 'code' => 'TEST']]];
        $signed = app(ActivityApi::class)->buildCancelContent($base, $reason);
        $this->assertFalse(array_is_list($signed['author']));
        $this->assertSame($base['author'], $signed['author']);
    }

    private function fixture(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/CarePlan/activity.json')), true, flags: JSON_THROW_ON_ERROR);
    }
}
