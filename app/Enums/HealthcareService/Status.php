<?php

declare(strict_types=1);

namespace App\Enums\HealthcareService;

use App\Traits\EnumUtils;

enum Status: string
{
    use EnumUtils;

    case DRAFT = 'DRAFT';
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';

    /**
     * Human-readable label for the status.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT => __('forms.status.draft'),
            self::ACTIVE => __('forms.status.active'),
            self::INACTIVE => __('forms.status.non_active'),
        };
    }
}
