<?php

declare(strict_types=1);

namespace App\Enums\Contract;

use App\Traits\EnumUtils;

/**
 * Statuses of a signed contract (договір).
 * Contract requests use {@see ContractRequestStatus}.
 * Public list API values are VERIFIED and TERMINATED.
 *
 * @see https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/17569644549/REST+API+Public.+Get+Contracts+list+API-005-012-0001
 */
enum ContractStatus: string
{
    use EnumUtils;

    case VERIFIED = 'VERIFIED';
    case TERMINATED = 'TERMINATED';
    /** @deprecated Local alias of VERIFIED. eHealth sends VERIFIED. */
    case ACTIVE = 'ACTIVE';
    case SUSPENDED = 'SUSPENDED';
    case EXPIRED = 'EXPIRED';

    public function label(): string
    {
        return match ($this) {
            self::VERIFIED, self::ACTIVE => __('contracts.contract_status.verified'),
            self::TERMINATED => __('contracts.contract_status.terminated'),
            self::SUSPENDED => __('contracts.contract_status.suspended'),
            self::EXPIRED => __('contracts.contract_status.expired'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::VERIFIED, self::ACTIVE => 'badge-green',
            self::TERMINATED => 'badge-red',
            self::SUSPENDED => 'badge-yellow',
            self::EXPIRED => 'badge-dark',
        };
    }

    /**
     * Statuses shown in the contracts list filter (eHealth list API).
     *
     * @return array<string, string>
     */
    public static function listFilterOptions(): array
    {
        return [
            self::VERIFIED->value => self::VERIFIED->label(),
            self::TERMINATED->value => self::TERMINATED->label(),
        ];
    }

    /**
     * Expand filter values so VERIFIED also matches legacy ACTIVE rows.
     *
     * @param  list<string>  $values
     * @return list<string>
     */
    public static function expandFilterValues(array $values): array
    {
        $expanded = [];

        foreach ($values as $value) {
            if ($value === self::VERIFIED->value || $value === self::ACTIVE->value) {
                $expanded[] = self::VERIFIED->value;
                $expanded[] = self::ACTIVE->value;

                continue;
            }

            $expanded[] = $value;
        }

        return array_values(array_unique($expanded));
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
