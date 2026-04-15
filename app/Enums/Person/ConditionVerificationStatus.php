<?php

declare(strict_types=1);

namespace App\Enums\Person;

use App\Traits\EnumUtils;

/**
 * see https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/18476236945/DRAFT+eHealth+condition_verification_statuses
 */
enum ConditionVerificationStatus: string
{
    use EnumUtils;

    case CONFIRMED = 'confirmed';
    case DIFFERENTIAL = 'differential';
    case ENTERED_IN_ERROR = 'entered_in_error';
    case PROVISIONAL = 'provisional';
    case REFUTED = 'refuted';

    public function label(): string
    {
        return match ($this) {
            self::CONFIRMED => __('patients.status.confirmed'),
            self::DIFFERENTIAL => __('patients.status.differential'),
            self::ENTERED_IN_ERROR => __('patients.status.entered_in_error'),
            self::PROVISIONAL => __('patients.status.provisional'),
            self::REFUTED => __('patients.status.refuted')
        };
    }
}
