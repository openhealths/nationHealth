<?php

declare(strict_types=1);

namespace App\Enums;

enum CarePlanCompletenessFilter: string
{
    case COMPLETE = 'complete';
    case ALL = 'all';
    case INCOMPLETE = 'incomplete';
}
