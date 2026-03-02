<?php

declare(strict_types=1);

namespace App\Rules\DivisionRules;

use Closure;
use Illuminate\Support\Str;
use Illuminate\Contracts\Validation\ValidationRule;

class LocationRule implements ValidationRule
{
    protected string $message;

    public function __construct(protected array $division)
    {
    }

    /**
     * Run the validation rule. Check that location longitude and latitude specified in pair simultaneously
     *
     * @param  \Closure(string): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $field = Str::afterLast($attribute, '.');

        $emptyLongitude = ((float) ($this->division['location']['longitude'] ?? 0)) === 0.0;
        $emptyLatitude = ((float) ($this->division['location']['latitude'] ?? 0)) === 0.0;

        if ($emptyLongitude && $emptyLatitude) {
            return;
        }

        if (!preg_match('/^-?([1-8]?[1-9]|[1-9]0|0)\\.\\d{1,6}/', number_format($value, 6, '.', ''))) {
            $fail(__('divisions.errors.location.loсation_misformat'));

            return;
        }

        if ($field === 'longitude' && $emptyLongitude && !$emptyLatitude) {
            $fail(__('divisions.errors.location.longitude_required'));

            return;
        }

        if ($field === 'latitude' && !$emptyLongitude && $emptyLatitude) {
            $fail(__('divisions.errors.location.latitude_required'));
        }
    }
}
