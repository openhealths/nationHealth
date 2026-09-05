<?php

declare(strict_types=1);

namespace App\Enums\Person;

use App\Traits\EnumUtils;

/**
 * https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/18475090309/DRAFT+eHealth+diagnostic_report_statuses
 */
enum DiagnosticReportStatus: string
{
    use EnumUtils;

    case ENTERED_IN_ERROR = 'entered_in_error';
    case FINAL = 'final';
    case DRAFT = 'draft';

    public function label(): string
    {
        return match ($this) {
            self::ENTERED_IN_ERROR => __('diagnostic-reports.status.entered_in_error'),
            self::FINAL => __('diagnostic-reports.status.final'),
            self::DRAFT => __('diagnostic-reports.status.draft')
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::FINAL => 'badge-green',
            self::ENTERED_IN_ERROR => 'badge-red',
            self::DRAFT => 'badge-dark',
        };
    }
}
