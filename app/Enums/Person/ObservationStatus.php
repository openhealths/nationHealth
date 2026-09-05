<?php

declare(strict_types=1);

namespace App\Enums\Person;

use App\Traits\EnumUtils;

/**
 * see https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/18059297152/eHealth+observation_statuses
 */
enum ObservationStatus: string
{
    use EnumUtils;

    case ENTERED_IN_ERROR = 'entered_in_error';
    case VALID = 'valid';

    public function label(): string
    {
        return match ($this) {
            self::ENTERED_IN_ERROR => __('observations.status.entered_in_error'),
            self::VALID => __('observations.status.valid')
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::VALID => 'badge-green',
            self::ENTERED_IN_ERROR => 'badge-red',
        };
    }
}
