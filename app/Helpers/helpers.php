<?php

declare(strict_types=1);

use App\Classes\eHealth\EHealthRequest;
use App\Classes\eHealth\Services\SchemaService;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Models\LegalEntity;
use App\Models\User;
use App\Services\Dictionary\DictionaryManager;
use App\Services\SignatureService;
use Carbon\CarbonImmutable;

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

        if (!is_array($payload)) {
            return false;
        }

        $scopes = [];

        foreach (['scope', 'scp'] as $claim) {
            if (!isset($payload[$claim])) {
                continue;
            }

            $claimScopes = is_string($payload[$claim])
                ? preg_split('/\s+/', trim($payload[$claim])) ?: []
                : (array) $payload[$claim];

            $scopes = array_merge($scopes, $claimScopes);
        }

        return in_array($requiredScope, $scopes, true);
    }
}

if (!function_exists('ehealthIsMissingScopeError')) {
    /**
     * Whether an eHealth API error indicates a missing OAuth scope allowance.
     */
    function ehealthIsMissingScopeError(EHealthResponseException $exception, string $requiredScope): bool
    {
        if ($exception->getCode() !== 403) {
            return false;
        }

        $message = $exception->getMessage();

        return str_contains($message, 'Missing allowances')
            && str_contains($message, $requiredScope);
    }
}

if (!function_exists('ehealthApplyMisProxy')) {
    /**
     * Route an eHealth request through the MIS token with msp_drfo when the session token
     * lacks the required scope or when forced after a scope-related 403.
     *
     * @return bool True when MIS proxy headers were applied.
     */
    function ehealthApplyMisProxy(
        EHealthRequest $service,
        ?User $user,
        int $legalEntityId,
        string $requiredScope = 'employee:deactivate',
        bool $force = false,
    ): bool {
        if (app()->environment('testing') || !$user) {
            return false;
        }

        if (!$force && ehealthHasScope($requiredScope)) {
            return false;
        }

        $taxId = $user->resolveParty($legalEntityId)?->taxId
            ?? $user->adminEmployeeForMisAction($legalEntityId)?->party?->taxId;

        if (!$taxId) {
            return false;
        }

        $service->asMis()->withHeaders([
            'msp_drfo' => $taxId,
        ]);

        return true;
    }
}
