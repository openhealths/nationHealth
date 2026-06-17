<?php

declare(strict_types=1);

namespace App\Enums\Contract;

use App\Traits\EnumUtils;

/**
 * Enum for eHealth Contract Request statuses,
 * see: https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/17569185823/REST+API+Public.+Get+Contract+Requests+List+API-005-012-0007
 */
enum Status: string
{
    use EnumUtils;

    case DRAFT = 'DRAFT';
    case NEW = 'NEW';
    case IN_PROCESS = 'IN_PROCESS';
    case APPROVED = 'APPROVED';
    case DECLINED = 'DECLINED';
    case TERMINATED = 'TERMINATED';
    case PENDING_NHS_SIGN = 'PENDING_NHS_SIGN';
    case NHS_SIGNED = 'NHS_SIGNED';
    case MSP_APPROVED = 'MSP_APPROVED';
    case SIGNED = 'SIGNED';

    /**
     * Gets the translatable label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT => __('contracts.status.draft'),
            self::NEW => __('contracts.status.new'),
            self::IN_PROCESS => __('contracts.status.in_process'),
            self::APPROVED => __('contracts.status.approved'),
            self::DECLINED => __('contracts.status.declined'),
            self::TERMINATED => __('contracts.status.terminated'),
            self::PENDING_NHS_SIGN => __('contracts.status.pending_nhs_sign'),
            self::NHS_SIGNED => __('contracts.status.nhs_signed'),
            self::MSP_APPROVED => __('contracts.status.msp_approved'),
            self::SIGNED => __('contracts.status.signed')
        };
    }

    /**
     * Gets the color class for UI badges.
     * (e.g., 'blue', 'green', 'red', 'yellow', 'gray')
     */
    public function color(): string
    {
        return match ($this) {
            self::DRAFT => 'badge-gray',
            self::NEW, self::SIGNED => 'badge-green',
            self::IN_PROCESS, self::PENDING_NHS_SIGN => 'badge-yellow',
            self::APPROVED, self::NHS_SIGNED, self::MSP_APPROVED => 'badge-blue',
            self::TERMINATED, self::DECLINED => 'badge-red'
        };
    }
}
