<?php

declare(strict_types=1);

namespace App\Enums\Person;

use App\Traits\EnumUtils;

/**
 * see https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/17908171181/eHealth+episode_statuses
 */
enum EpisodeStatus: string
{
    use EnumUtils;

    case ACTIVE = 'active';
    case CLOSED = 'closed';
    case ENTERED_IN_ERROR = 'entered_in_error';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => __('patients.status.active'),
            self::CLOSED => __('patients.status.closed'),
            self::ENTERED_IN_ERROR => __('patients.status.entered_in_error')
        };
    }
}
