<?php

declare(strict_types=1);

namespace App\Enums\DeviceDispense;

use App\Traits\EnumUtils;

/**
 * How a dispensed device is named in the payload (TV 3.22.1.4, TV 3.22.2.1).
 *
 * eHealth accepts one representation and only one: either the classification type of the device under
 * `details.device_code[].code`, or the concrete device definition (model or brand) under
 * `details.device[].device_definition`.
 */
enum DeviceReferenceType: string
{
    use EnumUtils;

    case DEVICE_CODE = 'device_code';
    case DEVICE_DEFINITION = 'device_definition';

    public function label(): string
    {
        return __('device-dispenses.device_reference_type.' . $this->value);
    }
}
