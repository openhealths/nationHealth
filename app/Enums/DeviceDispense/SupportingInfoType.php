<?php

declare(strict_types=1);

namespace App\Enums\DeviceDispense;

use App\Traits\EnumUtils;

/**
 * Resources a device dispense may point at as `supporting_info` (TV 3.22.2.1).
 *
 * The values are eHealth resource codes, because that is what the reference carries as its
 * identifier type, so they go into the payload unchanged.
 */
enum SupportingInfoType: string
{
    use EnumUtils;

    case DIAGNOSTIC_REPORT = 'diagnostic_report';
    case OBSERVATION = 'observation';
    case CONDITION = 'condition';
    case PROCEDURE = 'procedure';
    case ENCOUNTER = 'encounter';
    case EPISODE_OF_CARE = 'episode_of_care';

    public function label(): string
    {
        return __('device-dispenses.supporting_info_type.' . $this->value);
    }
}
