<?php

declare(strict_types=1);

namespace App\Casts;

use Carbon\Carbon;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * When using $model->fill([...]), Laravel does not convert empty strings ('') to null by default.
 * As a result, when a field is cast as date:Y-m-d, Laravel attempts to parse the empty string as a date.
 * This can lead to unexpected behavior, such as the date being interpreted as 1970-01-01 or even the current date,
 * depending on the database or casting logic.
 *
 * This custom cast aimed to return date in ISO8601 format (if date present) or null if date is absent.
 * This need to ensure some validation on data input fields
 */
class NullableDateCast implements CastsAttributes
{
    /**
     * Cast the given value.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return $value ? Carbon::parse($value)->format('Y-m-d') : null;
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return $value;
    }
}
