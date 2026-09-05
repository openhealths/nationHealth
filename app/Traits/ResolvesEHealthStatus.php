<?php

declare(strict_types=1);

namespace App\Traits;

/**
 * eHealth spells statuses inconsistently: upper case in API responses, lower case in our
 * tables, and `-` or `_` between words depending on the endpoint. Enums using this trait keep
 * the spelling our own tables store and accept every variant on the way in.
 */
trait ResolvesEHealthStatus
{
    public static function resolve(?string $status): ?static
    {
        if ($status === null || trim($status) === '') {
            return null;
        }

        $needle = strtolower(trim($status));
        $spellings = array_unique([
            $needle,
            str_replace('_', '-', $needle),
            str_replace('-', '_', $needle),
        ]);

        foreach (static::cases() as $case) {
            if (in_array(strtolower($case->value), $spellings, true)) {
                return $case;
            }
        }

        return null;
    }

    /**
     * Label for a status that may not be one we know about, so unmapped values coming back from
     * eHealth are still shown rather than silently blanked.
     */
    public static function labelFor(?string $status): string
    {
        $resolved = static::resolve($status);

        if ($resolved !== null) {
            return $resolved->label();
        }

        return $status !== null && trim($status) !== '' ? $status : '—';
    }

    public static function colorFor(?string $status): string
    {
        return static::resolve($status)?->color() ?? 'badge-dark';
    }
}
