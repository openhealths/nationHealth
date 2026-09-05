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
    case DRAFT = 'draft';

    public function label(): string
    {
        return match ($this) {
            self::ENTERED_IN_ERROR => __('encounters.status.entered_in_error'),
            self::FINISHED => __('encounters.status.finished'),
            self::DRAFT => __('encounters.status.draft'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::FINISHED => 'badge-green',
            self::ENTERED_IN_ERROR => 'badge-red',
            self::DRAFT => 'badge-dark',
        };
    }
}
