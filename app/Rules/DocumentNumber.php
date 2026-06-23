<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class DocumentNumber implements ValidationRule
{
    public function __construct(protected string $type)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        //If the type is not selected, skip (this will catch the validation required on the type field)
        if (empty($this->type)) {
            return;
        }

        $isValid = match ($this->type) {
            'PASSPORT' => (bool) preg_match('/^([А-ЯІЄЇ]{2}\d{6}|\d{9})$/u', $value), // Old passport or ID card
            'NATIONAL_ID' => (bool) preg_match('/^[0-9]{9}$/u', $value),
            'BIRTH_CERTIFICATE' => (bool) preg_match('/^((I|II|III|IV|V|VI|VII|VIII|IX|X|XI|XII|[0-9А-ЯІЄЇ-]{2,})\-?[А-ЯІЄЇ]{2})?\d{6}$/u', $value),
            'MARRIAGE_CERTIFICATE' => (bool) preg_match('/^((I|II|III|IV|V|VI|VII|VIII|IX|X|XI|XII|[0-9А-ЯІЄЇ-]{2,})\-?[А-ЯІЄЇ]{2})?\d{6}$/u', $value),
            'COMPLEMENTARY_PROTECTION_CERTIFICATE' => (bool) preg_match('/^((?![ЫЪЭЁ])([А-ЯҐЇІЄ])){2}[0-9]{6}$/u', $value),
            'REFUGEE_CERTIFICATE' => (bool) preg_match('/^((?![ЫЪЭЁ])([А-ЯҐЇІЄ])){2}[0-9]{6}$/u', $value),
            'TEMPORARY_CERTIFICATE' => (bool) preg_match('/^(((?![ЫЪЭЁ])([А-ЯҐЇІЄ])){2}[0-9]{4,6}|[0-9]{9}|((?![ЫЪЭЁ])([А-ЯҐЇІЄ])){2}[0-9]{5}\\/[0-9]{5})$/u', $value),
            'TEMPORARY_PASSPORT' => (bool) preg_match('/^((?![ЫЪЭЁыъэё@%&$^#`~:,.*|}{?!])[A-ZА-ЯҐЇІЄ0-9№\\/()-]){2,25}$/u', $value),
            'TAX_ID' => (bool) preg_match('/^\d{10}$/', $value),
            // For other documents, we allow almost everything so as not to block unnecessary if there is no clear specification
            default => (bool) preg_match('/^[a-zA-Z0-9А-ЯІЄЇ\-\s]+$/u', $value),
        };

        if (!$isValid) {
            $fail(__('validation.custom.document_number_format', ['type' => $this->type]));
        }
    }
}
