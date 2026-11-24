<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Responses\Collections;

use Illuminate\Support\Collection;

/**
 * One validated ServiceRequest with native eHealth keys.
 *
 * @extends Collection<string, mixed>
 */
class ServiceRequestDetails extends Collection
{
    /** Original JSON fields for recall and cancellation signatures, including nested objects and extension fields. */
    public readonly array $signaturePayload;

    public function __construct(iterable|null $items = [], ?array $signaturePayload = null)
    {
        parent::__construct($items);

        $this->signaturePayload = $signaturePayload ?? $this->all();
    }
}
