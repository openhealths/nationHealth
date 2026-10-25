<?php

declare(strict_types=1);

namespace App\Dto\ClinicalImpression;

use App\Dto\Concerns\PreservesEhealthDocumentValues;
use App\Mapping\Transforms\FhirReference;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: Collection::class)]
final class EhealthFinding
{
    use PreservesEhealthDocumentValues;

    #[Map(source: '[id]', transform: [self::class, 'referenceValue'])]
    public array $itemReference;

    #[Map(source: '[basis?]', transform: [self::class, 'optionalValue'])]
    public mixed $basis;

    public static function referenceValue(string $value, Collection $source): array
    {
        return new FhirReference($source['type'], includeText: true)($value, $source, null);
    }

    public static function optionalValue(mixed $value): mixed
    {
        return empty($value) ? null : $value;
    }
}
