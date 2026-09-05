<?php

declare(strict_types=1);

namespace App\Enums\Party;

use App\Traits\EnumUtils;
use Illuminate\Support\Facades\Lang;

/**
 * Allowed verification_reason values for PATCH party DRACS death stream.
 *
 * Live IL JSON Schema enum (preprod/demo) accepts only:
 * - MANUAL_DECEASED
 * - MANUAL_NO_DEATH_RECORD
 *
 * Confluence/Apiary narrative text often mentions MANUAL_CONFIRMED /
 * MANUAL_NOT_CONFIRMED, but those values are rejected with
 * "value is not allowed in enum" (params.values = MANUAL_DECEASED,
 * MANUAL_NO_DEATH_RECORD). RCC CSI-4614 dictionary matches the live schema.
 *
 * @see https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/20033273857/RCC_+CSI-4614+PARTY_VERIFICATION_STATUS_REASONS
 * @see https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/18543804740/REST+API+Update+Party+Verification+Status+API-005-049-0003
 */
enum DracsDeathVerificationReason: string
{
    use EnumUtils;

    /** Confirm death act linkage. */
    case MANUAL_DECEASED = 'MANUAL_DECEASED';

    /** Refute death act linkage. */
    case MANUAL_NO_DEATH_RECORD = 'MANUAL_NO_DEATH_RECORD';

    /**
     * Resolve a live schema case from a live token or a legacy/narrative alias.
     *
     * Returns null when the value is neither a live case nor a known alias
     * (validation should reject it).
     */
    public static function tryFromLegacy(string $reason): ?self
    {
        return match ($reason) {
            'MANUAL_CONFIRMED', 'MANUAL_COMFIRMED' => self::MANUAL_DECEASED,
            'MANUAL_NOT_CONFIRMED' => self::MANUAL_NO_DEATH_RECORD,
            default => self::tryFrom($reason),
        };
    }

    public function label(): string
    {
        return Lang::get('party_verification.reasons.' . $this->value);
    }
}
