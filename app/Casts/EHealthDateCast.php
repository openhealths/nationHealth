<?php

declare(strict_types=1);

namespace App\Casts;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

class EHealthDateCast implements CastsAttributes
{
    /**
     * Cast the given value.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if (!$value) {
            return null;
        }

        $format = config('app.date_format');

        return Carbon::parse($value)->format($format);
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if (!$value) {
            return null;
        }

        // If already in Y-m-d format, return as is
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }

        // ISO 8601 + timezone (microseconds optional)
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z$/', $value)) {
            return $value;
        }

        $format = config('app.date_format');

        return Carbon::createFromFormat($format, $value)?->format('Y-m-d');
    }
}
