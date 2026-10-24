<?php

declare(strict_types=1);

namespace App\Enums\Person;

enum ServiceRequestProgramStatus: string
{
    case NEW = 'new';
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';
}
