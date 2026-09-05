<?php

declare(strict_types=1);

namespace App\Enums\Contract;

use App\Traits\EnumUtils;

/**
 * Statuses of a contract request (заявка на договір).
 * Signed contracts use {@see ContractStatus}.
 *
 * @see https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/17568923702/REST+API+Get+Contract+Request+Details+API-005-012-0006
 * @see https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/17569185823/REST+API+Public.+Get+Contract+Requests+List+API-005-012-0007
 */
enum ContractRequestStatus: string
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

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => __('contracts.request_status.draft'),
            self::NEW => __('contracts.request_status.new'),
            self::IN_PROCESS => __('contracts.request_status.in_process'),
            self::APPROVED => __('contracts.request_status.approved'),
            self::DECLINED => __('contracts.request_status.declined'),
            self::TERMINATED => __('contracts.request_status.terminated'),
            self::PENDING_NHS_SIGN => __('contracts.request_status.pending_nhs_sign'),
            self::NHS_SIGNED => __('contracts.request_status.nhs_signed'),
            self::MSP_APPROVED => __('contracts.request_status.msp_approved'),
            self::SIGNED => __('contracts.request_status.signed'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::DRAFT => 'badge-gray',
            self::NEW, self::SIGNED => 'badge-green',
            self::IN_PROCESS, self::PENDING_NHS_SIGN => 'badge-yellow',
            self::APPROVED, self::NHS_SIGNED, self::MSP_APPROVED => 'badge-blue',
            self::TERMINATED, self::DECLINED => 'badge-red',
        };
    }

    public static function resolveLabel(mixed $status): string
    {
        if ($status instanceof self) {
            return $status->label();
        }

        $value = strtoupper((string) (is_object($status) && property_exists($status, 'value')
            ? $status->value
            : $status));

        if ($value === '') {
            return '-';
        }

        return self::tryFrom($value)?->label() ?? $value;
    }
}
