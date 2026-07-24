<?php

declare(strict_types=1);

namespace App\Enums\Contract;

use App\Traits\EnumUtils;

/**
 * Enum for eHealth Contract statuses (not contract requests).
 * See: https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/17569185823
 */
enum ContractStatus: string
{
    use EnumUtils;

    case ACTIVE = 'ACTIVE';
    case TERMINATED = 'TERMINATED';
    case SUSPENDED = 'SUSPENDED';
    case EXPIRED = 'EXPIRED';
    case NEW = 'NEW';
    case DRAFT = 'DRAFT';
    case IN_PROCESS = 'IN_PROCESS';
    case APPROVED = 'APPROVED';
    case DECLINED = 'DECLINED';
    case PENDING_NHS_SIGN = 'PENDING_NHS_SIGN';
    case NHS_SIGNED = 'NHS_SIGNED';
    case MSP_APPROVED = 'MSP_APPROVED';
    case SIGNED = 'SIGNED';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => __('contracts.status.active'),
            self::TERMINATED => __('contracts.status.terminated'),
            self::SUSPENDED => __('contracts.status.suspended'),
            self::EXPIRED => __('contracts.status.expired'),
            self::NEW => __('contracts.status.new'),
            self::DRAFT => __('contracts.status.draft'),
            self::IN_PROCESS => __('contracts.status.in_process'),
            self::APPROVED => __('contracts.status.approved'),
            self::DECLINED => __('contracts.status.declined'),
            self::PENDING_NHS_SIGN => __('contracts.status.pending_nhs_sign'),
            self::NHS_SIGNED => __('contracts.status.nhs_signed'),
            self::MSP_APPROVED => __('contracts.status.msp_approved'),
            self::SIGNED => __('contracts.status.signed'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::ACTIVE => 'badge-green',
            self::TERMINATED => 'badge-red',
            self::SUSPENDED => 'badge-yellow',
            self::EXPIRED => 'badge-dark',
            self::NEW => 'badge-green',
            self::DRAFT => 'badge-dark',
            self::IN_PROCESS => 'badge-yellow',
            self::APPROVED => 'badge-green',
            self::DECLINED => 'badge-red',
            self::PENDING_NHS_SIGN => 'badge-yellow',
            self::NHS_SIGNED => 'badge-green',
            self::MSP_APPROVED => 'badge-green',
            self::SIGNED => 'badge-green',
        };
    }
}
