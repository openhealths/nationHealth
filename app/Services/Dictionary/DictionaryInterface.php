<?php

declare(strict_types=1);

namespace App\Services\Dictionary;

interface DictionaryInterface
{
    /**
     * Get unique dictionary identifier key.
     *
     * @return string The unique key used for caching and registration
     */
    public function getKey(): string;

    /**
     * Fetch raw dictionary data from the source.
     *
     * @return array Raw dictionary data structure
     */
    public function fetch(): array;
}
