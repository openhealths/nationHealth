<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Responses\Collections;

use Illuminate\Support\Collection;

/**
 * Validated list of healthcare services.
 * Each item has the same structure as a single healthcare service response, so it is wrapped as HealthcareServiceDetails.
 *
 * @extends Collection<int, HealthcareServiceDetails>
 */
class HealthcareServiceGetMany extends Collection
{
    /**
     * @param  iterable|null  $items
     */
    public function __construct(iterable|null $items = [])
    {
        parent::__construct($items);

        $this->items = array_map(
            static fn (array|HealthcareServiceDetails $item): HealthcareServiceDetails => $item instanceof HealthcareServiceDetails
                ? $item
                : new HealthcareServiceDetails($item),
            $this->items
        );
    }
}
