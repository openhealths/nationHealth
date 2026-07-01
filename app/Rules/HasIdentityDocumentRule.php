<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class HasIdentityDocumentRule implements ValidationRule
{
    /**
     * The list of document types considered as identity documents.
     *
     * @var array
     */
    protected array $identityDocTypes;

    /**
     * Create a new rule instance.
     *
     * @param  array  $identityDocTypes  The list of required identity document types.
     */
    public function __construct(array $identityDocTypes)
    {
        $this->identityDocTypes = $identityDocTypes;
    }

    /**
     * Run the validation rule.
     *
     * This rule checks if at least one of the provided documents
     * is an identity document (e.g., PASSPORT, NATIONAL_ID).
     *
     * @param  string  $attribute  The attribute being validated (e.g., 'documents').
     * @param  mixed  $value  The value of the attribute (the array of documents).
     * @param  Closure(string): PotentiallyTranslatedString  $fail  The failure callback.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // $value is expected to be the 'documents' array
        if (!is_array($value)) {
            // Fails silently if the input isn't an array, as 'array' rule should catch this.
            return;
        }

        // Get all 'type' values from the submitted documents array
        $typesInSubmission = collect($value)->pluck('type');

        // Find the intersection between the submitted types and the required identity types
        $identityDocsSubmitted = $typesInSubmission->intersect($this->identityDocTypes);

        // If the intersection is empty, it means no valid identity document was provided.
        if ($identityDocsSubmitted->isEmpty()) {
            $fail(__('validation.custom.identity_document_required'));
        }
    }
}
