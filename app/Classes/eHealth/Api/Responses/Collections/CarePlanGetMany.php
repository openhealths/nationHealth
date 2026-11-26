<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Responses\Collections;

use Illuminate\Support\Collection;

/**
 * Validated care plans returned by the requisition search.
 *
 * @extends Collection<int, CarePlanDetails>
 */
class CarePlanGetMany extends Collection
{
    public function __construct(iterable|null $items = [])
    {
        parent::__construct($items);

        $this->items = array_map(
            static fn (array|CarePlanDetails $item): CarePlanDetails => $item instanceof CarePlanDetails
                ? $item
                : new CarePlanDetails($item),
            $this->items
        );
    }
}
