<?php

declare(strict_types=1);

namespace App\Dto\CarePlan;

use App\Classes\eHealth\Api\Responses\Collections\CarePlanDetails;
use App\Core\Arr;

/** Cancellation signs the complete remote snapshot with activities removed and a status reason added. */
class EhealthUpdate
{
    public function __construct(protected CarePlanDetails $snapshot, protected array $statusReason)
    {
    }

    public function toArray(): array
    {
        $content = $this->snapshot->toArray();
        unset($content['activities']);
        $content['status_reason'] = Arr::toSnakeCase($this->statusReason);

        return $content;
    }
}
