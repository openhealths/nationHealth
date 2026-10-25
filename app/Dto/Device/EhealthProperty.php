<?php

declare(strict_types=1);

namespace App\Dto\Device;

use App\Dto\EhealthMapping;
use App\Mapping\Transforms\FhirCodeableConcept;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;

#[Map(source: Collection::class)]
final class EhealthProperty
{
    use EhealthMapping;

    #[Map(source: '[code]', transform: new FhirCodeableConcept('device_properties', includeText: true))]
    public array $code;

    #[Map(source: '[valueCodeableConceptCode?]', if: [self::class, 'present'], transform: [self::class, 'concept'])]
    public array $valueCodeableConcept;

    #[Map(source: '[valueQuantityValue?]', if: [self::class, 'present'], transform: [self::class, 'quantity'])]
    public array $valueQuantity;

    #[Map(source: '[valueRangeLowValue?]', if: [self::class, 'present'], transform: [self::class, 'range'])]
    public array $valueRange;

    #[Map(source: '[valueBoolean?]', if: [self::class, 'present'])]
    public mixed $valueBoolean;

    #[Map(source: '[valueInteger?]', if: [self::class, 'present'])]
    public mixed $valueInteger;

    #[Map(source: '[valueString?]', if: [self::class, 'present'])]
    public mixed $valueString;

    public static function present(mixed $value): bool
    {
        return $value !== null;
    }

    public static function concept(string $value, Collection $source): array
    {
        return new FhirCodeableConcept($source['valueCodeableConceptSystem'], includeText: true)($value, $source, null);
    }

    public static function quantity(mixed $value, Collection $source): array
    {
        return [
            'value' => $value, 'comparator' => $source['valueQuantityComparator'] ?? null,
            'unit' => $source['valueQuantityUnit'], 'system' => $source['valueQuantitySystem'] ?? null,
            'code' => $source['valueQuantityCode'] ?? null,
        ];
    }

    public static function range(mixed $value, Collection $source): array
    {
        return [
            'low' => [
                'value' => $value, 'unit' => $source['valueRangeLowUnit'],
                'system' => $source['valueRangeLowSystem'] ?? null, 'code' => $source['valueRangeLowCode'] ?? null,
            ],
            'high' => [
                'value' => $source['valueRangeHighValue'], 'unit' => $source['valueRangeHighUnit'],
                'system' => $source['valueRangeHighSystem'] ?? null, 'code' => $source['valueRangeHighCode'] ?? null,
            ],
        ];
    }

    protected function normalizeMappedData(array $data, CamelCaseToSnakeCaseNameConverter $converter): array
    {
        $order = array_flip(['code', 'value_codeable_concept', 'value_quantity', 'value_range', 'value_boolean', 'value_integer', 'value_string']);

        return array_replace(array_intersect_key($order, $data), $data);
    }
}
