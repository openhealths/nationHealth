<?php

declare(strict_types=1);

namespace App\Enums\Declaration;

use App\Traits\EnumUtils;

/**
 * Where the declaration request has been created.
 */
enum Channel: string
{
    use EnumUtils;

    case MIS = 'MIS';
    case PIS = 'PIS';

    public function label(): string
    {
        return match ($this) {
            self::MIS => __('declarations.channel.mis'),
            self::PIS => __('declarations.channel.pis')
        };
    }
}
