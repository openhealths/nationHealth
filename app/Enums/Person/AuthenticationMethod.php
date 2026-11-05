<?php

declare(strict_types=1);

namespace App\Enums\Person;

use App\Traits\EnumUtils;

enum AuthenticationMethod: string
{
    use EnumUtils;

    case OTP = 'OTP';
    case OFFLINE = 'OFFLINE';
    case THIRD_PERSON = 'THIRD_PERSON';
    case NA = 'NA';

    /**
     * Resolve the actual OTP/OFFLINE confirmation type, not the patient's selected method.
     * For THIRD_PERSON, PatientApprovalService resolves the representative's method into
     * the internal `reference` field. Unresolved and unknown types must not imply OFFLINE.
     *
     * @param  array<string, mixed>  $method  Validated API data or a resolved confirmation method.
     */
    public static function confirmationMethod(array $method): ?self
    {
        $type = self::tryFrom($method['type'] ?? '');
        if ($type === self::THIRD_PERSON) {
            $type = self::tryFrom($method['reference']['type'] ?? '');
        }

        return match ($type) {
            self::OTP, self::OFFLINE => $type,
            default => null,
        };
    }

    /**
     * Read phone_number from Get Person Authentication methods or number from approval details.
     * A resolved THIRD_PERSON method carries the representative's phone in `reference`.
     *
     * @param  array<string, mixed>  $method
     */
    public static function confirmationPhone(array $method): ?string
    {
        if (self::tryFrom($method['type'] ?? '') === self::THIRD_PERSON) {
            $method = $method['reference'] ?? $method;
        }

        return $method['phone_number'] ?? ($method['number'] ?? null);
    }

    /**
     * Name of the method as the AUTHENTICATION_METHOD dictionary gives it. NA is not part of the dictionary.
     *
     * @return string
     */
    public function label(): string
    {
        if ($this === self::NA) {
            return __('patients.authentication_method.na');
        }

        return dictionary()->basics()->byName('AUTHENTICATION_METHOD')->asCodeDescription()->get($this->value);
    }
}
