<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Dto\ServiceRequest\Input as ServiceRequestInput;
use App\Dto\ServiceRequest\EhealthCreate as ServiceRequestCreateData;
use App\Dto\ServiceRequest\EhealthPrequalify as ServiceRequestPrequalifyData;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceRequestContractTest extends TestCase
{
    #[Test]
    public function prequalify_keeps_programs_envelope(): void
    {
        $programId = (string) Str::uuid();
        $payload = app(ObjectMapperInterface::class)->map(ServiceRequestInput::fromArray(
            $this->serviceData($programId),
            $this->uuids(),
            CarbonImmutable::now(),
            (string) Str::uuid(),
            (string) Str::uuid()
        ), ServiceRequestPrequalifyData::class)->toArray();

        $this->assertArrayHasKey('service_request', $payload);
        $this->assertArrayHasKey('programs', $payload);
        $this->assertArrayNotHasKey('program', $payload['service_request']);
        $this->assertSame($programId, $payload['programs'][0]['identifier']['value']);
    }

    #[Test]
    public function create_signed_content_is_flat_service_request_with_program(): void
    {
        $programId = (string) Str::uuid();
        $requestId = (string) Str::uuid();

        $payload = app(ObjectMapperInterface::class)->map(ServiceRequestInput::fromArray(
            $this->serviceData($programId, $requestId),
            $this->uuids(),
            CarbonImmutable::now(),
            (string) Str::uuid(),
            (string) Str::uuid()
        ), ServiceRequestCreateData::class)->toArray();

        $this->assertArrayNotHasKey('service_request', $payload);
        $this->assertArrayNotHasKey('programs', $payload);
        $this->assertSame($requestId, $payload['id']);
        $this->assertSame('active', $payload['status']);
        $this->assertArrayHasKey('requester_employee', $payload);
        $this->assertSame($programId, $payload['program']['identifier']['value']);
        $this->assertSame('medical_program', $payload['program']['identifier']['type']['coding'][0]['code']);
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceData(string $programId, ?string $requestId = null): array
    {
        $data = [
            'service_id' => (string) Str::uuid(),
            'quantity' => 1.0,
            'quantity_system' => 'SERVICE_UNIT',
            'quantity_code' => 'PIECE',
            'intent' => 'order',
            'category' => 'diagnostic_procedure',
            'program_id' => $programId,
            'priority' => 'routine',
            'started_at' => '2026-08-15',
            'ended_at' => '2026-11-15',
        ];

        if ($requestId !== null) {
            $data['uuid'] = $requestId;
        }

        return $data;
    }

    /**
     * @return array<string, string>
     */
    private function uuids(): array
    {
        return [
            'person_uuid' => (string) Str::uuid(),
            'encounter_uuid' => (string) Str::uuid(),
            'employee_uuid' => (string) Str::uuid(),
            'legal_entity_uuid' => (string) Str::uuid(),
        ];
    }
}
