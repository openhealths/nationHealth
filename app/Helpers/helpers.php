<?php

declare(strict_types=1);

use App\Classes\eHealth\Services\SchemaService;
use App\Services\Dictionary\DictionaryManager;
use App\Services\SignatureService;
use Carbon\CarbonImmutable;
use App\Models\LegalEntity;

if (!function_exists('removeEmptyKeys')) {
    function removeEmptyKeys(array $array): array
    {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = removeEmptyKeys($value);
                if (empty($array[$key])) {
                    unset($array[$key]);
                }
            } elseif ((empty($value) && $value !== false) || $value === '') {
                unset($array[$key]);
            }
        }

        return $array;
    }
}

if (!function_exists('convertToLocalTimezone')) {
    function convertToLocalTimezone(string $dateString): string
    {
        if (empty($dateString)) {
            return '';
        }

        return CarbonImmutable::parse($dateString)->setTimezone(config('app.timezone'))->toDateTimeString();
    }
}

if (!function_exists('convertToYmd')) {
    function convertToYmd(string $dateString): string
    {
        if (empty($dateString)) {
            return '';
        }

        return CarbonImmutable::parse($dateString)->format('Y-m-d');
    }
}

if (!function_exists('convertToISO8601')) {
    function convertToISO8601(?string $dateString): string
    {
        if (empty($dateString)) {
            return '';
        }

        return CarbonImmutable::parse($dateString)->avoidMutation()
            ->rawFormat('Y-m-d\T'. CarbonImmutable::getTimeFormatByPrecision('second').'\Z');
    }
}

if (!function_exists('convertToEHealthISO8601')) {
    function convertToEHealthISO8601(?string $dateString): string
    {
        if (empty($dateString)) {
            return '';
        }

        return CarbonImmutable::parse($dateString)->utc()->toIso8601ZuluString();
    }
}

if (!function_exists('convertToAppDateFormat')) {
    function convertToAppDateFormat(?string $dateString): string
    {
        if (empty($dateString)) {
            return '';
        }

        return CarbonImmutable::parse($dateString)->format(config('app.date_format'));
    }
}

if (!function_exists('formatDisplayDate')) {
    /**
     * Formats a model/API date for read-only views (handles Carbon, strings, null).
     */
    function formatDisplayDate(mixed $value, ?string $format = null): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $format ??= config('app.date_format');

        if ($value instanceof \DateTimeInterface) {
            return $value->format($format);
        }

        if (is_string($value)) {
            return CarbonImmutable::parse($value)->format($format);
        }

        return '';
    }
}

if (!function_exists('formatDisplayDateTime')) {
    /**
     * Formats a datetime value for read-only views (handles Carbon, ISO strings, null).
     */
    function formatDisplayDateTime(mixed $value, string $format = 'd.m.Y H:i'): string
    {
        return formatDisplayDate($value, $format);
    }
}

if (!function_exists('frontendDateFormat')) {
    function frontendDateFormat(): string
    {
        return config('ehealth.frontend_date_format')[config('app.date_format')] ?? 'dd.mm.yyyy';
    }
}

if (!function_exists('schemaService')) {
    function schemaService(): SchemaService
    {
        return app(SchemaService::class);
    }
}

if (!function_exists('dictionary')) {
    function dictionary(): DictionaryManager
    {
        return app(DictionaryManager::class);
    }
}

if (!function_exists('legalEntity')) {
    function legalEntity(): ?LegalEntity
    {
        // The app('legalEntity') shouldn't be called without condition.
        // We must check if the LegalEntity already has in container.
        // It works, if policy access already has been called and binded to 'legalEntity'.
        if (app()->bound('legalEntity')) {
            return app('legalEntity');
        }

        // If LegalEntity hasn't binded to the container (like 'legal_entity.new.create' route),
        // return null, to avoid an error.
        return null;
    }
}

if (!function_exists('signatureService')) {
    function signatureService(): SignatureService
    {
        return app(SignatureService::class);
    }
}

if (!function_exists('ehealthHasScope')) {
    /**
     * Checks if the active eHealth Bearer token in the session has the specified scope.
     *
     * @param  string  $requiredScope
     * @return bool
     */
    function ehealthHasScope(string $requiredScope): bool
    {
        return ehealthHasAnyScope($requiredScope);
    }
}

if (!function_exists('ehealthHasAnyScope')) {
    /**
     * Checks if the active eHealth Bearer token has at least one of the given scopes.
     */
    function ehealthHasAnyScope(string ...$requiredScopes): bool
    {
        if ($requiredScopes === []) {
            return false;
        }

        if (app()->environment('testing')) {
            return true;
        }

        $tokenKey = config('ehealth.api.oauth.bearer_token');
        if (!session()->has($tokenKey)) {
            return false;
        }

        $token = session()->get($tokenKey);
        if (empty($token) || !is_string($token)) {
            return false;
        }

        try {
            // Decrypt token if it is encrypted in the session
            $token = \Illuminate\Support\Facades\Crypt::decryptString($token);
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            // Raw token format
        } catch (\Throwable $e) {
            return false;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }

        $payloadJson = base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1]));
        $payload = json_decode($payloadJson, true);

        if (!is_array($payload) || !isset($payload['scope'])) {
            return false;
        }

        $scopes = is_string($payload['scope']) ? explode(' ', $payload['scope']) : (array) $payload['scope'];

        foreach ($requiredScopes as $requiredScope) {
            if (in_array($requiredScope, $scopes, true)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('ehealthCanAccessPartyVerification')) {
    /**
     * ADMIN/PHARMACY_OWNER get party_verification:details(+write) but not :read.
     * OWNER/HR typically have :read as well — accept either for list/menu access.
     */
    function ehealthCanAccessPartyVerification(): bool
    {
        return ehealthHasAnyScope('party_verification:read', 'party_verification:details');
    }
}
