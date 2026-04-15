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
            self::ACTIVE => __('patients.status.active'),
            self::FINISHED => __('patients.status.completed'),
            self::RECURRENCE => __('patients.status.recurrence'),
            self::REMISSION => __('patients.status.remission'),
            self::RESOLVED => __('patients.status.resolved')
        };
    }
}
