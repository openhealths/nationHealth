<?php

declare(strict_types=1);

namespace App\Enums\Person;

use App\Traits\EnumUtils;

enum MergedPersonStatus: string
{
    use EnumUtils;

    case MERGED = 'MERGED';
    case DECLINED = 'DECLINED';

    /**
     * Human-readable label for the merged person status.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::MERGED => __('preperson.merged_status.merged'),
            self::DECLINED => __('preperson.merged_status.declined')
        };
    }
}
