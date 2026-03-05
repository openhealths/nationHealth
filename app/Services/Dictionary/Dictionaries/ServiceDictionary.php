<?php

declare(strict_types=1);

namespace App\Services\Dictionary\Dictionaries;

use App\Classes\eHealth\EHealth;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Services\Dictionary\DictionaryInterface;
use Illuminate\Http\Client\ConnectionException;

class ServiceDictionary implements DictionaryInterface
{
    /**
     * Dictionary unique identifier key.
     */
    public const string KEY = 'dictionaries.service';

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
     * Fetch services from eHealth API.
     *
     * Retrieves all available medical services with their hierarchical
     * structure including service groups and nested services.
     *
     * @return array Raw service data from eHealth API
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function fetch(): array
    {
        return EHealth::service()->getMany()->getData();
    }
}
