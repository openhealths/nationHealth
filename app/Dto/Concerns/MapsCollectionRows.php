<?php

declare(strict_types=1);

namespace App\Dto\Concerns;

use Illuminate\Support\Collection;

/** Plain nested rows become object sources for MapCollection, retaining their original sequence. */
trait MapsCollectionRows
{
    /** @return list<Collection> */
    public static function collectionRows(?array $rows): array
    {
        return array_map(static fn (array $row): Collection => new Collection($row), array_values($rows ?? []));
    }
}
