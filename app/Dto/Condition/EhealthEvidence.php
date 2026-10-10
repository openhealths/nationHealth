<?php

declare(strict_types=1);

namespace App\Dto\Condition;

use App\Dto\Concerns\PreservesEhealthDocumentValues;
use App\Dto\Shared\ClinicalConcept;
use App\Dto\Shared\ClinicalReference;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Transform\MapCollection;

#[Map(source: Collection::class)]
final class EhealthEvidence
{
    use PreservesEhealthDocumentValues;

    #[Map(source: '[evidenceCodes?]', transform: [[self::class, 'codeRows'], new MapCollection(targetClass: ClinicalConcept::class), [self::class, 'nonempty']])]
    public ?array $codes;

    #[Map(source: '[evidenceDetails?]', transform: [[self::class, 'detailRows'], new MapCollection(targetClass: ClinicalReference::class), [self::class, 'nonempty']])]
    public ?array $details;

    public static function codeRows(?array $values): array
    {
        return array_map(static fn (array $row): Collection => new Collection(['code' => $row['code'], 'system' => $row['system'] ?? 'eHealth/ICPC2/reasons', 'text' => $row['text'] ?? '']), $values ?? []);
    }

    public static function detailRows(?array $values): array
    {
        return array_map(static fn (array $row): Collection => new Collection(['uuid' => $row['id'], 'type' => $row['type']]), $values ?? []);
    }

    public static function nonempty(array $value): ?array
    {
        return $value ?: null;
    }
}
