<?php

declare(strict_types=1);

namespace App\Dto\DeviceDispense;

use App\Dto\Concerns\PreservesEhealthDocumentValues;
use App\Mapping\Transforms\FhirIdentifier;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: Collection::class)]
final class EhealthSupportingInfo
{
    use PreservesEhealthDocumentValues;

    #[Map(source: '[uuid]', transform: [self::class, 'identifier'])]
    public array $identifier;

    public static function identifier(string $value, Collection $source): array
    {
        return new FhirIdentifier($source['type'], includeText: true)($value, $source, null);
    }
}
