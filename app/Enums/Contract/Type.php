<?php

declare(strict_types=1);

namespace App\Enums\Contract;

use App\Traits\EnumUtils;

enum Type: string
{
    use EnumUtils;

    case REIMBURSEMENT = 'REIMBURSEMENT';
    case CAPITATION = 'CAPITATION';

    public function label(): string
    {
        return match ($this) {
            self::REIMBURSEMENT => __('contracts.reimbursement'),
            self::CAPITATION => __('contracts.capitation'),
        };
    }
}
