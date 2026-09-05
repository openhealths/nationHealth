<?php

declare(strict_types=1);

namespace App\Enums\Person;

use App\Traits\EnumUtils;

/**
 * see https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/18422661631/PERSON_VERIFICATION_STATUSES
 */
enum VerificationStatus: string
{
    use EnumUtils;

    case CHANGES_NEEDED = 'CHANGES_NEEDED';
    case IN_REVIEW = 'IN_REVIEW';
    case NOT_VERIFIED = 'NOT_VERIFIED';
    case VERIFICATION_NEEDED = 'VERIFICATION_NEEDED';
    case VERIFICATION_NOT_NEEDED = 'VERIFICATION_NOT_NEEDED';
    case VERIFIED = 'VERIFIED';

    public function label(): string
    {
        return match ($this) {
            self::CHANGES_NEEDED => __('patient-verifications.statuses.changes_needed'),
            self::IN_REVIEW => __('patient-verifications.statuses.in_review'),
            self::NOT_VERIFIED => __('patient-verifications.statuses.not_verified'),
            self::VERIFICATION_NEEDED => __('patient-verifications.statuses.verification_needed'),
            self::VERIFICATION_NOT_NEEDED => __('patient-verifications.statuses.verification_not_needed'),
            self::VERIFIED => __('patient-verifications.statuses.verified')
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::VERIFIED => 'badge-green',

            self::CHANGES_NEEDED,
            self::IN_REVIEW,
            self::VERIFICATION_NEEDED => 'badge-yellow',

            self::NOT_VERIFIED => 'badge-red',

            self::VERIFICATION_NOT_NEEDED => 'badge-dark',
        };
    }
}
