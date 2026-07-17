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
        if (empty($this->type)) {
            return;
        }

        $isValid = match ($this->type) {
            'PASSPORT' => (bool) preg_match('/^((?![ЫЪЭЁ])([А-ЯҐЇІЄ])){2}[0-9]{6}$/u', (string) $value),
            'NATIONAL_ID' => (bool) preg_match('/^[0-9]{9}$/u', (string) $value),
            'BIRTH_CERTIFICATE' => (bool) preg_match('/^((I|II|III|IV|V|VI|VII|VIII|IX|X|XI|XII|[0-9А-ЯІЄЇ-]{2,})\-?[А-ЯІЄЇ]{2})?\d{6}$/u', (string) $value),
            'MARRIAGE_CERTIFICATE' => (bool) preg_match('/^((I|II|III|IV|V|VI|VII|VIII|IX|X|XI|XII|[0-9А-ЯІЄЇ-]{2,})\-?[А-ЯІЄЇ]{2})?\d{6}$/u', (string) $value),
            'COMPLEMENTARY_PROTECTION_CERTIFICATE' => (bool) preg_match('/^((?![ЫЪЭЁ])([А-ЯҐЇІЄ])){2}[0-9]{6}$/u', (string) $value),
            'REFUGEE_CERTIFICATE' => (bool) preg_match('/^((?![ЫЪЭЁ])([А-ЯҐЇІЄ])){2}[0-9]{6}$/u', (string) $value),
            'PERMANENT_RESIDENCE_PERMIT', 'TEMPORARY_CERTIFICATE' => (bool) preg_match('/^(((?![ЫЪЭЁ])([А-ЯҐЇІЄ])){2}[0-9]{4,6}|[0-9]{9}|((?![ЫЪЭЁ])([А-ЯҐЇІЄ])){2}[0-9]{5}\\/[0-9]{5})$/u', (string) $value),
            'TEMPORARY_PASSPORT' => (bool) preg_match('/^((?![ЫЪЭЁыъэё@%&$^#`~:,.*|}{?!])[A-ZА-ЯҐЇІЄ0-9№\\/()-]){2,25}$/u', (string) $value),
            'TAX_ID' => (bool) preg_match('/^\d{10}$/', (string) $value),
            default => (bool) preg_match('/^[a-zA-Z0-9А-ЯІЄЇ\-\s]+$/u', (string) $value),
        };

        if (!$isValid) {
            $fail($this->formatErrorMessage());
        }
    }

    private function formatErrorMessage(): string
    {
        $documentLabel = __('patients.documents.' . $this->type);
        if ($documentLabel === 'patients.documents.' . $this->type) {
            $documentLabel = $this->type;
        }

        $messageKey = 'validation.custom.document_number_format.' . $this->type;
        $message = __($messageKey, ['document' => $documentLabel]);

        if ($message === $messageKey) {
            return __('validation.custom.document_number_format.default', ['document' => $documentLabel]);
        }

        return $message;
    }
}
