<?php

declare(strict_types=1);

namespace App\Enums\Person;

use App\Traits\EnumUtils;
use App\Traits\ResolvesEHealthStatus;
use Illuminate\Support\Facades\Lang;

/**
 * Statuses an electronic referral (ServiceRequest) goes through, including the executor side:
 * an active referral is taken into work and then completed against a medical event.
 *
 * Values match what `service_request_requests.status` stores.
 *
 * @see https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/17053253632
 */
enum ServiceRequestStatus: string
{
    use EnumUtils;
    use ResolvesEHealthStatus;

    case DRAFT = 'draft';
    case NEW = 'new';
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case PROCESSED = 'processed';
    case ACTIVE = 'active';
    case IN_QUEUE = 'in_queue';
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';
    case USED = 'used';
    case CANCELLED = 'cancelled';
    case RECALLED = 'recalled';
    case REVOKED = 'revoked';
    case ENTERED_IN_ERROR = 'entered-in-error';

    /**
     * Referrals are worded in the neuter gender ("направлення"), so they use their own keys
     * where one exists and fall back to the shared care plan statuses.
     */
    public function label(): string
    {
        $referralKey = 'care-plan.referral_status.'.$this->value;

        if (Lang::has($referralKey)) {
            return __($referralKey);
        }

        return __('care-plan.status.'.str_replace('-', '_', $this->value));
    }

    public function color(): string
    {
        return match ($this) {
            self::ACTIVE,
            self::COMPLETED,
            self::USED => 'badge-green',

            self::NEW,
            self::DRAFT => 'badge-yellow',

            self::PENDING,
            self::PROCESSING,
            self::PROCESSED,
            self::IN_QUEUE,
            self::IN_PROGRESS => 'badge-blue',

            self::CANCELLED,
            self::RECALLED,
            self::REVOKED,
            self::ENTERED_IN_ERROR => 'badge-red',
        };
    }
}
