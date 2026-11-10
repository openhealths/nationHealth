<?php

declare(strict_types=1);

namespace App\Enums;

enum CarePlanStatusFilter: string
{
    case CURRENT = 'current';
    case ALL = '';
}
