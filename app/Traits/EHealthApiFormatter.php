<?php

declare(strict_types=1);

namespace App\Traits;

use App\Core\Arr;

/**
 * Trait that formats data into API format.
 */
trait EHealthApiFormatter
{
    /** Metadata search accepts literal true/false, not Guzzle's boolean 1/0. */
    protected function normalizeActiveFilter(array $query): array
    {
        if (isset($query['is_active']) && is_bool($query['is_active'])) {
            $query['is_active'] = $query['is_active'] ? 'true' : 'false';
        }

        return $query;
    }

    /**
     * Format data for eHealth API calls.
     *
     * @param  array  $data  The data to format
     * @param  array  $dateFields  Array of field names that should be treated as dates (optional)
     * @return array Formatted data
     */
    protected function format(array $data, array $dateFields = []): array
    {
        return removeEmptyKeys(Arr::toSnakeCase($this->convertDates($data, $dateFields)));
    }

    /**
     * Recursively convert dd.mm.yyyy date fields to ISO 8601 format.
     *
     * @param  array  $data  The data to process
     * @param  array  $dateFields  Array of field names that should be treated as dates
     * @return array Processed data with converted dates
     */
    private function convertDates(array $data, array $dateFields): array
    {
        return collect($data)
            ->map(function ($value, $key) use ($dateFields) {
                if (is_array($value)) {
                    return $this->convertDates($value, $dateFields);
                }

                if (is_string($value) && in_array($key, $dateFields, true) && filled($value)) {
                    return convertToYmd($value);
                }

                return $value;
            })
            ->toArray();
    }
}
