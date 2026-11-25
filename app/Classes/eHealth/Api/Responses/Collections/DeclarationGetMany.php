<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Responses\Collections;

use Illuminate\Support\Collection;

/**
 * @extends Collection<int, DeclarationGet>
 */
final class DeclarationGetMany extends Collection
{
    /**
     * @param iterable<array<string, mixed>|DeclarationGet> $items
     */
    public function __construct(iterable $items = [])
    {
        parent::__construct($items);

        $this->items = array_map(
            static fn (array|DeclarationGet $item): DeclarationGet => $item instanceof DeclarationGet ? $item : new DeclarationGet($item),
            $this->items
        );
    }
}