<?php

declare(strict_types=1);

namespace App\Enums\TreatmentPlan;

enum Intention: string
{
    case ORDER = 'order';
    case PLAN = 'plan';
    case PROPOSAL = 'proposal';

    /**
     * Get label for enum value.
     */
    public function label(): string
    {
        return match ($this) {
            self::ORDER => __('treatment-plan.order'),
            self::PLAN => __('treatment-plan.plan'),
            self::PROPOSAL => __('treatment-plan.proposal'),
        };
    }
}
