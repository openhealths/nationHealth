<?php

declare(strict_types=1);

namespace App\Enums\TreatmentPlan;

enum TermsService: string
{
    case AMBULATORY = 'ambulatory';
    case INPATIENT = 'inpatient';

    /**
     * Get label for enum value.
     */
    public function label(): string
    {
        return match ($this) {
            self::AMBULATORY => __('treatment-plan.ambulatory'),
            self::INPATIENT => __('treatment-plan.inpatient'),
        };
    }
}
