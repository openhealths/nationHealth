<?php

declare(strict_types=1);

namespace App\Enums\Person;

use App\Traits\EnumUtils;

/**
 * see https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/18475090092/DRAFT+eHealth+condition_clinical_statuses
 */
enum ConditionClinicalStatus: string
{
    use EnumUtils;

    case ACTIVE = 'active';
    case FINISHED = 'finished';
    case RECURRENCE = 'recurrence';
    case REMISSION = 'remission';
    case RESOLVED = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => __('conditions.status.active'),
            self::FINISHED => __('conditions.status.finished'),
            self::RECURRENCE => __('conditions.status.recurrence'),
            self::REMISSION => __('conditions.status.remission'),
            self::RESOLVED => __('conditions.status.resolved')
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::ACTIVE,
            self::FINISHED,
            self::RESOLVED => 'badge-green',

            self::RECURRENCE,
            self::REMISSION => 'badge-yellow',
        };
    }
}
