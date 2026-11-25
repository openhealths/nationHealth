<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Responses\Collections;

/**
 * Validated full care plan from GET details or a synchronous create/status response.
 * Diagnosis concepts are prepared for the shared CodeableConcept Model DTO.
 * toArray() retains the API keys and values required when signing cancellation.
 */
class CarePlanDetails extends CarePlanSummary
{
    public function __construct(iterable|null $items = [])
    {
        parent::__construct($items);

        if ($this->has('addresses')) {
            $this->put('addresses', array_map(
                static fn (array|EhealthCodeableConcept $concept): EhealthCodeableConcept => $concept instanceof EhealthCodeableConcept
                    ? $concept
                    : new EhealthCodeableConcept($concept),
                $this->get('addresses')
            ));
        }
    }
}
