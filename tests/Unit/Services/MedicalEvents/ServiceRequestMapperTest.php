<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MedicalEvents;

use App\Services\MedicalEvents\Mappers\ServiceRequestMapper;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceRequestMapperTest extends TestCase
{
    #[Test]
    public function prequalify_keeps_programs_envelope(): void
    {
        $programId = (string) Str::uuid();
        $payload = (new ServiceRequestMapper())->toPrequalifyPayload(
            $this->serviceData($programId),
            $this->uuids(),
            (string) Str::uuid(),
            (string) Str::uuid()
        );

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

        $payload = (new ServiceRequestMapper())->toCreateSignedContent(
            $this->serviceData($programId, $requestId),
            $this->uuids(),
            (string) Str::uuid(),
            (string) Str::uuid()
        );

        $this->assertArrayNotHasKey('service_request', $payload);
        $this->assertArrayNotHasKey('programs', $payload);
        $this->assertSame($requestId, $payload['id']);
        $this->assertSame('active', $payload['status']);
        $this->assertArrayHasKey('requester_employee', $payload);
        $this->assertSame($programId, $payload['program']['identifier']['value']);
        $this->assertSame('medical_program', $payload['program']['identifier']['type']['coding'][0]['code']);
        $this->assertArrayNotHasKey('performer', $payload);
        $this->assertArrayNotHasKey('location_reference', $payload);
    }

    #[Test]
    public function transfer_of_care_includes_performer_and_location_only_for_that_category(): void
    {
        $performer = (string) Str::uuid();
        $division = (string) Str::uuid();
        $data = $this->serviceData((string) Str::uuid(), (string) Str::uuid());
        $data['category'] = 'transfer_of_care';
        $data['performer'] = $performer;
        $data['location_reference'] = $division;
        $data['performer_type'] = 'THERAPIST';

        $payload = (new ServiceRequestMapper())->toCreateSignedContent($data, $this->uuids());

        $this->assertSame($performer, $payload['performer']['identifier']['value']);
        $this->assertSame('legal_entity', $payload['performer']['identifier']['type']['coding'][0]['code']);
        $this->assertSame($division, $payload['location_reference']['identifier']['value']);
        $this->assertSame('division', $payload['location_reference']['identifier']['type']['coding'][0]['code']);
        $this->assertSame('THERAPIST', $payload['performer_type']['coding'][0]['code']);
        $this->assertSame('SPECIALITY_TYPE', $payload['performer_type']['coding'][0]['system']);

        $diagnostic = $this->serviceData((string) Str::uuid(), (string) Str::uuid());
        $diagnostic['performer'] = $performer;
        $diagnostic['location_reference'] = $division;
        $diagnostic['performer_type'] = 'THERAPIST';

        $plain = (new ServiceRequestMapper())->toCreateSignedContent($diagnostic, $this->uuids());

        $this->assertArrayNotHasKey('performer', $plain);
        $this->assertArrayNotHasKey('location_reference', $plain);
        $this->assertArrayNotHasKey('performer_type', $plain);
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
