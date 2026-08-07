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
            self::DRAFT => __('forms.status.draft'),
            self::PENDING => __('forms.status.new'),
            self::ACTIVE => __('forms.status.active'),
            self::ON_HOLD => __('forms.status.on_hold'),
            self::REVOKED => __('forms.status.revoked'),
            self::COMPLETED => __('forms.status.completed'),
            self::TERMINATED => __('forms.status.terminated') ?? 'Припинено',
            self::CANCELLED => __('forms.status.cancelled') ?? 'Скасовано',
            self::ENTERED_IN_ERROR => __('forms.status.entered_in_error'),
            self::UNKNOWN => __('forms.status.unknown'),
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
