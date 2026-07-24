<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

class LegalEntityAccreditationCast implements CastsAttributes
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

        $arrayData = \is_array($value) ? $value : json_decode($value, true) ?? [];

        foreach ($arrayData as $key => $value) {
            switch ($key) {
                case 'issued_date':
                    $arrayData[$key] = convertToAppDateFormat($value);
                    break;
                case 'expiry_date':
                    $arrayData[$key] = convertToAppDateFormat($value);
                    break;
                case 'order_date':
                    $arrayData[$key] = convertToAppDateFormat($value);
                    break;
                default:
                    // Is not processed key, leave it as is
                    $arrayData[$key] ??= "";
            }
        }

        return $arrayData;
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  array<string, mixed>  $attributes
     * @return array
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        $arrayData = \is_array($value) ? $value : json_decode($value, true) ?? [];

        foreach ($arrayData as $key => $value) {
            switch ($key) {
                case 'issued_date':
                    $arrayData[$key] = convertToISO8601($value);
                    break;
                case 'expiry_date':
                    $arrayData[$key] = convertToISO8601($value);
                    break;
                case 'order_date':
                    $arrayData[$key] = convertToISO8601($value);
                    break;
                default:
                    // Is not processed key, leave it as is
                    $arrayData[$key] ??= "";
            }
        }

        return json_encode($arrayData);
    }
}
