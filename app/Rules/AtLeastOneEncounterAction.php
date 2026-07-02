<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * eHealth rejects the encounter package (asynchronously, after signing) unless it contains
 * at least one of: action references, diagnostic reports or procedures. This mirrors that
 * rule client-side so the user gets an immediate error instead of a silently failed async job.
 *
 * @see storage/logs/e_health_errors.log "$.encounter.action_references" validation_failed
 */
readonly class AtLeastOneEncounterAction implements ValidationRule
{
    public function __construct(
        private array $diagnosticReports = [],
        private array $procedures = []
    ) {
    }

    /**
     * Run the validation rule.
     *
     * @param  string  $attribute
     * @param  mixed  $value  The encounter.actionReferences array being validated.
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     * @return void
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $hasActionReferences = !empty($value);
        $hasDiagnosticReports = !empty($this->diagnosticReports);
        $hasProcedures = !empty($this->procedures);

        if (!$hasActionReferences && !$hasDiagnosticReports && !$hasProcedures) {
            $fail(__('validation.custom.encounter.actionReferences.action_required'));
        }
    }
}
