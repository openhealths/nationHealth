<?php

declare(strict_types=1);

namespace App\Livewire\Encounter\Forms;

use App\Core\BaseForm;
use App\Rules\Cyrillic;
use App\Rules\InDictionary;
use App\Rules\OnlyOnePrimaryDiagnosis;
use App\Rules\PastDateTime;
use Carbon\Carbon;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\RequiredIf;

class EncounterForm extends BaseForm
{
    public array $encounter = [
        'diagnoses' => [],
        'reasons' => [],
        'actions' => [],
        'referralType' => ''
    ];

    public array $episode = ['id' => '', 'typeCode' => '', 'name' => ''];

    public array $conditions;

    public array $immunizations;

    public array $observations;

    public array $diagnosticReports;

    public array $procedures;

    public array $clinicalImpressions;

    protected function rules(): array
    {
        $rules = [
            'encounter.periodDate' => ['nullable', 'date'],
            'encounter.periodStart' => ['nullable'],
            'encounter.periodEnd' => ['nullable'],
            'encounter.classCode' => ['nullable'],
            'encounter.typeCode' => ['nullable'],
        ];

        return $rules;
    }

    /**
     * @return array
     */
    protected function messages(): array
    {
        return [
            'encounter.priorityCode.required_if' => __('validation.custom.encounter.priorityCode.required_if'),
            'encounter.reasons.required_if' => __('validation.custom.encounter.reasons.required_if'),
            'encounter.diagnoses.required_unless' => __('validation.custom.encounter.diagnoses.required_unless'),
            'encounter.divisionId.required_if' => __('validation.custom.encounter.divisionId.required_if'),
            'encounter.divisionId.prohibited' => __('validation.custom.encounter.divisionId.prohibited'),
            'encounter.actions.required_if' => __('validation.custom.encounter.actions.required_if'),
            'encounter.actions.prohibited_unless' => __('validation.custom.encounter.actions.prohibited_unless'),
        ];
    }

    /**
     * Add allowed values for episode type code.
     *
     * @param  array  $rules
     * @return void
     */
    private function addAllowedEpisodeCareManagerEmployeeTypes(array &$rules): void
    {
        $allowedValues = array_intersect(
            config('ehealth.legal_entity_episode_types')[legalEntity()->type->name],
            config('ehealth.employee_episode_types')[Auth::user()->getEncounterWriterEmployee()->employeeType]
        );
        $rules['episode.typeCode'][] = 'in:' . implode(',', $allowedValues);
    }

    /**
     * Add allowed values for encounter classes.
     *
     * @param  array  $rules
     * @return void
     */
    private function addAllowedEncounterClasses(array &$rules): void
    {
        $rules['encounter.classCode'][] = function (string $attribute, mixed $value, Closure $fail): void {
            $episodeTypeCode = $this->episode['typeCode'] ?? null;

            if (empty($episodeTypeCode) && !empty($this->episode['id'])) {
                $episode = collect($this->component->episodes)
                    ->firstWhere('uuid', $this->episode['id']);
                $episodeTypeCode = data_get($episode, 'type.code');
            }

            if (empty($episodeTypeCode)) {
                return;
            }

            $allowed = config("ehealth.episode_type_encounter_classes.$episodeTypeCode", []);
            if (!in_array($value, $allowed, true)) {
                $fail(__('validation.custom.encounter.classCode.episode_type_forbidden', ['value' => $value]));
            }
        };

        $rules['encounter.classCode'][] = static function (string $attribute, mixed $value, Closure $fail): void {
            $allowed = config('ehealth.legal_entity_encounter_classes.' . legalEntity()->type->name, []);
            if (!in_array($value, $allowed, true)) {
                $fail(__('validation.custom.encounter.classCode.legal_entity_forbidden', ['value' => $value]));
            }
        };
    }

    /**
     * Add allowed values for encounter types.
     *
     * @param  array  $rules
     * @return void
     */
    private function addAllowedEncounterTypes(array &$rules): void
    {
        $rules['encounter.typeCode'][] = function (string $attribute, mixed $value, Closure $fail): void {
            $classCode = $this->encounter['classCode'] ?? null;
            if (empty($classCode)) {
                return;
            }
            $allowed = config("ehealth.encounter_class_encounter_types.$classCode", []);
            if (!in_array($value, $allowed, true)) {
                $fail(__('validation.custom.encounter.typeCode.class_forbidden', ['value' => $value]));
            }
        };
    }

    /**
     * Add condition code system validation based on encounter class.
     *
     * @param  array  $rules
     * @return void
     */
    private function addAllowedConditionCodes(array &$rules): void
    {
        $rules['conditions.*.codeSystem'][] = function (string $attribute, mixed $value, Closure $fail): void {
            $classCode = $this->encounter['classCode'] ?? null;
            if (empty($classCode) || $classCode === 'PHC') {
                return;
            }

            if ($value !== 'eHealth/ICD10_AM/condition_codes') {
                $fail(__('validation.custom.conditions.codeSystem.class_forbidden'));
            }
        };

        $rules['conditions'][] = static function (string $attribute, mixed $value, Closure $fail): void {
            if (empty($value)) {
                return;
            }

            $hasDuplicate = collect($value)->groupBy('codeSystem')
                ->contains(fn (Collection $group) => $group->count() > 1);

            if ($hasDuplicate) {
                $fail(__('validation.custom.conditions.max_one_per_dictionary'));
            }
        };
    }

    /**
     * Validate that conditions requiring a psychiatry evidence reference have a valid condition evidence attached.
     *
     * @param  array  $rules
     * @return void
     */
    private function addPsychiatryEvidenceValidation(array &$rules): void
    {
        $rules['conditions.*'][] = static function (string $attribute, mixed $value, Closure $fail): void {
            $codeCode = data_get($value, 'codeCode');
            $psychiatryCodes = config('ehealth.psychiatry_icpc2_diagnoses_evidence_check', []);

            if (!in_array($codeCode, $psychiatryCodes, true)) {
                return;
            }

            $evidenceDetails = collect(data_get($value, 'evidenceDetails', []));
            $conditionEvidence = $evidenceDetails->firstWhere('type', '=', 'condition');

            if (!$conditionEvidence) {
                $fail(__('validation.custom.conditions.psychiatry_evidence_required', ['code' => $codeCode]));

                return;
            }

            $allowedCodes = config('ehealth.icd10am_speciality_conditions_allowed.PSYCHIATRY', []);

            if (!in_array(data_get($conditionEvidence, 'codeCode'), $allowedCodes, true)) {
                $fail(__('validation.custom.conditions.psychiatry_evidence_code_forbidden', ['code' => $codeCode]));
            }
        };
    }

    /**
     * Validate that ASSISTANT and MED_COORDINATOR employees only use their allowed condition codes.
     *
     * @param  array  $rules
     * @return void
     */
    private function addEmployeeTypeConditionsValidation(array &$rules): void
    {
        $employeeType = Auth::user()->getEncounterWriterEmployee()->employeeType;

        $rules['conditions.*'][] = static function (string $attribute, mixed $value, Closure $fail) use (
            $employeeType
        ): void {
            $allowedByCodeSystem = config("ehealth.employee_type_conditions_allowed.$employeeType");

            if ($allowedByCodeSystem === null) {
                return;
            }

            $codeSystem = data_get($value, 'codeSystem');
            $allowedCodes = $allowedByCodeSystem[$codeSystem] ?? [];
            $codeCode = data_get($value, 'codeCode');

            if (!in_array($codeCode, $allowedCodes, true)) {
                $fail(__("validation.custom.conditions.employee_type_code_forbidden"));
            }
        };
    }

    /**
     * Validate that the asserter's officio speciality is allowed to set the given ICD10_AM condition code.
     * Only applies when primarySource is true and codeSystem is eHealth/ICD10_AM/condition_codes.
     *
     * @param  array  $rules
     * @return void
     */
    private function addSpecialityConditionsValidation(array &$rules): void
    {
        $speciality = Auth::user()
            ->getEncounterWriterEmployee()
            ->loadMissing('specialities')
            ->specialities
            ->firstWhere('speciality_officio', true)
            ->speciality;

        $rules['conditions.*'][] = static function (string $attribute, mixed $value, Closure $fail) use (
            $speciality
        ): void {
            if (data_get($value, 'codeSystem') !== 'eHealth/ICD10_AM/condition_codes') {
                return;
            }

            if (!$speciality) {
                return;
            }

            $allowedCodes = config("ehealth.icd10am_speciality_conditions_allowed.$speciality");
            if ($allowedCodes === null) {
                return;
            }

            $codeCode = data_get($value, 'codeCode');
            if (!in_array($codeCode, $allowedCodes, true)) {
                $fail(__('validation.custom.conditions.speciality_condition_code_forbidden', ['code' => $codeCode]));
            }
        };
    }

    /**
     * Required if the immunization is from a primary source or the protocol authority is MoH.
     *
     * @param  string  $attribute  e.g. immunizations.0.vaccinationProtocols.1.doseSequence
     * @return RequiredIf
     */
    private function requiredIfProtocolFieldsMandatory(string $attribute): RequiredIf
    {
        $parts = explode('.', $attribute);
        $immunizationIndex = (int)$parts[1];
        $protocolIndex = (int)$parts[3];

        $immunization = $this->immunizations[$immunizationIndex] ?? [];
        $authorityCode = $immunization['vaccinationProtocols'][$protocolIndex]['authorityCode'] ?? null;
        $primarySource = $immunization['primarySource'] ?? null;

        return Rule::requiredIf($authorityCode === 'MoH' || $primarySource === true);
    }
}
