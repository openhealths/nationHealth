<?php

declare(strict_types=1);

namespace App\Enums\Episode;

use App\Traits\EnumUtils;

/**
 * see https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/17908171181/eHealth+episode_statuses
 */
enum Status: string
{
    use EnumUtils;

    case DRAFT = 'draft';
    case ACTIVE = 'active';
    case CLOSED = 'closed';
    case ENTERED_IN_ERROR = 'entered_in_error';

    /**
     * Get options for eHealth search — excludes local-only statuses.
     *
     * @return array
     */
    public static function searchableOptions(): array
    {
        return collect(self::cases())
            ->reject(fn (self $case) => $case === self::DRAFT)
            ->mapWithKeys(fn (self $case) => [$case->value => $case->label()])
            ->toArray();
    }

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => __('episodes.status.draft'),
            self::ACTIVE => __('episodes.status.active'),
            self::CLOSED => __('episodes.status.closed'),
            self::ENTERED_IN_ERROR => __('episodes.status.entered_in_error')
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::ACTIVE,
            self::CLOSED => 'badge-green',

            self::ENTERED_IN_ERROR => 'badge-red',

            self::DRAFT => 'badge-dark',
        };
    }
}
