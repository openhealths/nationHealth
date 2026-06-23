<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class OnlyOneDocument implements ValidationRule
{
    public function __construct(protected array $documents)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        //If the documents is not present, skip (this should be caught by the required validation on the documents field)
        if (empty($this->documents)) {
            return;
        }

        // Only validate the current field's value ($value = this document's type)
        if (!isset($value)) {
            return;
        }

        // Check if there are more than one document of the same type
        if (\count(array_filter($this->documents, fn (array $doc) => $doc['type'] === $value)) > 1) {
            $fail(__('validation.custom.document_unique'));

            return;
        }

        // Check if there are present the documents of type PASSPORT or NATIONAL_ID at the same time
        if (
            ($value === 'PASSPORT' || $value === 'NATIONAL_ID') &&
            \count(array_filter($this->documents, fn (array $doc) => $doc['type'] === 'PASSPORT' || $doc['type'] === 'NATIONAL_ID')) > 1
        ) {
            $fail(__('validation.custom.person.national_id_passport_mutual_exclusion'));
        }
    }

}
