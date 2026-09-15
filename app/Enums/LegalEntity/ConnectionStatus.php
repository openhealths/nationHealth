<?php

declare(strict_types=1);

namespace App\Enums\LegalEntity;

use App\Traits\EnumUtils;

/**
 * See: https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/18832326681/Declaration+ENT-013#%D0%A1%D1%82%D0%B0%D1%82%D1%83%D1%81%D0%B8
 */
enum ConnectionStatus: string
{
    use EnumUtils;

    case ACTIVE = 'ACTIVE';
    case TERMINATED = 'TERMINATED';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => __('declarations.status.active'),
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
            self::TERMINATED => 'badge-red'
        };
    }
}
