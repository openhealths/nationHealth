<?php

declare(strict_types=1);

namespace App\Dto\Shared;

use App\Dto\Concerns\PreservesEhealthDocumentValues;
use App\Mapping\Transforms\FhirIdentifier;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;

/** FHIR document reference with the legacy clinical identifier text contract. */
#[Map(source: Collection::class)]
final class ClinicalReference
{
    use PreservesEhealthDocumentValues;
    #[Map(source: '[uuid]', transform: [self::class, 'identifierValue'])]
    public array $identifier;
    public static function identifierValue(string $value, Collection $source): array
    {
        $identifier = new FhirIdentifier($source['type'], includeText: true)($value, $source, null);
        $identifier['type']['text'] = $source['text'] ?? '';

        return $identifier;
    }
}
