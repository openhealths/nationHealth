<?php

declare(strict_types=1);

namespace App\Dto\Encounter;

use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Transform\MapCollection;

#[Map(source: Collection::class)]
final class EhealthCancelledSection
{
    #[Map(source: '[section]')]
    public string $section;

    #[Map(source: '[records]', transform: [[self::class, 'rows'], new MapCollection(targetClass: EhealthCancelledRecord::class)])]
    public array $records;

    public static function rows(?array $value): array
    {
        return array_map(static fn (array $row): Collection => new Collection($row), $value ?? []);
    }

    public function toArray(): array
    {
        return array_map(static fn (object $row): array => $row->toArray(), $this->records);
    }
}
