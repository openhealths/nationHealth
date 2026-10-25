<?php

declare(strict_types=1);

namespace App\Dto\Device;

use App\Dto\Concerns\PreservesEhealthDocumentValues;
use App\Mapping\Transforms\FhirCodeableConcept;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: Collection::class)]
final class EhealthIdentifier
{
    use PreservesEhealthDocumentValues;

    #[Map(source: '[code]', transform: [self::class, 'identifierType'])]
    public array $type;

    #[Map(source: '[value]')]
    public mixed $value;

    public static function identifierType(string $value, Collection $source): array
    {
        return new FhirCodeableConcept('external_system')($value, $source, null) + ['text' => $source['text'] ?? ''];
    }
}
