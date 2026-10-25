<?php

declare(strict_types=1);

namespace App\Dto\DeviceDispense;

use App\Dto\Concerns\PreservesEhealthDocumentValues;
use App\Mapping\Transforms\FhirCodeableConcept;
use App\Mapping\Transforms\FhirReference;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: Collection::class)]
final class EhealthDetail
{
    use PreservesEhealthDocumentValues;

    #[Map(source: '[quantity]', transform: [self::class, 'quantity'])]
    public array $quantity;

    #[Map(source: '[deviceDefinitionId?]', if: [self::class, 'isModel'], transform: new FhirReference('device_definition', includeText: true))]
    public array $device;

    #[Map(source: '[deviceCode?]', if: [self::class, 'isType'], transform: new FhirCodeableConcept('device_definition_classification_type', includeText: true))]
    public array $deviceCode;

    public static function quantity(mixed $value, Collection $source): array
    {
        return ['value' => (int) $value, 'system' => 'device_unit', 'code' => $source['quantityCode'] ?? 'piece'];
    }

    public static function isModel(mixed $value, Collection $source): bool
    {
        return $source['deviceSelectionType'] === 'model';
    }

    public static function isType(mixed $value, Collection $source): bool
    {
        return !self::isModel($value, $source);
    }
}
