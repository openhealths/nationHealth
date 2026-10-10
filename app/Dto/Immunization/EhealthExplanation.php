<?php

declare(strict_types=1);

namespace App\Dto\Immunization;

use App\Dto\Concerns\PreservesEhealthDocumentValues;
use App\Dto\Shared\ClinicalConcept;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Transform\MapCollection;

#[Map(source: Collection::class)]
final class EhealthExplanation
{
    use PreservesEhealthDocumentValues;

    #[Map(source: '[reasons?]', transform: [[self::class, 'reasonRows'], new MapCollection(targetClass: ClinicalConcept::class), [self::class, 'reasonResult']])]
    public ?array $reasons;

    #[Map(source: '[reasonNotGivenCode?]', transform: [[self::class, 'notGivenRows'], new MapCollection(targetClass: ClinicalConcept::class), [self::class, 'notGivenResult']])]
    public ?array $reasonsNotGiven;

    public static function reasonRows(?array $value, Collection $source): array
    {
        return $source['notGiven'] ? [] : collect($value ?? [])->filter(static fn (array $row): bool => !empty($row['code']))->map(static fn (array $row): Collection => new Collection(['code' => $row['code'], 'system' => 'eHealth/reason_explanations']))->values()->all();
    }

    public static function reasonResult(array $value, Collection $source): ?array
    {
        return $source['notGiven'] ? null : $value;
    }

    public static function notGivenRows(mixed $value, Collection $source): array
    {
        return $source['notGiven'] ? [new Collection(['code' => $value, 'system' => 'eHealth/reason_not_given_explanations'])] : [];
    }

    public static function notGivenResult(array $value, Collection $source): ?array
    {
        return $source['notGiven'] ? $value : null;
    }
}
