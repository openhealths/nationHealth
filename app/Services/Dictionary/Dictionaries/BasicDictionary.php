<?php

declare(strict_types=1);

namespace App\Services\Dictionary\Dictionaries;

use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\EHealthResponse;
use App\Services\Dictionary\DictionaryInterface;

class BasicDictionary implements DictionaryInterface
{
    /**
     * Dictionary unique identifier key.
     */
    public const string KEY = 'dictionaries.basic';

    /**
     * Dictionaries kept out of the cached payload. ICD 10 is separate table.
     *
     */
    public const array EXCLUDED_NAMES = [
        'eHealth/ICD10_AM/condition_codes',
        'eHealth/ICD10_AM_FULL/condition_codes',
        'ORPHAcodes'
    ];

    /**
     * Get the dictionary key.
     *
     * @return string Dictionary identifier for caching and registry
     */
    public function getKey(): string
    {
        return self::KEY;
    }

    /**
     * @inheritDoc
     */
    public function fetch(int $page = 1): EHealthResponse
    {
        // Basic dictionaries don't support pagination, ignore $page parameter
        return EHealth::dictionary()->getMany();
    }

    /**
     * Drop the excluded dictionaries before the payload is cached.
     *
     * @param  array  $data
     * @return array
     */
    public static function prune(array $data): array
    {
        return array_values(
            array_filter(
                $data,
                static fn (array $entry): bool => !in_array($entry['name'] ?? '', self::EXCLUDED_NAMES, true)
            )
        );
    }
}
