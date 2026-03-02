<?php

declare(strict_types=1);

namespace App\Casts\Division;

use App\Models\Division;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

class Location implements CastsAttributes
{
    /**
     * Cast the given value.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if (!is_array($value)) {
            $data = !empty($value) ? json_decode($value, true) : [];
        }

        return empty($data) ? ['latitude' => 0, 'longitude' => 0] : $data;
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        $data = [];

        if (is_array($value)) {
            $data = [$key => $value];
        } else {
            $data = $value ? [$key => json_decode($value, true)] : [];

            if (empty($data)) {
                $data[$key] = Division::getLocationTemplate();
            }
        }

        return json_encode($data[$key]);
    }
}
