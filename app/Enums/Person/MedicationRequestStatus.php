<?php

declare(strict_types=1);

namespace App\Enums\Person;

use App\Traits\EnumUtils;
use App\Traits\ResolvesEHealthStatus;

/**
 * Statuses a medication request (eRx) goes through, from local draft to a signed prescription
 * in eHealth.
 *
 * Values match what `medication_request_requests.status` stores.
 *
 * @see https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/17053253632
 */
enum MedicationRequestStatus: string
{
    use EnumUtils;
    use ResolvesEHealthStatus;

    case DRAFT = 'draft';
    case NEW = 'new';
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case SIGNED = 'signed';
    case ACTIVE = 'active';
    case BLOCKED = 'blocked';
    case COMPLETED = 'completed';
    case REJECTED = 'rejected';
    case EXPIRED = 'expired';
    case ENTERED_IN_ERROR = 'entered-in-error';

    /**
     * Statuses where the prescription has not been signed yet, so it can still be withdrawn
     * without a KEP-signed reason code.
     *
     * @return list<self>
     */
    public static function unsigned(): array
    {
        return [self::DRAFT, self::NEW];
    }

    public function isUnsigned(): bool
    {
        return in_array($this, self::unsigned(), true);
    }

    public function label(): string
    {
        return __('care-plan.medication_request_status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::ACTIVE,
            self::COMPLETED,
            self::SIGNED => 'badge-green',

            self::NEW,
            self::DRAFT => 'badge-yellow',

            self::PENDING,
            self::PROCESSING => 'badge-blue',

            self::REJECTED,
            self::EXPIRED,
            self::BLOCKED,
            self::ENTERED_IN_ERROR => 'badge-red',
        };
    }
}
