<?php

declare(strict_types=1);

namespace App\Enums\Person;

use App\Traits\EnumUtils;

/**
 * see https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/17946738689/eHealth+encounter_statuses
 */
enum EncounterStatus: string
{
    use EnumUtils;

    case ENTERED_IN_ERROR = 'entered_in_error';
    case FINISHED = 'finished';

    public function label(): string
    {
        return match ($this) {
            self::ENTERED_IN_ERROR => __('patients.status.entered_in_error'),
            self::FINISHED => __('patients.status.completed')
        };
    }
}
