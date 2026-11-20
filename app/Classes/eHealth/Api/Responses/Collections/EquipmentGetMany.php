<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Responses\Collections;

use Illuminate\Support\Collection;

/**
 * Validated list of equipment.
 * Each item has the same structure as a single equipment response, so it is wrapped as EquipmentDetails.
 *
 * @extends Collection<int, EquipmentDetails>
 */
class EquipmentGetMany extends Collection
{
    /**
     * @param  iterable|null  $items
     */
    public function __construct(iterable|null $items = [])
    {
        parent::__construct($items);

        $this->items = array_map(
            static fn (mixed $item): mixed => is_array($item) ? new EquipmentDetails($item) : $item,
            $this->items
        );
    }
}