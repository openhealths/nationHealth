<?php

declare(strict_types=1);

namespace App\Enums\DeviceDispense;

use App\Traits\EnumUtils;

enum Status: string
{
    use EnumUtils;

    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';
    case STOPPED = 'stopped';
    case ENTERED_IN_ERROR = 'entered_in_error';
    case UNKNOWN = 'unknown';
    case PREPARATION = 'preparation';
    case CANCELED = 'canceled';

    public function label(): string
    {
        return __('device-dispenses.status.' . $this->value);
    }
}