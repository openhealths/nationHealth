<?php

declare(strict_types=1);

namespace App\Enums\Contract;

use App\Traits\EnumUtils;

/**
 * Enum for eHealth Contract statuses (not contract requests).
 * See: https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/17569185823
 */
enum ContractStatus: string
{
    use EnumUtils;

    case ACTIVE = 'ACTIVE';
    case TERMINATED = 'TERMINATED';
    case SUSPENDED = 'SUSPENDED';
    case EXPIRED = 'EXPIRED';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => __('contracts.status.active'),
            self::TERMINATED => __('contracts.status.terminated'),
            self::SUSPENDED => __('contracts.status.suspended'),
            self::EXPIRED => __('contracts.status.expired'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::ACTIVE => 'badge-green',
            self::TERMINATED => 'badge-red',
            self::SUSPENDED => 'badge-yellow',
            self::EXPIRED => 'badge-gray',
        };
    }
}
