<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Responses\Collections;

use Illuminate\Support\Collection;

/**
 * Validated asynchronous care plan receipt with its status, ETA and job links.
 * The resource is imported only after the job confirms processing.
 *
 * @extends Collection<string, mixed>
 */
class CarePlanSubmission extends Collection
{
}
