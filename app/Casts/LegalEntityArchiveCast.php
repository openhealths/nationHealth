<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

class LegalEntityArchiveCast implements CastsAttributes
{
    /**
     * Cast the given value.
     *
     * @param  array<string, mixed>  $attributes
     * @return array
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (empty($value)) {
            return [];
        }

        $arrayData = \is_array($value) ? $this->convertValueToArray($value) : json_decode($value, true) ?? [];

        $arr = [];

        foreach ($arrayData as $subArray) {
            foreach ($subArray as $key => $subValue) {
                $subArray[$key] = $key === 'date' ? convertToAppDateFormat($subValue) : $subArray[$key];
            }

            $arr[] = $subArray;
        }

        return $arr;
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  array<string, mixed>  $attributes
     * @return array
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        if (empty($value)) {
            return json_encode([]);
        }

        $arrayData = \is_array($value) ? $this->convertValueToArray($value) : json_decode($value, true) ?? [];

        $arr = [];

        foreach ($arrayData as $subArray) {
            foreach ($subArray as $key => $subValue) {
                $subArray[$key] = $key === 'date' ? convertToISO8601($subValue) : $subArray[$key];
            }

            $arr[] = $subArray;
        }

        return json_encode($arr);
    }

    /**
     * Converts the given value data (string with jsons or an array) to an array.
     *
     * @param  array  $value  The value to be converted.
     * @return array The converted array.
     */
    protected function convertValueToArray(array $value): array
    {
        $arr = [];

        foreach ($value as $jsonData) {
            $arr[] = \is_array($jsonData) ? $jsonData : json_decode($jsonData, true) ?? [];
        }

        return $arr;
    }
}
