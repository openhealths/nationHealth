<?php

declare(strict_types=1);

namespace App\Enums\Person;

use App\Traits\EnumUtils;

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
            self::CHANGES_NEEDED => __('patients.status.changes_needed'),
            self::IN_REVIEW => __('patients.status.in_review'),
            self::NOT_VERIFIED => __('patients.status.not_verified'),
            self::VERIFICATION_NEEDED => __('patients.status.verification_needed'),
            self::VERIFICATION_NOT_NEEDED => __('patients.status.verification_not_needed'),
            self::VERIFIED => __('patients.status.verified')
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
