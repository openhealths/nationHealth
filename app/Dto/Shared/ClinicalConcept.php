<?php

declare(strict_types=1);

namespace App\Dto\Shared;

use App\Dto\Concerns\PreservesEhealthDocumentValues;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;

/** One clinical coding plus text; lists of concepts use MapCollection. */
#[Map(source: Collection::class)]
final class ClinicalConcept
{
    use PreservesEhealthDocumentValues;
    #[Map(source: '[code]', transform: [self::class, 'codingValue'])]
    public array $coding;
    #[Map(source: '[text?]', transform: [self::class, 'textValue'])]
    public mixed $text;
    public static function codingValue(string $value, Collection $source): array
    {
        return [['system' => $source['system'], 'code' => $value]];
    }
    public static function textValue(mixed $value): mixed
    {
        return $value ?? '';
    }
}
