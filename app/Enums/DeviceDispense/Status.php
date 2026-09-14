<?php

declare(strict_types=1);

namespace App\Enums\DeviceDispense;

use App\Traits\EnumUtils;

/**
 * Statuses a Device Dispense goes through in eHealth.
 *
 * The lifecycle is driven by the device_dispense scopes the System grants: a dispense is created
 * (`device_dispense:write`), may be completed (`:complete`), stopped (`:stop`) or marked as
 * entered in error (`:mark_in_error`).
 */
enum Status: string
{
    use EnumUtils;

    case PROCESSED = 'processed';
    case COMPLETED = 'completed';
    case REJECTED = 'rejected';
    case STOPPED = 'stopped';
    case ENTERED_IN_ERROR = 'entered_in_error';

    public function label(): string
    {
        return __('device-dispenses.status.' . $this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::PROCESSED, self::COMPLETED => 'badge-green',
            self::REJECTED, self::STOPPED, self::ENTERED_IN_ERROR => 'badge-red',
        };
    }
}
