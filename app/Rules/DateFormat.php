<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Exception;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\ValidationRule;

class DateFormat implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $format = config('app.date_format');

        try {
            Carbon::createFromFormat($format, $value);
        } catch (Exception $err) {
            $fail(__('validation.date_format', ['format' => $format]));
        }
    }
}
