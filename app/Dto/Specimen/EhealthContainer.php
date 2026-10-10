<?php

declare(strict_types=1);

namespace App\Dto\Specimen;

use App\Dto\Concerns\PreservesEhealthDocumentValues;
use App\Mapping\Transforms\FhirCodeableConcept;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;

#[Map(source: Collection::class)]
final class EhealthContainer
{
    use PreservesEhealthDocumentValues;

    #[Map(source: '[identifier]')]
    public mixed $identifier;

    #[Map(source: '[description?]', if: [self::class, 'filled'])]
    public mixed $description;

    #[Map(source: '[typeCode?]', if: [self::class, 'filled'], transform: new FhirCodeableConcept('specimen_container_types', includeText: true))]
    public array $type;

    #[Map(source: '[capacityValue?]', if: [self::class, 'filled'], transform: new UcumQuantity('capacityCode'))]
    public array $capacity;

    #[Map(source: '[specimenQuantityValue?]', if: [self::class, 'filled'], transform: new UcumQuantity('specimenQuantityCode'))]
    public array $specimenQuantity;

    #[Map(source: '[additiveCode?]', if: [self::class, 'filled'], transform: new FhirCodeableConcept('specimen_container_additives', includeText: true))]
    public array $additiveCodeableConcept;

    public static function filled(mixed $value): bool
    {
        return !empty($value);
    }

    protected function normalizeMappedData(array $data, CamelCaseToSnakeCaseNameConverter $converter): array
    {
        return ['identifier' => $this->identifier, ...$data];
    }
}
