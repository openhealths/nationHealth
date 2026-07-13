<?php

declare(strict_types=1);

namespace App\Enums\MergeRequest;

use App\Traits\EnumUtils;

enum Status: string
{
    use EnumUtils;

    case NEW = 'NEW';
    case APPROVED = 'APPROVED';
    case SIGNED = 'SIGNED';
    case REJECTED = 'REJECTED';
}
