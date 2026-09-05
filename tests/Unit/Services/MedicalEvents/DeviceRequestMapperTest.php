<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MedicalEvents;

use App\Services\MedicalEvents\Mappers\DeviceRequestMapper;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DeviceRequestMapperTest extends TestCase
{
    #[Test]
    public function prequalify_authored_on_is_current_utc_not_in_the_future(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-13T07:38:24Z'));

        $mapper = new DeviceRequestMapper();
        $payload = $mapper->toPrequalifyPayload(
            [
                'device_id' => '0fa1e6cd-7066-4881-92a5-6d747a1128f7',
                'device_code_type' => 'DEVICE_DEFINITION',
                'quantity' => 50,
                'quantity_code' => 'piece',
                'intent' => 'order',
                'program_id' => (string) Str::uuid(),
                'started_at' => '2026-08-13',
                'ended_at' => '2026-11-12',
            ],
            [
                'person_uuid' => (string) Str::uuid(),
                'encounter_uuid' => (string) Str::uuid(),
                'employee_uuid' => (string) Str::uuid(),
                'legal_entity_uuid' => (string) Str::uuid(),
            ],
            (string) Str::uuid(),
            (string) Str::uuid()
        );

        $authoredOn = $payload['device_request']['authored_on'];
        $this->assertSame('2026-08-13T07:37:54.000Z', $authoredOn);

        $occurrenceStart = CarbonImmutable::parse($payload['device_request']['occurrence_period']['start']);
        $this->assertTrue($occurrenceStart->lessThanOrEqualTo(CarbonImmutable::now('UTC')->addMinute()));

        CarbonImmutable::setTestNow();
    }

    #[Test]
    public function prequalify_maps_device_quantity_as_integer_package_units(): void
    {
        $mapper = new DeviceRequestMapper();
        $payload = $mapper->toPrequalifyPayload(
            [
                'device_id' => '0fa1e6cd-7066-4881-92a5-6d747a1128f7',
                'device_code_type' => 'DEVICE_DEFINITION',
                'quantity' => 50,
                'quantity_code' => 'piece',
                'intent' => 'order',
                'started_at' => '2026-08-13',
                'ended_at' => '2026-11-12',
            ],
            [
                'person_uuid' => (string) Str::uuid(),
                'encounter_uuid' => (string) Str::uuid(),
                'employee_uuid' => (string) Str::uuid(),
                'legal_entity_uuid' => (string) Str::uuid(),
            ],
            (string) Str::uuid(),
            (string) Str::uuid()
        );

        $this->assertSame(50, $payload['device_request']['quantity']['value']);
        $this->assertSame('device_unit', $payload['device_request']['quantity']['system']);
        $this->assertSame('piece', $payload['device_request']['quantity']['code']);
    }

    #[Test]
    public function create_signed_content_is_flat_device_request_with_program_and_authored_on(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-13T07:38:24Z'));

        $mapper = new DeviceRequestMapper();
        $programId = (string) Str::uuid();
        $requestId = (string) Str::uuid();

        $payload = $mapper->toCreateSignedContent(
            [
                'uuid' => $requestId,
                'device_id' => '0fa1e6cd-7066-4881-92a5-6d747a1128f7',
                'device_code_type' => 'DEVICE_DEFINITION',
                'quantity' => 50,
                'quantity_code' => 'piece',
                'intent' => 'order',
                'program_id' => $programId,
                'started_at' => '2026-08-13',
                'ended_at' => '2026-11-12',
            ],
            [
                'person_uuid' => (string) Str::uuid(),
                'encounter_uuid' => (string) Str::uuid(),
                'employee_uuid' => (string) Str::uuid(),
                'legal_entity_uuid' => (string) Str::uuid(),
            ],
            (string) Str::uuid(),
            (string) Str::uuid()
        );

        $this->assertArrayNotHasKey('device_request', $payload);
        $this->assertArrayNotHasKey('programs', $payload);
        $this->assertArrayHasKey('authored_on', $payload);
        $this->assertSame($requestId, $payload['id']);
        $this->assertSame('active', $payload['status']);
        $this->assertSame('order', $payload['intent']);
        $this->assertArrayHasKey('encounter', $payload);
        $this->assertArrayHasKey('requester', $payload);
        $this->assertSame($programId, $payload['program']['identifier']['value']);
        $this->assertSame('medical_program', $payload['program']['identifier']['type']['coding'][0]['code']);

        CarbonImmutable::setTestNow();
    }
}
