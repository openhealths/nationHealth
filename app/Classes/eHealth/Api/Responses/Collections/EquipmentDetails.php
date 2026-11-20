<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Responses\Collections;

use Illuminate\Support\Collection;

/**
 * Validated equipment data shared by create, status changes,
 * and individual items returned by getMany.
 *
 * @extends Collection<string, mixed>
 */
final class EquipmentDetails extends Collection
{
    /**
     * @param iterable<string, mixed>|null $items
     */
    public function __construct(?iterable $items = [])
    {
        parent::__construct(array_replace([
            'division_id' => null,
            'parent_id' => null,
            'device_definition_id' => null,
            'serial_number' => null,
            'manufacturer' => null,
            'manufacture_date' => null,
            'model_number' => null,
            'inventory_number' => null,
            'lot_number' => null,
            'expiration_date' => null,
            'note' => null,
            'error_reason' => null,
            'properties' => null,
        ], is_array($items) ? $items : iterator_to_array($items ?? [])));
    }
}