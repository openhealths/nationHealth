<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Responses\Collections;

use Illuminate\Support\Collection;

/**
 * Validated equipment data shared by create, status changes,
 * and individual items returned by getMany.
 *
 * @extends Collection<string, mixed>
 */
final class EquipmentDetails extends Collection
{
}