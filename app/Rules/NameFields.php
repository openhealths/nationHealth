<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * See: https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/19331907630/REST+API+Create+Update+Person+Request+v2+API-010-055-0015#Validate-name-fields
 */
readonly class NameFields implements ValidationRule
{
    /**
     * @param  string  $language  LANGUAGE dictionary code that selects the allowed alphabet (uk by default).
     */
    public function __construct(private string $language = 'uk')
    {
    }

    /**
     * Run the validation rule.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     * @return void
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Presence and type are the job of the required/string rules, which may run after this one
        if (!is_string($value)) {
            return;
        }

        $isLatin = $this->language !== 'uk';

        // Checking for allowed symbols
        $allowed = $isLatin
            ? "/^[A-Za-z\s.\-\/']+$/u"
            : "/^[А-ЩЬЮЯҐЄІЇа-щьюяґєії\s.\-\/']+$/u";
        if (!preg_match($allowed, $value)) {
            $fail(__($isLatin
                ? 'validation.custom.name_fields.only_english'
                : 'validation.custom.name_fields.only_ukrainian'));

            return;
        }

        // Checking first symbol
        $start = $isLatin ? "/^[A-Za-z]/u" : "/^[А-ЩЬЮЯҐЄІЇа-щьюяґєії]/u";
        if (!preg_match($start, $value)) {
            $fail(__($isLatin
                ? 'validation.custom.name_fields.start_english'
                : 'validation.custom.name_fields.start_ukrainian'));

            return;
        }

        // Checking last symbol
        $last = "/[\s\-\/']$/u";
        if (preg_match($last, $value)) {
            $fail(__('validation.custom.name_fields.invalid_ending'));

            return;
        }

        // Checking repeated special characters
        $double = "/([\s.\-\/'])\\1/u";
        if (preg_match($double, $value)) {
            $fail(__('validation.custom.name_fields.repeated_special'));
        }
    }
}
