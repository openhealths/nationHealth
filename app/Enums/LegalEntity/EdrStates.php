<?php

declare(strict_types=1);

namespace App\Enums\LegalEntity;

use App\Traits\EnumUtils;

/*
 * This enum represents the full states of a legal entity in the EDR (Unified State Register)
 *
 * @see https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/18417942617/EDR_STATE
 */
enum EdrStates: int
{
    use EnumUtils;

    case CANCELED = -1;
    case REGISTERED = 1;
    case UNDER_DISSOLUTION = 2;
    case DISSOLVED = 3;
    case BANKRUPTCY_CASE_INITIATED = 4;
    case BANKRUPTCY_SANATION = 5;
    case REGISTERED_INVALID_CERTIFICATE = 6;

    public function label(): string
    {
        return match ($this) {
            self::CANCELED => __('forms.edr.status.canceled'),
            self::REGISTERED => __('forms.edr.status.registered'),
            self::UNDER_DISSOLUTION => __('forms.edr.status.under_dissolution'),
            self::DISSOLVED => __('forms.edr.status.dissolved'),
            self::BANKRUPTCY_CASE_INITIATED => __('forms.edr.status.bankruptcy_case_initiated'),
            self::BANKRUPTCY_SANATION => __('forms.edr.status.bankruptcy_sanation'),
            self::REGISTERED_INVALID_CERTIFICATE => __('forms.edr.status.registered_invalid_certificate'),
        };
    }

    public function cssClass(): string
    {
        return match ($this) {
            self::REGISTERED => 'status-alert-green',
            self::UNDER_DISSOLUTION => 'status-alert-yellow',
            default => 'status-alert-red',
        };
    }
}
