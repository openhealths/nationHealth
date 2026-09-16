<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MedicalEvents;

use App\Enums\DeviceDispense\Status;
use App\Services\MedicalEvents\Mappers\DeviceDispenseMapper;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DeviceDispenseMapperTest extends TestCase
{
    #[Test]
    public function to_fhir_maps_based_on_device_request_and_quantity_code(): void
    {
        $mapper = new DeviceDispenseMapper();
        $basedOnId = (string) Str::uuid();
        $encounterUuid = (string) Str::uuid();

        $payload = $mapper->toFhir([
            'basedOnId' => $basedOnId,
            'performerId' => (string) Str::uuid(),
            'locationId' => (string) Str::uuid(),
            'whenHandedOverDate' => '15.09.2026',
            'whenHandedOverTime' => '10:15',
            'quantity' => 2,
            'quantityCode' => 'piece',
            'deviceSelectionType' => 'type',
            'deviceCode' => '30221',
            'status' => Status::COMPLETED->value,
        ], [
            'encounter' => $encounterUuid,
        ]);

        $this->assertSame($basedOnId, data_get($payload, 'basedOn.identifier.value'));
        $this->assertSame('device_request', data_get($payload, 'basedOn.identifier.type.coding.0.code'));
        $this->assertSame($encounterUuid, data_get($payload, 'encounter.identifier.value'));
        $this->assertSame(2, data_get($payload, 'details.0.quantity.value'));
        $this->assertSame('piece', data_get($payload, 'details.0.quantity.code'));
        $this->assertSame('30221', data_get($payload, 'details.0.deviceCode.coding.0.code'));
    }

    #[Test]
    public function to_fhir_maps_model_without_based_on(): void
    {
        $mapper = new DeviceDispenseMapper();
        $deviceDefinitionId = (string) Str::uuid();

        $payload = $mapper->toFhir([
            'performerId' => (string) Str::uuid(),
            'locationId' => (string) Str::uuid(),
            'whenHandedOverDate' => '15.09.2026',
            'whenHandedOverTime' => '11:00',
            'quantity' => 1,
            'deviceSelectionType' => 'model',
            'deviceDefinitionId' => $deviceDefinitionId,
        ], [
            'encounter' => (string) Str::uuid(),
        ]);

        $this->assertArrayNotHasKey('basedOn', $payload);
        $this->assertSame($deviceDefinitionId, data_get($payload, 'details.0.device.identifier.value'));
    }
}
