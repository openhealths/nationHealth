<?php

declare(strict_types=1);

namespace App\Enums\Declaration;

use App\Traits\EnumUtils;

enum ReorganizedStatus: string
{
    use EnumUtils;

    case TO_BE_RESIGNED = 'TO_BE_RESIGNED';
    case RESIGNED = 'RESIGNED';

    public function label(): string
    {
        return match ($this) {
            self::TO_BE_RESIGNED => __('declarations.status.to_be_resigned'),
            self::RESIGNED => __('declarations.status.resigned')
        };
    }
}
