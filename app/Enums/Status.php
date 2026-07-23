<?php

declare(strict_types=1);

namespace App\Enums;

use App\Traits\EnumUtils;

enum Status: string
{
    use EnumUtils;

    case NEW = 'NEW';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case SIGNED = 'SIGNED';
    case DISMISSED = 'DISMISSED';
    case STOPPED = 'STOPPED';
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
    case DRAFT = 'DRAFT';
    case UNSYNCED = 'UNSYNCED';
    case EXPIRED = 'EXPIRED';
    case REORGANIZED = 'REORGANIZED';
    case ENTERED_IN_ERROR = 'ENTERED_IN_ERROR';

    public function label(): string
    {
        return match ($this) {
            self::NEW => __('forms.status.new'),
            self::APPROVED => __('forms.status.approved'),
            self::REJECTED => __('forms.status.rejected'),
            self::SIGNED => __('forms.status.signed'),
            self::DISMISSED => __('forms.status.dismissed'),
            self::ACTIVE => __('forms.status.active'),
            self::INACTIVE => __('forms.status.non_active'),
            self::DRAFT => __('forms.status.draft'),
            self::UNSYNCED => __('forms.status.unsynced'),
            self::STOPPED => __('forms.status.stopped'),
            self::REORGANIZED => __('forms.status.reorganized'),
            self::ENTERED_IN_ERROR => __('forms.status.entered_in_error'),
            self::EXPIRED => __('forms.status.expired'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::APPROVED, self::ACTIVE => 'badge-green',
            self::NEW, self::SIGNED, self::UNSYNCED, self::REORGANIZED => 'badge-yellow',
            self::DRAFT, self::REJECTED, self::DISMISSED, self::STOPPED,
            self::INACTIVE, self::ENTERED_IN_ERROR, self::EXPIRED => 'badge-red',
        };
    }

    public static function only(array $names): array
    {
        return collect(self::cases())
            ->filter(fn ($case) => in_array($case->name, $names, true))
            ->map(fn ($case) => $case->value)
            ->values()
            ->all();
    }
}
