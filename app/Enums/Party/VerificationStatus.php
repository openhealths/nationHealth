<?php

declare(strict_types=1);

namespace App\Enums\Party;

use App\Traits\EnumUtils;
use Illuminate\Support\Facades\Lang;

/**
 * Displays the general (cumulative) verification status of Party.
 *
 * @see \App\Traits\ProcessesPartyVerificationResponses
 */
enum VerificationStatus: string
{
    use EnumUtils;

    case VERIFIED = 'VERIFIED';
    case NOT_VERIFIED = 'NOT_VERIFIED';
    case VERIFICATION_NEEDED = 'VERIFICATION_NEEDED';
    case VERIFICATION_NOT_NEEDED = 'VERIFICATION_NOT_NEEDED';

    /**
     * Returns the translated label for status.
     */
    public function label(): string
    {
        return Lang::get('party_verification.statuses.' . $this->value);
    }
}
