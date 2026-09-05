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
            self::CONFIRMED => __('conditions.status.confirmed'),
            self::DIFFERENTIAL => __('conditions.status.differential'),
            self::ENTERED_IN_ERROR => __('conditions.status.entered_in_error'),
            self::PROVISIONAL => __('conditions.status.provisional'),
            self::REFUTED => __('conditions.status.refuted')
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::CONFIRMED => 'badge-green',

            self::DIFFERENTIAL,
            self::PROVISIONAL => 'badge-yellow',

            self::ENTERED_IN_ERROR,
            self::REFUTED => 'badge-red',
        };
    }
}
