<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Enums\DeviceDispense\Status;
use App\Dto\DeviceDispense\Ehealth;
use App\Dto\FormCollection;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Tests\TestCase;

class DeviceDispensePayloadTest extends TestCase
{
    #[Test]
    public function to_fhir_maps_based_on_device_request_and_quantity_code(): void
    {
        $mapper = app(ObjectMapperInterface::class);
        $basedOnId = (string) Str::uuid();
        $encounterUuid = (string) Str::uuid();

        $payload = $mapper->map(new FormCollection([
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
        ]), new Ehealth((string) Str::uuid(), $encounterUuid))->toArray();

        $this->assertSame($basedOnId, data_get($payload, 'based_on.identifier.value'));
        $this->assertSame('device_request', data_get($payload, 'based_on.identifier.type.coding.0.code'));
        $this->assertSame($encounterUuid, data_get($payload, 'encounter.identifier.value'));
        $this->assertSame(2, data_get($payload, 'details.0.quantity.value'));
        $this->assertSame('piece', data_get($payload, 'details.0.quantity.code'));
        $this->assertSame('30221', data_get($payload, 'details.0.device_code.coding.0.code'));
    }

    #[Test]
    public function to_fhir_maps_model_without_based_on(): void
    {
        $mapper = app(ObjectMapperInterface::class);
        $deviceDefinitionId = (string) Str::uuid();

        $payload = $mapper->map(new FormCollection([
            'performerId' => (string) Str::uuid(),
            'locationId' => (string) Str::uuid(),
            'whenHandedOverDate' => '15.09.2026',
            'whenHandedOverTime' => '11:00',
            'quantity' => 1,
            'deviceSelectionType' => 'model',
            'deviceDefinitionId' => $deviceDefinitionId,
        ]), new Ehealth((string) Str::uuid(), (string) Str::uuid()))->toArray();

        $this->assertArrayNotHasKey('based_on', $payload);
        $this->assertSame($deviceDefinitionId, data_get($payload, 'details.0.device.identifier.value'));
    }
}
