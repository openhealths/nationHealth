<?php

declare(strict_types=1);

namespace App\Rules\DivisionRules;

use App\Enums\Status;
use Closure;
use App\Models\Division;
use App\Exceptions\CustomValidationException;
use Illuminate\Contracts\Validation\ValidationRule;

class DivisionStatusRule implements ValidationRule
{
    protected Division $division;

    public function __construct(Division $division)
    {
        $this->division = $division;
    }

    /**
     * Run the validation rule. Check that division status = ‘ACTIVE’
     *
     * @param  \Closure(string): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // CustomValidationException
        if ($this->division->status !== Status::ACTIVE) {
            throw new CustomValidationException($this->message(), 'custom');
        }
    }

    protected function message(): string
    {
        return __('validation.attributes.healthcareService.error.division.status');
    }
}
