<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Responses\Collections;

use Illuminate\Support\Collection;

/**
 * Validated data of one healthcare service with snake_case keys, as returned by eHealth.
 * Get by id, create, update, activate, deactivate and each item of the list return the same structure,
 * so this one class is used for all of them and is mapped to the DTO the same way.
 *
 * @extends Collection<string, mixed>
 */
class HealthcareServiceDetails extends Collection
{
}
