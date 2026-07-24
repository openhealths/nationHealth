<?php

declare(strict_types=1);

namespace App\Enums\LegalEntity;

use App\Traits\EnumUtils;

/*
 * This enum represents the statuses of a legal entity in the eHealth system
 */
enum States: string
{
    use EnumUtils;

    case ACTIVE = 'ACTIVE';
    case SUSPENDED = 'SUSPENDED';
    case CLOSED = 'CLOSED';
    case REORGANIZED = 'REORGANIZED';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => __('forms.status.active'),
            self::SUSPENDED => __('forms.status.suspended'),
            self::CLOSED => __('forms.status.non_active'),
            self::REORGANIZED => __('forms.status.reorganized'),
        };
    }

    public function cssClass(): string
    {
        return match ($this) {
            self::ACTIVE => 'status-alert-green',
            self::SUSPENDED => 'status-alert-yellow',
            self::CLOSED => 'status-alert-red',
            self::REORGANIZED => 'status-alert-red',
        };
    }
}
