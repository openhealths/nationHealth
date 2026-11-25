<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Responses\Collections;

use Illuminate\Support\Collection;

/**
 * Validated short care plans. Full details are fetched before replacing clinical relations.
 *
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
