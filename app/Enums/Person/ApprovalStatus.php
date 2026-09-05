<?php

declare(strict_types=1);

namespace App\Enums\Person;

use App\Traits\EnumUtils;
use App\Traits\ResolvesEHealthStatus;

/**
 * Statuses of a patient approval (consent) granting an employee access to a resource.
 *
 * Local rows store eHealth-style lowercase `pending` / `active` / `inactive`.
 * The upper-case NEW / APPROVED aliases remain so older rows and inbound eHealth
 * `NEW` still resolve; {@see forStorage()} maps them when writing.
 */
enum ApprovalStatus: string
{
    use EnumUtils;
    use ResolvesEHealthStatus;

    case NEW = 'NEW';
    case PENDING = 'pending';
    case APPROVED = 'APPROVED';
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';

    /**
     * The patient has confirmed the request, so the granted employee may use the resource.
     */
    /**
     * Canonical spelling to persist on `approvals.status`.
     */
    public static function forStorage(?string $status): string
    {
        return match (self::resolve($status)) {
            self::APPROVED, self::ACTIVE => self::ACTIVE->value,
            self::INACTIVE => self::INACTIVE->value,
            self::NEW, self::PENDING => self::PENDING->value,
            default => self::PENDING->value,
        };
    }

    public function isGranted(): bool
    {
        return $this === self::ACTIVE || $this === self::APPROVED;
    }

    /**
     * Created but not confirmed by the patient yet, so it can be re-requested.
     */
    public function isAwaitingPatient(): bool
    {
        return $this === self::NEW || $this === self::PENDING;
    }

    public function label(): string
    {
        return __('care-plan.approval_status.'.strtolower($this->value));
    }

    public function color(): string
    {
        return match ($this) {
            self::ACTIVE,
            self::APPROVED => 'badge-green',

            self::NEW,
            self::PENDING => 'badge-yellow',

            self::INACTIVE => 'badge-dark',
        };
    }
}
