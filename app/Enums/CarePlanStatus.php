<?php

declare(strict_types=1);

namespace App\Enums;

use App\Traits\EnumUtils;

enum CarePlanStatus: string
{
    use EnumUtils;

    case DRAFT = 'draft';
    case PENDING = 'new';
    case ACTIVE = 'active';
    case ON_HOLD = 'on-hold';
    case REVOKED = 'revoked';
    case COMPLETED = 'completed';
    case TERMINATED = 'terminated';
    case ENTERED_IN_ERROR = 'entered-in-error';
    case CANCELLED = 'cancelled';
    case UNKNOWN = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => __('care-plan.status.draft'),
            self::PENDING => __('care-plan.status.new'),
            self::ACTIVE => __('care-plan.status.active'),
            self::ON_HOLD => __('care-plan.status.on-hold'),
            self::REVOKED => __('care-plan.status.revoked'),
            self::COMPLETED => __('care-plan.status.completed'),
            self::TERMINATED => __('care-plan.status.terminated'),
            self::CANCELLED => __('care-plan.status.cancelled'),
            self::ENTERED_IN_ERROR => __('care-plan.status.entered-in-error'),
            self::UNKNOWN => __('care-plan.status.unknown'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::ACTIVE,
            self::COMPLETED => 'badge-green',

            self::PENDING,
            self::ON_HOLD => 'badge-yellow',

            self::REVOKED,
            self::TERMINATED,
            self::CANCELLED,
            self::ENTERED_IN_ERROR => 'badge-red',

            self::DRAFT,
            self::UNKNOWN => 'badge-dark',
        };
    }
}
