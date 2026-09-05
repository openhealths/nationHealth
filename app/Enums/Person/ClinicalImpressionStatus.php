<?php

declare(strict_types=1);

namespace App\Enums\Person;

use App\Traits\EnumUtils;

/**
 * see https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/18475090020/eHealth+clinical_impression_statuses
 */
enum ClinicalImpressionStatus: string
{
    use EnumUtils;

    case COMPLETED = 'completed';
    case ENTERED_IN_ERROR = 'entered_in_error';

    public function label(): string
    {
        return match ($this) {
            self::COMPLETED => __('clinical-impressions.status.completed'),
            self::ENTERED_IN_ERROR => __('clinical-impressions.status.entered_in_error')
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::COMPLETED => 'badge-green',
            self::ENTERED_IN_ERROR => 'badge-red',
        };
    }
}
