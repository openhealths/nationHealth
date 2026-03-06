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

if (!function_exists('convertToAppDateFormat')) {
    function convertToAppDateFormat(?string $dateString): string
    {
        if (empty($dateString)) {
            return '';
        }

        return CarbonImmutable::parse($dateString)->format(config('app.date_format'));
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
