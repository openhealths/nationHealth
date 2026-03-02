<?php

declare(strict_types=1);

namespace App\Enums\TreatmentPlan;

enum Category: string
{
    case PRIMARY_CARE = 'primary_care';
    case PROLONGED = 'prolonged';
    case REHABILITATION = 'rehabilitation';
    case PALLIATIVE = 'palliative';

    /**
     * Get label for enum value.
     */
    public function label(): string
    {
        return match ($this) {
            self::PRIMARY_CARE => __('treatment-plan.primary_care'),
            self::PROLONGED => __('treatment-plan.prolonged'),
            self::REHABILITATION => __('treatment-plan.rehabilitation'),
            self::PALLIATIVE => __('treatment-plan.palliative'),
        };
    }
}
