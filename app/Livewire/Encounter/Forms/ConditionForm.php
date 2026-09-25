<?php

declare(strict_types=1);

namespace App\Livewire\Encounter\Forms;

use App\Enums\Person\ConditionVerificationStatus;
use App\Enums\User\Role;
use App\Rules\AfterOrEqualDateTime;
use App\Rules\InDictionary;
use App\Rules\PrimarySourceRequiredForAssistant;
use App\Rules\PastDateTime;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Form;

class ConditionForm extends Form
{
    public array $conditions = [];

    /**
     * Name the fields of a condition the way the form labels them.
     *
     * @return array
     */
    public function validationAttributes(): array
    {
        $names = __('conditions.attributes');
        // A field nested deeper than one record keeps the name it carries for every index
        $attributes = collect($names)
            ->mapWithKeys(static fn (string $name, string $field): array => ["conditions.*.$field" => $name])
            ->all();

        // Each name carries the diagnosis number, so an error points to the card it belongs to
        foreach ($this->conditions as $index => $condition) {
            $number = __('conditions.position', ['position' => $index + 1]);

            foreach ($names as $field => $name) {
                if (!str_contains($field, '.*.')) {
                    $attributes["conditions.$index.$field"] = "$name, $number";

                    continue;
                }

                [$nestedProperty, $nestedField] = explode('.*.', $field, 2);

                foreach (array_keys($condition[$nestedProperty] ?? []) as $nestedIndex) {
                    $attributes["conditions.$index.$nestedProperty.$nestedIndex.$nestedField"] = "$name, $number";
                }
            }
        }

        return $attributes;
    }

    protected function rules(): array
    {
        return [
            'conditions' => ['nullable', 'array'],
            'conditions.*' => [
                $this->psychiatryEvidenceProvided(),
                $this->asserterEmployeeAllowed(),
                $this->codeAllowedForAsserterEmployeeType(),
                $this->codeAllowedForAsserterSpeciality()
            ],
            // for edit page
            'conditions.*.uuid' => ['required_if_accepted:conditions.*.isRegistered', 'nullable', 'uuid'],
            'conditions.*.isRegistered' => ['nullable', 'boolean'],
            'conditions.*.primarySource' => [
                'exclude_if:conditions.*.isRegistered,true',
                'required_with:conditions',
                'boolean',
                new PrimarySourceRequiredForAssistant()
            ],
            'conditions.*.reportOriginCode' => [
                'exclude_if:conditions.*.isRegistered,true',
                'nullable',
                'string',
                'required_if:conditions.*.primarySource,false'
            ],
            'conditions.*.codeCode' => [
                'required_with:conditions',
                'string',
                new InDictionary(['eHealth/ICPC2/condition_codes', 'eHealth/ICD10_AM/condition_codes'])
            ],
            'conditions.*.codeSystem' => [
                'required_with:conditions',
                'string',
                Rule::in(['eHealth/ICPC2/condition_codes', 'eHealth/ICD10_AM/condition_codes']),
                $this->codeSystemAllowedForEncounterClass()
            ],
            'conditions.*.clinicalStatus' => [
                'exclude_if:conditions.*.isRegistered,true',
                'required_with:conditions',
                'string',
                new InDictionary('eHealth/condition_clinical_statuses')
            ],
            'conditions.*.verificationStatus' => Rule::forEach(function (mixed $value, string $attribute): array {
                $index = (int) explode('.', $attribute)[1];

                $rules = [
                    "exclude_if:conditions.$index.isRegistered,true",
                    'required_with:conditions',
                    'string',
                    new InDictionary('eHealth/condition_verification_statuses')
                ];

                // A condition the encounter lists among its diagnoses has to stay active
                if (isset($this->encounter()['diagnoses'][$index])) {
                    $rules[] = Rule::notIn([ConditionVerificationStatus::ENTERED_IN_ERROR->value]);
                }

                return $rules;
            }),
            'conditions.*.severityCode' => [
                'nullable',
                'string',
                new InDictionary('eHealth/condition_severities')
            ],
            'conditions.*.bodySites.*.code' => ['nullable', 'string', new InDictionary('eHealth/body_sites')],
            'conditions.*.onsetDate' => [
                'exclude_if:conditions.*.isRegistered,true',
                'required_with:conditions',
                'before:tomorrow',
                'date',
                'before_or_equal:' . (($this->encounter()['periodDate'] ?? '') ?: 'today')
            ],
            'conditions.*.onsetTime' => Rule::forEach(
                function (mixed $value, string $attribute): array {
                    $index = (int) explode('.', $attribute)[1];
                    $onsetDate = $this->conditions[$index]['onsetDate'] ?? '';

                    return [
                        "exclude_if:conditions.$index.isRegistered,true",
                        'required_with:conditions',
                        'date_format:H:i',
                        new PastDateTime($onsetDate),
                        $this->notAfterEncounterEnd($onsetDate)
                    ];
                }
            ),
            'conditions.*.assertedDate' => [
                'nullable',
                'before:tomorrow',
                'date',
                'date_equals:' . (($this->encounter()['periodDate'] ?? '') ?: 'today')
            ],
            'conditions.*.assertedTime' => Rule::forEach(
                function (mixed $value, string $attribute): array {
                    $assertedDate = $this->conditions[(int) explode('.', $attribute)[1]]['assertedDate'] ?? '';

                    return [
                        'nullable',
                        'date_format:H:i',
                        new AfterOrEqualDateTime(
                            $assertedDate,
                            $this->encounter()['periodDate'] ?? '',
                            $this->encounter()['periodStart'] ?? '',
                            'encounter_period_start'
                        ),
                        $this->notAfterEncounterEnd($assertedDate)
                    ];
                }
            ),
            'conditions.*.asserterText' => ['nullable', 'string'],
            'conditions.*.stageCode' => [
                'nullable',
                'string',
                new InDictionary('eHealth/condition_stages')
            ],
            'conditions.*.evidenceCodes.*.code' => [
                'nullable',
                'string',
                new InDictionary('eHealth/ICPC2/reasons')
            ],
            'conditions.*.evidenceDetails.*.id' => ['nullable', 'uuid'],
            'conditions.*.evidenceDetails.*.type' => ['nullable', 'string', Rule::in(['observation', 'condition'])]
        ];
    }

    /**
     * The default wording only names the forbidden value, not why the condition has to stay active.
     *
     * @return array
     */
    protected function messages(): array
    {
        return [
            'conditions.*.verificationStatus.not_in' => __('conditions.validation.verification_status_not_in')
        ];
    }

    /**
     * Only ICD10_AM codes are allowed outside primary health care.
     *
     * @return Closure
     */
    private function codeSystemAllowedForEncounterClass(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $classCode = $this->encounter()['classCode'] ?? null;

            if (empty($classCode) || $classCode === 'PHC') {
                return;
            }

            if ($value !== 'eHealth/ICD10_AM/condition_codes') {
                $fail(__('conditions.validation.code_system_class_forbidden'));
            }
        };
    }

    /**
     * Conditions requiring a psychiatry evidence reference have to carry a valid condition evidence.
     *
     * @return Closure
     */
    private function psychiatryEvidenceProvided(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (data_get($value, 'isRegistered') === true) {
                return;
            }

            $codeCode = data_get($value, 'codeCode');
            $psychiatryCodes = config('ehealth.psychiatry_icpc2_diagnoses_evidence_check', []);

            if (!in_array($codeCode, $psychiatryCodes, true)) {
                return;
            }

            $evidenceDetails = collect(data_get($value, 'evidenceDetails', []));
            $conditionEvidence = $evidenceDetails->firstWhere('type', '=', 'condition');

            if (!$conditionEvidence) {
                $fail(__('conditions.validation.psychiatry_evidence_required', ['code' => $codeCode]));

                return;
            }

            $allowedCodes = config('ehealth.icd10am_speciality_conditions_allowed.PSYCHIATRY', []);

            if (!in_array(data_get($conditionEvidence, 'codeCode'), $allowedCodes, true)) {
                $fail(__('conditions.validation.psychiatry_evidence_code_forbidden', ['code' => $codeCode]));
            }
        };
    }

    /**
     * The asserter has to be an employee allowed to assert conditions and taking part in the encounter.
     *
     * @return Closure
     */
    private function asserterEmployeeAllowed(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (data_get($value, 'primarySource') !== true) {
                return;
            }

            $asserter = Auth::user()->getEncounterWriterEmployee($this->encounter()['classCode'] ?? null);

            if ($asserter === null) {
                $fail(__('conditions.validation.asserter_employee_not_found'));

                return;
            }

            $allowedEmployeeTypes = config('ehealth.encounter_package_allowed_condition_asserter_employee_types', []);

            if (!in_array($asserter->employeeType, $allowedEmployeeTypes, true)) {
                $fail(__('conditions.validation.asserter_employee_invalid_type'));

                return;
            }

            $participantUuids = collect($this->encounter()['participant'] ?? [])
                ->pluck('uuid')
                ->filter();

            if (!$participantUuids->contains($asserter->uuid)) {
                $fail(__('conditions.validation.asserter_employee_not_participant'));
            }
        };
    }

    /**
     * ASSISTANT and MED_COORDINATOR employees only use their allowed condition codes.
     *
     * @return Closure
     */
    private function codeAllowedForAsserterEmployeeType(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (data_get($value, 'primarySource') !== true) {
                return;
            }

            $asserter = Auth::user()->getEncounterWriterEmployee($this->encounter()['classCode'] ?? null);

            if ($asserter === null) {
                return;
            }

            $employeeType = $asserter->employeeType;

            if (in_array($employeeType, [Role::DOCTOR->value, Role::SPECIALIST->value], true)) {
                return;
            }

            if (!in_array($employeeType, [Role::ASSISTANT->value, Role::MED_COORDINATOR->value], true)) {
                $fail(__('conditions.validation.employee_type_code_forbidden', [
                    'code' => data_get($value, 'codeCode')
                ]));

                return;
            }

            $allowedByCodeSystem = config("ehealth.employee_type_conditions_allowed.$employeeType", []);
            $codeSystem = data_get($value, 'codeSystem');
            $codeCode = data_get($value, 'codeCode');
            $allowedCodes = $allowedByCodeSystem[$codeSystem] ?? [];

            if (!in_array($codeCode, $allowedCodes, true)) {
                $fail(__('conditions.validation.employee_type_code_forbidden', ['code' => $codeCode]));
            }
        };
    }

    /**
     * The asserter's officio speciality has to allow the given ICD10_AM condition code.
     *
     * @return Closure
     */
    private function codeAllowedForAsserterSpeciality(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (data_get($value, 'primarySource') !== true) {
                return;
            }

            if (data_get($value, 'codeSystem') !== 'eHealth/ICD10_AM/condition_codes') {
                return;
            }

            $asserter = Auth::user()->getEncounterWriterEmployee($this->encounter()['classCode'] ?? null);

            if ($asserter === null) {
                return;
            }

            $codeCode = data_get($value, 'codeCode');

            if (in_array($codeCode, $asserter->forbiddenIcd10ConditionCodes(), true)) {
                $fail(__('conditions.validation.speciality_condition_code_forbidden', ['code' => $codeCode]));
            }
        };
    }

    /**
     * Fail when the given date combined with the validated time is later than the encounter period end.
     *
     * @param  string  $date  Date portion of the validated datetime, e.g. 03.08.2026
     * @return Closure
     */
    private function notAfterEncounterEnd(string $date): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($date): void {
            $encounter = $this->encounter();

            if (
                empty($date)
                || empty($value)
                || empty($encounter['periodDate'])
                || empty($encounter['periodEnd'])
            ) {
                return;
            }

            $format = config('app.date_format') . ' H:i';
            $datetime = CarbonImmutable::createFromFormat($format, $date . ' ' . $value);
            $periodEnd = CarbonImmutable::createFromFormat(
                $format,
                $encounter['periodDate'] . ' ' . $encounter['periodEnd']
            );

            if ($datetime->greaterThan($periodEnd)) {
                $fail(__('validation.before_or_equal', [
                    'date' => __('validation.attributes.encounter_period_end')
                ]));
            }
        };
    }

    /**
     * Encounter the conditions are written in.
     *
     * @return array
     */
    private function encounter(): array
    {
        return $this->component->form->encounter;
    }
}
