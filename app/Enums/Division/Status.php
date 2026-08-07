<?php

declare(strict_types=1);

namespace App\Enums\Division;

use App\Traits\EnumUtils;

/**
 * see: https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/17940185105/DIVISION_STATUS
 */
enum Status: string
{
    use EnumUtils;

    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => __('forms.status.active'),
            self::INACTIVE => __('forms.status.non_active')
        };
    }

    /**
     * Get all enum cases as value => label pairs.
     *
     * @return array<string, string>
     */
    public static function entries(): array
    {
        return collect(self::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->toArray();
    }

    public function cssClass(): string
    {
        return match ($this) {
            self::ACTIVE => 'status-alert-green',
            self::INACTIVE => 'status-alert-red'
        };
    }
}
