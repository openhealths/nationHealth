<?php

declare(strict_types=1);

namespace App\Enums\Person;

use App\Traits\EnumUtils;
use App\Traits\ResolvesEHealthStatus;
use Illuminate\Support\Facades\Lang;

/**
 * Statuses a medical device prescription (DeviceRequest) goes through.
 *
 * Values match what `device_request_requests.status` stores. Unlike referrals these are never
 * taken into work or queued: a device request is dispensed against, not executed by a provider.
 *
 * @see https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/17053253632
 */
enum DeviceRequestStatus: string
{
    use EnumUtils;
    use ResolvesEHealthStatus;

    case DRAFT = 'draft';
    case NEW = 'new';
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case ACTIVE = 'active';
    case COMPLETED = 'completed';
    case RECALLED = 'recalled';
    case REVOKED = 'revoked';
    case ENTERED_IN_ERROR = 'entered-in-error';

    /**
     * Device prescriptions are listed alongside referrals and share their wording.
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
            self::COMPLETED => 'badge-green',

            self::NEW,
            self::DRAFT => 'badge-yellow',

            self::PENDING,
            self::PROCESSING => 'badge-blue',

            self::RECALLED,
            self::REVOKED,
            self::ENTERED_IN_ERROR => 'badge-red',
        };
    }
}
