<?php

declare(strict_types=1);

namespace App\Enums\DetectedIssue;

use App\Traits\EnumUtils;

enum Status: string
{
    use EnumUtils;

    case PRELIMINARY = 'preliminary';
    case MITIGATED = 'mitigated';
    case ENTERED_IN_ERROR = 'entered_in_error';
}