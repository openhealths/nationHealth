<?php

declare(strict_types=1);

namespace App\Enums\Device;

use App\Traits\EnumUtils;

enum Status: string
{
    use EnumUtils;

    case ACTIVE = 'active';
    case ENTERED_IN_ERROR = 'entered_in_error';
    case INACTIVE = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => __('devices.status.active'),
            self::ENTERED_IN_ERROR => __('devices.status.entered_in_error'),
            self::INACTIVE => __('devices.status.inactive')
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::ACTIVE => 'badge-green',
            self::ENTERED_IN_ERROR => 'badge-red',
            self::INACTIVE => 'badge-dark'
        };
    }
}
