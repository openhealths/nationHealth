<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Responses\Collections;

use Illuminate\Support\Collection;

/**
 * Validated care plan list entry with the API's snake_case keys.
 * List entries omit title, diagnoses and audit history supplied by the details endpoint.
 *
 * @extends Collection<string, mixed>
 */
class CarePlanSummary extends Collection
{
    public function __construct(iterable|null $items = [])
    {
        parent::__construct($items);

        foreach (['category', 'terms_of_service', 'status_reason'] as $field) {
            if (is_array($this->get($field))) {
                $this->put($field, new EhealthCodeableConcept($this->get($field)));
            }
        }
    }

    /** Nested coding lists must also become arrays at the signing and persistence boundaries. */
    public function toArray(): array
    {
        return $this->nativeValue($this->items);
    }

    protected function nativeValue(mixed $value): mixed
    {
        if ($value instanceof Collection) {
            $value = $value->all();
        }
        if (!is_array($value)) {
            return $value;
        }

        return array_map($this->nativeValue(...), $value);
    }
}
