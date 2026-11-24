<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Responses\Collections;

use Illuminate\Support\Collection;

/**
 * Validated care plan details. Codeable concepts are prepared for the shared Model DTOs.
 *
 * @extends Collection<string, mixed>
 */
class CarePlanDetails extends Collection
{
    public function __construct(iterable|null $items = [])
    {
        parent::__construct($items);

        foreach (['category', 'termsOfService', 'statusReason'] as $field) {
            if (is_array($this->get($field))) {
                $this->put($field, new EhealthCodeableConcept($this->get($field)));
            }
        }
    }
}
