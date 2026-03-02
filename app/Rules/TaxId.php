<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use App\Models\User;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;
use App\Core\Arr;

class TaxId implements ValidationRule, DataAwareRule
{
    /**
     * The entire data array under validation.
     *
     * @var array
     */
    protected array $data = [];

    /**
     * Flag indicating if the ID is a passport/national ID instead of a tax ID.
     *
     * This field is used to determine the validation logic.
     *
     * @var bool
     */
    protected bool $noTaxId = false;

    /**
     * The email associated with the person, used for additional checks.
     *
     * This email is used to fetch the user's data for comparison.
     *
     * @var string|null
     */
    protected ?string $email = null;

    /**
     * Set the data under validation and determine the context (party or owner).
     *
     * @param  array  $data
     * @return $this
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        $contextData = Arr::get($data, 'party') ?? Arr::get($data, 'owner');

        if (is_array($contextData)) {

            $this->noTaxId = (bool)($contextData['noTaxId'] ?? false);
            $this->email = $contextData['email'] ?? null;
        }

        return $this;
    }

    /**
     * Run the validation rule.
     *
     * @param  \Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Check if the validation is for a passport/national ID.
        if ($this->noTaxId) {
            // A national ID can be either 9 digits or 2 Ukrainian letters followed by 6 digits.
            // The "\\d" correctly escapes the backslash for the regex engine.
            if (!preg_match('/^([0-9]{9}|[А-ЯЁЇIЄҐ]{2}\\d{6})$/u', $value)) {
                $fail(__('validation.attributes.errors.invalidNationalId'));
            }

            return;
        }

        // The logic for a standard tax ID (ІПН).
        // It must be a 10-digit number.
        if (!preg_match('/^[0-9]{10}$/', $value)) {
            $fail(__('validation.attributes.errors.invalidTaxId'));

            return;
        }

        // If an email is provided, we perform an additional check against the database.
        if ($this->email) {
            $this->validateWithEmail($value, $fail);
        }
    }

    /**
     * Perform additional validation against the database based on the provided email.
     *
     * @param  mixed  $value  The tax ID from the request.
     * @param  Closure  $fail  The failure callback.
     */
    private function validateWithEmail(mixed $value, Closure $fail): void
    {
        // Find the user associated with the provided email.
        $user = User::where('email', $this->email)->first();

        // We cannot perform a check if the user or their party data is missing.
        if (!$user?->party) {
            return;
        }

        // The following logic is based on the eHealth requirements for comparing the tax ID.
        // Reference: https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/583403638/Create+Update+Legal+Entity+V2

        // Check 1: The tax ID from the request must match the tax ID stored in the user's party data.
        if ($user->party->taxId && $value !== $user->party->taxId) {
            $fail(__('validation.employee.wrong_tax_id'));
        }

        // Check 2: The request must not have a missing tax ID if one exists in the database.
        // This validates that the user cannot clear their tax ID if it's already set.
        if ($user->party->taxId && empty($value)) {
            $fail(__('validation.employee.missed_tax_id'));
        }
    }
}
