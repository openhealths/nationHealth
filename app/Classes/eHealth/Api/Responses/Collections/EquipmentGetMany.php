<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Responses\Collections;

use Illuminate\Support\Collection;

/**
 * @extends Collection<int, EquipmentDetails>
 */
final class EquipmentGetMany extends Collection
{
    /**
     * @param iterable<array<string, mixed>|EquipmentDetails> $items
     */
    public function __construct(iterable $items = [])
    {
        $equipment = [];

        foreach ($items as $item) {
            $equipment[] = $item instanceof EquipmentDetails ? $item : new EquipmentDetails($item);
        }

        parent::__construct($equipment);
    }
}