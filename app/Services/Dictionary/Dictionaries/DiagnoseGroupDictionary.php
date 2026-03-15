<?php

declare(strict_types=1);

namespace App\Services\Dictionary\Dictionaries;

use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\EHealthResponse;
use App\Services\Dictionary\DictionaryInterface;

class DiagnoseGroupDictionary implements DictionaryInterface
{
    /**
     * Dictionary unique identifier key.
     */
    public const string KEY = 'dictionaries.diagnose_group';

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
        $params = $page === 1 ? [] : ['page' => $page];

        return EHealth::diagnoseGroup()->getMany($params);
    }
}
