<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class Name implements ValidationRule
{
    /**
     * Run the validation rule.

     *
     * @param  \Closure(string): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        foreach ($this->getVvalidationFieldsMap() as $method => $errorMessage) {
            if (!$this->$method($value)) {
                $fail($errorMessage);

                // IMPORTANT: after first error occurs do return because Laravel shows only first error message
                return;
            }
        }
    }

    /**
     * Get Validation Fiselds Map (per method for differ case)
     *
     * @return array
     */
    protected function getVvalidationFieldsMap()
    {
        return [
            'checkCommonNamePattern' => ' :attribute містить неприпустимі символи.',
            'checkFirstLetterNamePattern' => ' :attribute має починатись лише з літери',
            'checkEndsNamePattern' => ' :attribute не може закінчуватися таким символом',
            'checkTwiceSpecialCharsPattern' => ' :attribute містить дубльовані спеціальні символи',
        ];
    }

    /**
     * Checks if the provided value matches a common name pattern.
     * see: https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/583402887/Create+employee+request+v2
     *
     * This method validates the given string to ensure it conforms to expected
     * patterns for common names: only ukrainian letters and special chars: "-", "'", "\", "+", " "
     *
     * @param  string  $value  The value to be checked against the name pattern.
     * @return bool Returns true if the value matches the pattern, false otherwise.
     */
    protected function checkCommonNamePattern(string $value): bool
    {
        return (bool) preg_match('/^(?!.*[ЫЪЭЁыъэё@%&$^#])[А-ЯҐЇІЄа-яґїіє’\\\'\\- ]+$/u', $value);
    }

    /**
     * Checks if the first letter of the given name matches a specific pattern.
     * see: https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/19155189761/RCC_+CSI-3870+Create+Update+Legal+Entity+V2
     *
     * @param  string  $value  The name value to be checked.
     * @return bool Returns true if the first letter matches the required pattern, false otherwise.
     */
    protected function checkFirstLetterNamePattern(string $value): bool
    {
        return (bool) preg_match('/^[А-ЩЬЮЯҐЄІЇа-щьюяґєії]/u', $value);
    }

    /**
     * Checks if the given value ends with a specific name pattern.
     * see: https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/19155189761/RCC_+CSI-3870+Create+Update+Legal+Entity+V2
     *
     * @param  string  $value  The value to be checked.
     * @return bool Returns false if the value matches the ending name pattern, true otherwise.
     */
    protected function checkEndsNamePattern(string $value): bool
    {
        return !(bool) preg_match("/[\\s\\-\\/']$/", $value);
    }

    /**
     * Checks if the given value contains special characters appearing twice in a row.
     * see: https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/19155189761/RCC_+CSI-3870+Create+Update+Legal+Entity+V2
     *
     * @param  string  $value  The input string to validate.
     * @return bool Returns false if the pattern is valid, true otherwise.
     */
    protected function checkTwiceSpecialCharsPattern(string $value): bool
    {
        return !(bool) preg_match("/([\\s\\.\\/\\-'])\\1/", $value);
    }
}
