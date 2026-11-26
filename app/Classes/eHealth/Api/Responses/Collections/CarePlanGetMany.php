<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Responses\Collections;

use Illuminate\Support\Collection;

/**
 * @extends Collection<int, CarePlanSummary>
 */
class CarePlanGetMany extends Collection
{
    public function __construct(iterable|null $items = [])
    {
        parent::__construct($items);

        $this->items = array_map(
            static fn (array|CarePlanSummary $item): CarePlanSummary => $item instanceof CarePlanSummary
                ? $item
                : new CarePlanSummary($item),
            $this->items
        );
    }
}
