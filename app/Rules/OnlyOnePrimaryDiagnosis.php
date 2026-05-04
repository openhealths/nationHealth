<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

readonly class OnlyOnePrimaryDiagnosis implements ValidationRule
{
    public function __construct(
        private ?string $classCode = null,
        private array $conditions = []
    ) {
    }

    /**
     * Run the validation rule.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     * @return void
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $diagnoses = collect($value);

        $primaryCount = $diagnoses->filter(fn (array $diagnosis) => $diagnosis['roleCode'] === 'primary')->count();

        if ($primaryCount !== 1) {
            $fail(__('Тільки один основний діагноз може бути.'));

            return;
        }

        if (empty($this->classCode)) {
            return;
        }

        $expectedSystem = $this->classCode === 'PHC'
            ? 'eHealth/ICPC2/condition_codes'
            : 'eHealth/ICD10_AM/condition_codes';

        $primaryIndex = $diagnoses->search(fn (array $diagnosis) => ($diagnosis['roleCode'] ?? '') === 'primary');

        $condition = $this->conditions[$primaryIndex] ?? null;
        if (empty($condition)) {
            return;
        }

        if (($condition['codeSystem'] ?? '') !== $expectedSystem) {
            $fail("Основний діагноз повинен бути визначений у системі $expectedSystem");
        }
    }
}
