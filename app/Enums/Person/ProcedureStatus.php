<?php

declare(strict_types=1);

namespace App\Enums\Person;

use App\Traits\EnumUtils;

/**
 * see https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/17904108726/eHealth+procedure_statuses
 */
enum ProcedureStatus: string
{
    use EnumUtils;

    case COMPLETED = 'completed';
    case ENTERED_IN_ERROR = 'entered_in_error';
    case NOT_DONE = 'not_done';

    public function label(): string
    {
        return match ($this) {
            self::COMPLETED => __('patients.status.completed'),
            self::ENTERED_IN_ERROR => __('patients.status.entered_in_error'),
            self::NOT_DONE => __('patients.status.not_done')
        };
    }
}
