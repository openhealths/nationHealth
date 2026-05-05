<?php

declare(strict_types=1);

namespace App\Core;

use Illuminate\Support\Arr as BaseArr;
use Illuminate\Support\Str;

class Arr extends BaseArr
{
    public static function toSnakeCase(array $array): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            $newKey = Str::snake($key);

            if (is_array($value)) {
                $result[$newKey] = self::toSnakeCase($value);
            } else {
                $result[$newKey] = $value;
            }
        }

        return $result;
    }

    /**
     * Recursively convert all array or object keys to camelCase.
     */
    public static function toCamelCase(array|object $data): array
    {
        $result = [];

        $array = is_object($data) ? (array)$data : $data;

        foreach ($array as $key => $value) {
            $newKey = is_string($key) ? Str::camel($key) : $key;

            if (is_array($value) || is_object($value)) {
                $result[$newKey] = self::toCamelCase($value);
            } else {
                $result[$newKey] = $value;
            }
        }

        return $result;
    }

    public static function snakeKeys(array|object $data): array
    {
        return self::toSnakeCase($data);
    }

    /**
     * Recursively replaces specific 'id' keys with 'uuid' or renames other specific 'Id' keys to '_uuid' suffix.
     * This method assumes that if a key ends with 'Id' (camelCase) or '_id' (snake_case) and is to be replaced,
     * its value is intended to be a UUID.
     *
     * @param  array|object  $data  The input array or object.
     * @return array The array with keys replaced.
     */
    public static function replaceIdsKeysToUuid(array|object $data): array
    {
        $result = [];
        $array = is_object($data) ? (array)$data : $data;

        foreach ($array as $key => $value) {
            $newKey = $key;
            $newValue = $value;

            // Recursive call for nested arrays/objects
            if (is_array($value) || is_object($value)) {
                $newValue = self::replaceIdsKeysToUuid($value);
            }

            // Determine standardized key for mapping
            $camelCaseKey = Str::camel($key);

            // Apply specific key renames based on common patterns from eHealth API
            switch ($camelCaseKey) {
                case 'id':
                    // If 'id' is a generic ID and its value is a UUID, rename key to 'uuid'
                    // This handles cases like `party: { id: "some-uuid" }` -> `party: { uuid: "some-uuid" }`
                    if (is_string($value) && Str::isUuid($value)) {
                        $newKey = 'uuid';
                    }
                    break;
                case 'legalEntityId':
                    $newKey = 'legal_entity_uuid';
                    break;
                case 'divisionId':
                    $newKey = 'division_uuid';
                    break;
                case 'partyId':
                    $newKey = 'party_uuid';
                    break;
                // Add other specific mappings here if needed (e.g., 'requestId' to 'request_uuid')
                default:
                    // If not explicitly handled above, keep the key as is (it will be snake_cased by snakeKeys later if needed)
                    break;
            }

            $result[$newKey] = $newValue;
        }

        return $result;
    }

    public static function removeEmptyKeys(array $array): array
    {
        foreach ($array as $key => &$value) {
            if (is_array($value)) {
                $value = self::removeEmptyKeys($value);
            }
            if ($value === null || $value === [] || $value === '') {
                unset($array[$key]);
            }
        }
        return $array;
    }

    /**
     * Sort an array by key in ascending order recursively.
     *
     * @param $array
     * @return array
     */
    public static function sortArrayRecursive($array): array
    {
        if (!is_array($array)) {
            return [$array];
        }

        ksort($array);

        foreach ($array as $key => $value) {
            $array[$key] = self::sortArrayRecursive($value);
        }

        return $array;
    }
}
