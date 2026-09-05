<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * eHealth's "Submit Encounter Package" validates action_references presence "according to
 * encounter class validations": for PHC, the `encounter.actions` (ICPC-2) block already fulfils
 * this requirement, so action_references/diagnostic reports/procedures stay optional. For AMB and
 * INPATIENT (the only classes available to OUTPATIENT legal entities), `encounter.actions` is
 * prohibited instead, so at least one of action references, diagnostic reports or procedures
 * becomes mandatory - otherwise eHealth rejects the encounter package asynchronously (after
 * signing) with a 422 on `$.encounter.action_references`.
 *
 * @see https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/18167398401/AH+RC+CSI-1758+Submit+Encounter+Package
 * @see storage/logs/e_health_errors.log "$.encounter.action_references" validation_failed
 */
readonly class AtLeastOneEncounterAction implements ValidationRule
{
    public function __construct(
        private ?string $classCode = null,
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
        if (!$this->isRequiredForClass()) {
            return;
        }

        $hasActionReferences = !empty($value);
        $hasDiagnosticReports = !empty($this->diagnosticReports);
        $hasProcedures = !empty($this->procedures);

        if (!$hasActionReferences && !$hasDiagnosticReports && !$hasProcedures) {
            $fail(__('validation.custom.encounter.actionReferences.action_required'));
        }
    }

    /**
     * Whether the current encounter class requires at least one action (per eHealth's
     * "encounter class validations"). PHC substitutes ICPC-2 `encounter.actions` instead.
     *
     * @return bool
     */
    private function isRequiredForClass(): bool
    {
        return match ($this->classCode) {
            'PHC' => false,
            'AMB', 'INPATIENT' => true,
            default => true,
        };
    }
}
