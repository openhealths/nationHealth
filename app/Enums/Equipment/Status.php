<?php

declare(strict_types=1);

namespace App\Enums\Equipment;

use App\Traits\EnumUtils;

/**
 * https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/18911953077/equipment_statuses
 */
enum Status: string
{
    use EnumUtils;

    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
    case DRAFT = 'DRAFT';
    case ENTERED_IN_ERROR = 'entered_in_error';

    public function label(): string
    {
        return match($this) {
            self::ACTIVE => __('equipments.status.active'),
            self::INACTIVE => __('equipments.status.inactive'),
            self::DRAFT => __('equipments.status.draft'),
            self::ENTERED_IN_ERROR => __('equipments.status.entered_in_error')
        };
    }

    public function color(): string
    {
        return match($this) {
            self::DRAFT => 'badge-dark',
            self::ACTIVE => 'badge-green',
            self::INACTIVE, self::ENTERED_IN_ERROR => 'badge-red'
        };
    }
}
