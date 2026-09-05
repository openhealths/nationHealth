<?php

declare(strict_types=1);

namespace App\Enums\Person;

use App\Traits\EnumUtils;

enum AuthenticationMethod: string
{
    use EnumUtils;

    case OTP = 'OTP';
    case OFFLINE = 'OFFLINE';
    case THIRD_PERSON = 'THIRD_PERSON';
    case NA = 'NA';

    public function label(): string
    {
        return match ($this) {
            self::OTP => __('patients.authentication_method.otp'),
            self::OFFLINE => __('patients.authentication_method.offline'),
            self::THIRD_PERSON => __('patients.authentication_method.third_person'),
            self::NA => __('patients.authentication_method.na')
        };
    }
}
