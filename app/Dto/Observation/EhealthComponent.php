<?php

declare(strict_types=1);

namespace App\Dto\Observation;

use App\Dto\Concerns\PreservesEhealthDocumentValues;
use App\Mapping\Transforms\FhirCodeableConcept;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: Collection::class)]
final class EhealthComponent
{
    use PreservesEhealthDocumentValues;

    #[Map(source: '[codeCode]', transform: [self::class, 'codeValue'])]
    public array $code;

    #[Map(source: '[interpretationCode]', transform: new FhirCodeableConcept('eHealth/observation_interpretations', includeText: true))]
    public array $interpretation;

    #[Map(source: '[valueCode]', transform: [self::class, 'conceptValue'])]
    public array $valueCodeableConcept;

    public static function codeValue(string $value, Collection $source): array
    {
        return new FhirCodeableConcept($source['codeSystem'] ?? 'eHealth/ICF/qualifiers', includeText: true)($value, $source, null);
    }

    public static function conceptValue(string $value, Collection $source): array
    {
        return new FhirCodeableConcept($source['valueSystem'], includeText: true)($value, $source, null);
    }
}
