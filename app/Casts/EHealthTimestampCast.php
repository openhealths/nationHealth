<?php

declare(strict_types=1);

namespace App\Casts;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

class EHealthTimestampCast implements CastsAttributes
{
    /**
     * {@inheritDoc}
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if (!$value) {
            return null;
        }

        return Carbon::parse($value, 'UTC')->setTimezone(config('app.timezone'))->format('d.m.Y H:i');
    }

    /**
     * {@inheritDoc}
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if (!$value) {
            return null;
        }

        // get() treats stored timestamps as UTC. Normalize before dropping the offset,
        // otherwise local evening values can be read back as the following calendar day.
        return Carbon::parse($value, config('app.timezone'))->utc()->format('Y-m-d H:i:s');
    }
}
