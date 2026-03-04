<?php

declare(strict_types=1);

namespace App\Enums\Contract;

use App\Traits\EnumUtils;

enum Type: string
{
    use EnumUtils;

    case REIMBURSEMENT = 'REIMBURSMENT';
    case CAPITATIONS = 'CAPITATIONS';

    public function label(): string
    {
        return match($this) {
            // Update translation keys to match contracts.php
            self::REIMBURSEMENT => __('contracts.reimbursement'),
            self::CAPITATIONS => __('contracts.capitation'),
        };
    }
}
