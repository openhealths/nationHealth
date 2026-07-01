<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use App\Core\Arr;
use App\Models\User;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

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
     * The user associated with the provided email, used for additional checks.
     *
     * @var User|null
     */
    protected ?User $user = null;

    /**
     * Flag indicating if the validation is for an owner (true) or a party (false).
     *
     * This field is used to determine the context of the validation.
     *
     * @var bool
     */
    protected bool $isOwner = false;

    /**
     * The EDRPOU (ЄДРПОУ) code associated with the entity, used for additional checks.
     *
     * This field is used to validate the EDRPOU against the provided tax ID or national ID when noTaxId is true.
     *
     * @var string|null
     */
    protected ?string $edrpou = '';

    /**
     * The documents of user (party or owner) data array under validation.
     *
     * @var array
     */
    protected array $documents = [];

    /**
     * Set the data under validation and determine the context (party or owner).
     *
     * @param  array  $data
     * @return $this
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        // Determine if the data income from owner or party (useful for creation/editing of the LegalEntity)
        $this->isOwner = Arr::get($data, 'owner') !== null;

        $contextData = $this->isOwner ? Arr::get($data, 'owner') : Arr::get($data, 'party');

        if (is_array($contextData)) {
            $this->noTaxId = (bool)($contextData['noTaxId'] ?? false);
            $this->email = $contextData['email'] ?? null;

            // Find the user associated with the provided email (or not find).
            $this->user = User::where('email', $this->email)->first();

            $this->documents = $this->isOwner
                ? ($contextData['documents'] ?? [])
                : ($data['documents'] ?? $this->user?->party?->documents?->toArray() ?? []);

            $this->edrpou = Arr::get($data, 'edrpou', '');
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
            if (empty(array_filter($this->documents, fn (array $doc) => $doc['type'] === 'NATIONAL_ID' || $doc['type'] === 'PASSPORT'))) {
                // If a no any document of type NATIONAL_ID or PASSPORT exists, raise an error.
                $fail(__('validation.employee.owner_passport_mandatory_no_tax_id'));

                return;
            }

            // If the value is a boolean (true/false), we need to fetch the actual document number for validation.
            if (\is_bool($value)) {
                $value = collect($this->documents)
                    ->first(fn (array $doc) => \in_array($doc['type'], ['PASSPORT', 'NATIONAL_ID']))['number'] ?? null;
            }

            // TODO: check if it need to be validated (the same validation used in the document's field)
            // A national ID can be either 9 digits or 2 Ukrainian letters followed by 6 digits.
            // The "\\d" correctly escapes the backslash for the regex engine.
            if (!preg_match('/^([0-9]{9}|[А-ЯЁЇIЄҐ]{2}\\d{6})$/u', $value)) {
                $fail(__('validation.attributes.errors.invalidNationalId'));

                return;
            }

            // If the EDRPOU is set and it not in the standard format (10 digits), we need to ensure that it matches the provided value.
            // TODO: check is it important for OWNER only
            if ($this->isOwner && !preg_match('/^([0-9]{10})$/', $this->edrpou) && $this->edrpou !== $value) {
                $fail(__('validation.custom.mismatch_edrpou_no_tax_id'));
            }

            return;
        }

        // The logic for a standard tax ID (ІПН).
        // It must be a 10-digit number.
        if (!\is_bool($value) && !preg_match('/^[0-9]{10}$/', $value)) {
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
        // We cannot perform a check if the user or their party data is missing.
        if (!$this->user?->party || \is_bool($value)) {
            return;
        }

        // The following logic is based on the eHealth requirements for comparing the tax ID.
        // Reference: https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/583403638/Create+Update+Legal+Entity+V2

        // Check 1: The tax ID from the request must match the tax ID stored in the user's party data.
        if ($this->user->party->taxId && $value !== $this->user->party->taxId) {
            $fail(__('validation.employee.wrong_tax_id'));
        }

        // Check 2: The request must not have a missing tax ID if one exists in the database.
        // This validates that the user cannot clear their tax ID if it's already set.
        if ($this->user->party->taxId && empty($value)) {
            $fail(__('validation.employee.missed_tax_id'));
        }
    }
}
