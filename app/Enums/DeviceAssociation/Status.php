<?php

declare(strict_types=1);

namespace App\Enums\DeviceAssociation;

use App\Traits\EnumUtils;

enum Status: string
{
    use EnumUtils;

    case ATTACHED = 'attached';
    case UNATTACHED = 'unattached';
    case IMPLANTED = 'implanted';
    case EXPLANTED = 'explanted';
    case ENTERED_IN_ERROR = 'entered_in_error';
}
