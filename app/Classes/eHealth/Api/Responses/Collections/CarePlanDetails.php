<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Responses\Collections;

use Illuminate\Support\Collection;

/**
 * Full, validated Care Plan from eHealth, with the concepts prepared for ObjectMapper.
 *
 * @extends Collection<string, mixed>
 */
class CarePlanDetails extends Collection
{
    protected array $resource;

    public function __construct(iterable|null $items = [])
    {
        parent::__construct($items);

        $this->resource = $this->all();
        foreach (['category', 'terms_of_service', 'status_reason'] as $field) {
            if (is_array($this->get($field))) {
                $this->put($field, new EhealthCodeableConcept($this->get($field)));
            }
        }
    }

    /** The validated resource as received, for the submission journal. */
    public function resource(): array
    {
        return $this->resource;
    }
}
