<?php

declare(strict_types=1);

namespace App\Enums;

use App\Traits\EnumUtils;
use Illuminate\Support\Facades\Lang;

enum CarePlanStatus: string
{
    use EnumUtils;

    case DRAFT = 'draft';
    case PENDING = 'new';
    case ACTIVE = 'active';
    case ON_HOLD = 'on-hold';
    case REVOKED = 'revoked';
    case COMPLETED = 'completed';
    case TERMINATED = 'terminated';
    case ENTERED_IN_ERROR = 'entered-in-error';
    case CANCELLED = 'cancelled';
    case UNKNOWN = 'unknown';

    /**
     * @param  array<string, mixed>  $finalResponse
     */
    public static function fromJobResponse(array $finalResponse, self $fallback): self
    {
        $result = $finalResponse['result'] ?? null;
        $entity = is_array($result) ? ($result[0] ?? $result) : null;
        $entityStatus = is_array($entity)
            ? ($entity['status'] ?? (is_array($entity['data'] ?? null) ? ($entity['data']['status'] ?? null) : null))
            : null;

        if (!is_string($entityStatus) || $entityStatus === '') {
            return $fallback;
        }

        $resolved = self::fromStored($entityStatus);

        return $resolved === self::UNKNOWN ? $fallback : $resolved;
    }

    public static function fromStored(mixed $status): self
    {
        return self::tryFrom(self::normalize($status)) ?? self::UNKNOWN;
    }

    public static function normalize(mixed $status): string
    {
        if (is_array($status)) {
            $status = $status['coding'][0]['code'] ?? ($status['text'] ?? '');
        }

        $normalized = strtolower(str_replace('_', '-', trim((string) $status)));

        return $normalized === 'canceled' ? 'cancelled' : $normalized;
    }

    public static function labelFor(mixed $status): string
    {
        $enum = self::fromStored($status);
        if ($enum !== self::UNKNOWN) {
            return $enum->label();
        }

        $raw = self::normalize($status);
        $langKey = 'care-plan.status.'.str_replace('-', '_', $raw);

        return Lang::has($langKey) ? __($langKey) : $enum->label();
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::COMPLETED,
            self::CANCELLED,
            self::REVOKED,
            self::TERMINATED,
            self::ENTERED_IN_ERROR => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => __('care-plan.status.draft'),
            self::PENDING => __('care-plan.status.new'),
            self::ACTIVE => __('care-plan.status.active'),
            self::ON_HOLD => __('care-plan.status.on-hold'),
            self::REVOKED => __('care-plan.status.revoked'),
            self::COMPLETED => __('care-plan.status.completed'),
            self::TERMINATED => __('care-plan.status.terminated'),
            self::CANCELLED => __('care-plan.status.cancelled'),
            self::ENTERED_IN_ERROR => __('care-plan.status.entered-in-error'),
            self::UNKNOWN => __('care-plan.status.unknown'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::ACTIVE,
            self::COMPLETED => 'badge-green',

            self::PENDING,
            self::ON_HOLD => 'badge-yellow',

            self::REVOKED,
            self::TERMINATED,
            self::CANCELLED,
            self::ENTERED_IN_ERROR => 'badge-red',

            self::DRAFT,
            self::UNKNOWN => 'badge-dark',
        };
    }
}
