<?php

declare(strict_types=1);

namespace App\Rules;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

readonly class PastDateTime implements ValidationRule
{
    public function __construct(private string $date)
    {
    }

    /**
     * Run the validation rule.
     * A date or time that cannot be parsed is reported by the format rules of its own fields.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     * @return void
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (empty($this->date) || empty($value)) {
            return;
        }

        try {
            $datetime = CarbonImmutable::createFromFormat(config('app.date_format') . ' H:i', $this->date . ' ' . $value);
        } catch (InvalidFormatException) {
            return;
        }

        if ($datetime->isFuture()) {
            // The field may hold only the time, while its date sits in another field, so the whole moment is named
            $fail(__('validation.before_or_equal_now_date_time', ['value' => $this->date . ' ' . $value]));
        }
    }
}
