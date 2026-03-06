<?php

declare(strict_types=1);

namespace App\Services\Dictionary\Dictionaries;

use App\Classes\eHealth\EHealth;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Services\Dictionary\DictionaryInterface;
use App\Traits\FormTrait;
use Illuminate\Http\Client\ConnectionException;

class BasicDictionary implements DictionaryInterface
{
    use FormTrait;

    /**
     * Dictionary unique identifier key.
     */
    public const string KEY = 'dictionaries.basic';

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
     * Fetch basic dictionaries from eHealth API.
     *
     * Retrieves all general-purpose dictionaries including device types,
     * classification codes, status enums and other reference data.
     *
     * @return array Raw dictionary data from eHealth API
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function fetch(): array
    {
        return EHealth::dictionary()->getMany()->getData();
    }
}
