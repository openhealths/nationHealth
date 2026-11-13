<?php

declare(strict_types=1);

namespace App\Enums\Composition;

/**
 * COMPOSITION_TREATMENT_VIOLATION dictionary codes on a temporary-disability conclusion.
 */
final class TreatmentViolation
{
    public const string DICTIONARY = 'COMPOSITION_TREATMENT_VIOLATION';

    public static function label(?string $code): string
    {
        if ($code === null || $code === '') {
            return '-';
        }

        $label = dictionary()->basics()
            ->byName(self::DICTIONARY)
            ->asCodeDescription()
            ->get($code);

        return is_string($label) && $label !== '' ? $label : $code;
    }
}
