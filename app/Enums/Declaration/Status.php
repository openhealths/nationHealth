<?php

declare(strict_types=1);

namespace App\Enums\Declaration;

use App\Traits\EnumUtils;

/**
 * See: https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/18832326681/Declaration+ENT-013#%D0%A1%D1%82%D0%B0%D1%82%D1%83%D1%81%D0%B8
 */
enum Status: string
{
    use EnumUtils;

    case ACTIVE = 'active';
    case CLOSED = 'closed';
    case PENDING_VERIFICATION = 'pending_verification';
    case REJECTED = 'rejected';
    case TERMINATED = 'terminated';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => __('declarations.status.active'),
            self::CLOSED => __('declarations.status.closed'),
            self::PENDING_VERIFICATION => __('declarations.status.pending_verification'),
            self::REJECTED => __('declarations.status.rejected'),
            self::TERMINATED => __('declarations.status.terminated')
        };
    }

    /**
     * Badge CSS class representing the status color.
     *
     * @return string
     */
    public function color(): string
    {
        return match ($this) {
            self::ACTIVE => 'badge-green',
            self::PENDING_VERIFICATION => 'badge-yellow',
            self::CLOSED, self::REJECTED, self::TERMINATED => 'badge-red'
        };
    }
}
