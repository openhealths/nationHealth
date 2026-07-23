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

if (!function_exists('contractIdFormLabel')) {
    /**
     * Human-readable label for contract id_form dictionary code (e.g. GENERAL → Загальний реімбурсаційний договір).
     */
    function contractIdFormLabel(?string $idFormCode, mixed $contractType = null): ?string
    {
        if (!is_string($idFormCode) || $idFormCode === '') {
            return null;
        }

        $type = strtoupper((string) (is_object($contractType) && property_exists($contractType, 'value')
            ? $contractType->value
            : $contractType));

        $dictionaryName = $type === 'REIMBURSEMENT'
            ? 'REIMBURSEMENT_CONTRACT_TYPE'
            : 'CONTRACT_TYPE';

        try {
            $label = dictionary()->basics()->byName($dictionaryName)->asCodeDescription()->get($idFormCode);

            if (is_string($label) && $label !== '') {
                return $label;
            }
        } catch (\Throwable) {
            // Fall back to local translations when the eHealth dictionary is unavailable.
        }

        $langKey = 'contracts.id_form.'.$type.'.'.$idFormCode;
        $translated = __($langKey);

        if ($translated !== $langKey) {
            return $translated;
        }

        return $idFormCode;
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
