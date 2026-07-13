<?php

declare(strict_types=1);

namespace App\Enums\Equipment;

use App\Traits\EnumUtils;

/**
 * https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/18768265500/equipment_availability_statuses
 */
enum AvailabilityStatus: string
{
    use EnumUtils;

    case AVAILABLE = 'available';
    case DAMAGED = 'damaged';
    case DESTROYED = 'destroyed';
    case LOST = 'lost';

    public function label(): string
    {
        return match($this) {
            self::AVAILABLE => __('equipments.availability_status.available'),
            self::DAMAGED => __('equipments.availability_status.damaged'),
            self::DESTROYED => __('equipments.availability_status.destroyed'),
            self::LOST => __('equipments.availability_status.lost')
        };
    }

    public function color(): string
    {
        return match($this) {
            self::AVAILABLE => 'badge-green',
            self::DAMAGED, self::DESTROYED, self::LOST => 'badge-red'
        };
    }
}
