<?php

declare(strict_types=1);

namespace App\Enums\TreatmentPlan;

enum Status: string
{
    case DRAFT = 'draft';
    case PROCESSING = 'processing';
    case ACTIVE = 'active';
    case TERMINATED = 'terminated';
    case ERROR = 'error';

    /**
     * Get label for enum value.
     */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT => __('treatment-plan.status_draft'),
            self::PROCESSING => __('treatment-plan.status_processing'),
            self::ACTIVE => __('treatment-plan.status_active'),
            self::TERMINATED => __('treatment-plan.status_terminated'),
            self::ERROR => __('treatment-plan.status_error'),
        };
    }
}
