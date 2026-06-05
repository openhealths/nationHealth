<?php

declare(strict_types=1);

namespace App\Enums\Person;

use App\Traits\EnumUtils;

/**
 * see https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/17908171181/eHealth+episode_statuses
 */
enum EpisodeStatus: string
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
            self::DRAFT => __('patients.status.draft'),
            self::ACTIVE => __('patients.status.active'),
            self::CLOSED => __('patients.status.completed'),
            self::ENTERED_IN_ERROR => __('patients.status.entered_in_error')
        };
    }
}
