<?php

declare(strict_types=1);

namespace App\Enums\Person;

use App\Traits\EnumUtils;

enum Gender: string
{
    use EnumUtils;

    case MALE = 'MALE';
    case FEMALE = 'FEMALE';

    public function label(): string
    {
        return match ($this) {
            self::MALE => __('patients.male'),
            self::FEMALE => __('patients.female')
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::MALE => 'men',
            self::FEMALE => 'women'
        };
    }
}
