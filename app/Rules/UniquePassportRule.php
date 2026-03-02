<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class UniquePassportRule implements ValidationRule
{
    /**
     * Check unique PASSPORT OR NATIONAL_ID
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     * @return void
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $types = collect($value)->pluck('type');

        if ($types->contains('PASSPORT') && $types->contains('NATIONAL_ID')) {
            $fail(__('Employee can have only one of the following document types: PASSPORT or NATIONAL_ID.'));
        }
    }
}
