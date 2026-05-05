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
    public array $encounter = ['diagnoses' => [], 'reasons' => [], 'actions' => []];

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
            'encounter.periodDate' => ['required', 'date', 'before_or_equal:today'],
            'encounter.periodStart' => [
                'required',
                'date_format:H:i',
                new PastDateTime($this->encounter['periodDate'])
            ],
            'encounter.periodEnd' => [
                'required',
                'date_format:H:i',
                'after:encounter.periodStart',
                new PastDateTime($this->encounter['periodDate']),
            ],
            'encounter.classCode' => ['required', 'string', new InDictionary('eHealth/encounter_classes')],
            'encounter.typeCode' => ['required', 'string', new InDictionary('eHealth/encounter_types')],
            'encounter.priorityCode' => [
                'required_if:encounter.classCode,INPATIENT',
                'string',
                new InDictionary('eHealth/encounter_priority')
            ],
            'encounter.reasons' => ['required_if:encounter.classCode,PHC', 'array'],
            'encounter.reasons.*.code' => ['required', 'string', new InDictionary('eHealth/ICPC2/reasons')],
            'encounter.reasons.*.text' => ['nullable', 'string', new Cyrillic()],
            'encounter.diagnoses' => [
                'required_unless:encounter.typeCode,intervention',
                Rule::when(
                    ($this->encounter['typeCode'] ?? '') !== 'intervention',
                    new OnlyOnePrimaryDiagnosis($this->encounter['classCode'] ?? null, $this->conditions ?? [])
                ),
                'array'
            ],
            'encounter.diagnoses.*.roleCode' => [
                'required_with:conditions',
                'string',
                new InDictionary('eHealth/diagnosis_roles')
            ],
            'encounter.diagnoses.*.rank' => ['nullable', 'integer', 'min:1', 'max:10'],
            'encounter.actions' => [
                'required_if:encounter.classCode,PHC',
                'prohibited_unless:encounter.classCode,PHC',
                'array'
            ],
            'encounter.actions.*.code' => ['required', 'string', new InDictionary('eHealth/ICPC2/actions')],
            'encounter.actions.*.text' => ['nullable', 'string', new Cyrillic()],
            'encounter.divisionId' => [
                'required_if:encounter.classCode,INPATIENT',
                'nullable',
                'uuid',
                Rule::prohibitedIf(in_array($this->encounter['typeCode'] ?? '', ['field', 'home']))
            ],

            'episode.id' => ['nullable', 'uuid'],
            'episode.typeCode' => ['nullable', 'string', new InDictionary('eHealth/episode_types')],
            'episode.name' => ['nullable', 'string', new Cyrillic()],

            'conditions' => ['nullable', 'array'],
            // for edit page
            'conditions.*.uuid' => ['nullable', 'uuid'],
            'conditions.*.primarySource' => ['required_with:conditions', 'boolean'],
            'conditions.*.reportOriginCode' => ['nullable', 'string', 'required_if:conditions.*.primarySource,false'],
            'conditions.*.codeCode' => [
                'required_with:conditions',
                'string',
                new InDictionary(['eHealth/ICPC2/condition_codes', 'eHealth/ICD10_AM/condition_codes'])
            ],
            'conditions.*.codeSystem' => [
                'required_with:conditions',
                'string',
                'in:eHealth/ICPC2/condition_codes,eHealth/ICD10_AM/condition_codes'
            ],
            'conditions.*.clinicalStatus' => [
                'required_with:conditions',
                'string',
                new InDictionary('eHealth/condition_clinical_statuses')
            ],
            'conditions.*.verificationStatus' => [
                'required_with:conditions',
                'string',
                new InDictionary('eHealth/condition_verification_statuses')
            ],
            'conditions.*.severityCode' => [
                'nullable',
                'string',
                new InDictionary('eHealth/condition_severities')
            ],
            // absent on frontend
            'conditions.*.bodySites.*.code' => ['nullable', 'string', new InDictionary('eHealth/body_sites')],
            'conditions.*.onsetDate' => ['required_with:conditions', 'before:tomorrow', 'date'],
            'conditions.*.onsetTime' => Rule::forEach(fn ($value, $attribute) => [
                'required_with:conditions',
                'date_format:H:i',
                new PastDateTime($this->conditions[explode('.', $attribute)[1]]['onsetDate'] ?? '')
            ]),
            'conditions.*.assertedDate' => ['nullable', 'before:tomorrow', 'date'],
            'conditions.*.assertedTime' => ['nullable', 'date_format:H:i'],
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
            'conditions.*.evidenceDetails.*.type' => ['nullable', 'string', 'in:observation,condition'],

            'immunizations' => ['nullable', 'array'],
            // for edit page
            'immunizations.*.uuid' => ['nullable', 'uuid'],
            'immunizations.*.primarySource' => ['required_with:immunizations', 'boolean'],
            'immunizations.*.notGiven' => ['required_with:immunizations', 'boolean'],
            'immunizations.*.vaccineCode' => [
                'required_with:immunizations',
                'string',
                new InDictionary('eHealth/vaccine_codes')
            ],
            'immunizations.*.date' => ['required_with:immunizations', 'before:tomorrow', 'date'],
            'immunizations.*.time' => Rule::forEach(fn ($value, $attribute) => [
                'required_with:immunizations',
                'date_format:H:i',
                new PastDateTime($this->immunizations[explode('.', $attribute)[1]]['date'])
            ]),
            'immunizations.*.reasons' => [
                'required_if:immunizations.*.notGiven,false',
                'prohibited_if:immunizations.*.notGiven,true',
                'array'
            ],
            'immunizations.*.reasons.*' => [
                'required',
                'string',
                new InDictionary('eHealth/reason_explanations')
            ],
            'immunizations.*.reasonNotGivenCode' => [
                'required_if:immunizations.*.notGiven,true',
                'prohibited_if:immunizations.*.notGiven,false',
                'string',
                new InDictionary('eHealth/reason_not_given_explanations')
            ],
            'immunizations.*.reportOriginCode' => [
                'required_if:immunizations.*.primarySource,false',
                'prohibited_if:immunizations.*.primarySource,true',
                'string',
                new InDictionary('eHealth/immunization_report_origins')
            ],
            'immunizations.*.reportOriginText' => ['nullable', 'string', 'max:255'],
            'immunizations.*.manufacturer' => ['nullable', 'string', 'max:255'],
            'immunizations.*.lotNumber' => ['nullable', 'string', 'max:255'],
            'immunizations.*.expirationDate' => ['nullable', 'date'],
            'immunizations.*.siteCode' => ['nullable', 'string', new InDictionary('eHealth/immunization_body_sites')],
            'immunizations.*.routeCode' => ['nullable', 'string', new InDictionary('eHealth/vaccination_routes')],
            'immunizations.*.doseQuantityValue' => ['nullable', 'numeric', 'min:0'],
            'immunizations.*.doseQuantityCode' => [
                'nullable',
                'string',
                new InDictionary('eHealth/immunization_dosage_units')
            ],
            'immunizations.*.doseQuantityUnit' => ['nullable', 'string'],
            'immunizations.*.vaccinationProtocols' => Rule::forEach(function ($value, $attribute) {
                $index = (int)explode('.', $attribute)[1];
                $immunization = $this->immunizations[$index];

                return [
                    Rule::when($immunization['primarySource'] && $immunization['notGiven'], 'required'),
                    'nullable',
                    'array',
                ];
            }),
            'immunizations.*.vaccinationProtocols.*.authorityCode' => [
                'required_with:immunizations.*.vaccinationProtocols',
                'string',
                new InDictionary('eHealth/vaccination_authorities')
            ],
            'immunizations.*.vaccinationProtocols.*.doseSequence' => [
                'nullable',
                'integer',
                'min:1',
                $this->requiredIfHasMoHAuthority()
            ],
            'immunizations.*.vaccinationProtocols.*.series' => [
                'nullable',
                'string',
                $this->requiredIfHasMoHAuthority()
            ],
            'immunizations.*.vaccinationProtocols.*.seriesDoses' => [
                'nullable',
                'integer',
                'min:1',
                $this->requiredIfHasMoHAuthority()
            ],
            'immunizations.*.vaccinationProtocols.*.description' => ['nullable', 'string'],
            'immunizations.*.vaccinationProtocols.*.targetDiseaseCodes' => [
                'required_with:immunizations.*.vaccinationProtocols',
                'array'
            ],
            'immunizations.*.vaccinationProtocols.*.targetDiseaseCodes.*' => [
                'required',
                'string',
                new InDictionary('eHealth/vaccination_target_diseases')
            ],

            'diagnosticReports' => ['nullable', 'array'],
            // for edit page
            'diagnosticReports.*.uuid' => ['nullable', 'uuid'],
            'diagnosticReports.*.categoryCode' => [
                'required_with:diagnosticReports',
                'string',
                new InDictionary('eHealth/diagnostic_report_categories')
            ],
            'diagnosticReports.*.codeValue' => ['required_with:diagnosticReports', 'uuid'],
            'diagnosticReports.*.primarySource' => ['required_with:diagnosticReports', 'boolean'],
            'diagnosticReports.*.reportOriginCode' => [
                'required_if:diagnosticReports.*.primarySource,false',
                'prohibited_if:diagnosticReports.*.primarySource,true',
                'string',
                new InDictionary('eHealth/report_origins')
            ],
            'diagnosticReports.*.reportOriginText' => ['nullable', 'string'],
            'diagnosticReports.*.paperReferralRequisition' => ['nullable', 'string', 'max:255'],
            'diagnosticReports.*.paperReferralRequesterEmployeeName' => ['nullable', 'string', 'max:255'],
            'diagnosticReports.*.paperReferralRequesterLegalEntityEdrpou' => ['nullable', 'digits_between:8,10'],
            'diagnosticReports.*.paperReferralRequesterLegalEntityName' => ['nullable', 'string', 'max:255'],
            'diagnosticReports.*.paperReferralServiceRequestDate' => ['nullable', 'date'],
            'diagnosticReports.*.paperReferralNote' => ['nullable', 'string'],
            'diagnosticReports.*.conclusionCode' => [
                'nullable',
                'string',
                new InDictionary('eHealth/ICD10_AM/condition_codes')
            ],
            'diagnosticReports.*.conclusion' => Rule::forEach(fn ($value, $attribute) => [
                Rule::requiredIf(
                    in_array(
                        $this->diagnosticReports[explode('.', $attribute)[1]]['categoryCode'],
                        ['diagnostic_procedure', 'imaging']
                    )
                ),
                'nullable',
                'string',
                'max:1000'
            ]),
            'diagnosticReports.*.divisionId' => ['nullable', 'uuid'],
            'diagnosticReports.*.resultsInterpreterEmployeeId' => ['nullable', 'uuid'],
            'diagnosticReports.*.issuedDate' => ['required_with:diagnosticReports', 'date', 'before_or_equal:today'],
            'diagnosticReports.*.issuedTime' => Rule::forEach(fn ($value, $attribute) => [
                'required_with:diagnosticReports',
                'date_format:H:i',
                new PastDateTime($this->diagnosticReports[explode('.', $attribute)[1]]['issuedDate'])
            ]),
            'diagnosticReports.*.effectivePeriodStartDate' => [
                'required_with:diagnosticReports',
                'date',
                'before_or_equal:today'
            ],
            'diagnosticReports.*.effectivePeriodStartTime' => Rule::forEach(fn ($value, $attribute) => [
                'required_with:diagnosticReports',
                'date_format:H:i',
                new PastDateTime($this->diagnosticReports[explode('.', $attribute)[1]]['effectivePeriodStartDate'])
            ]),
            'diagnosticReports.*.effectivePeriodEndDate' => ['required_with:diagnosticReports', 'date'],
            'diagnosticReports.*.effectivePeriodEndTime' => Rule::forEach(function ($value, $attribute) {
                $index = (int)explode('.', $attribute)[1];
                $report = $this->diagnosticReports[$index];

                return [
                    'required_with:diagnosticReports',
                    'date_format:H:i',
                    function (string $attribute, mixed $value, Closure $fail) use ($report) {
                        $start = Carbon::createFromFormat(
                            'Y-m-d H:i',
                            $report['effectivePeriodStartDate'] . ' ' . $report['effectivePeriodStartTime']
                        );
                        $end = Carbon::createFromFormat('Y-m-d H:i', $report['effectivePeriodEndDate'] . ' ' . $value);
                        $issued = Carbon::createFromFormat(
                            'Y-m-d H:i',
                            $report['issuedDate'] . ' ' . $report['issuedTime']
                        );

                        if (!$end->isAfter($start)) {
                            $fail(
                                __('validation.after', ['date' => __('validation.attributes.effective_period_start')])
                            );
                        }

                        if ($end->isAfter($issued)) {
                            $fail(__('validation.before_or_equal', ['date' => __('validation.attributes.issued')]));
                        }
                    }
                ];
            }),

            //            'observations' => ['nullable', 'array'],
            //            'observations.*.primarySource' => ['required_with:observations', 'boolean'],
            //            'observations.*.performer' => [
            //                'required_if:observations.*.primarySource,true',
            //                'prohibited_if:observations.*.primarySource,false',
            //                'array'
            //            ],
            //            'observations.*.reportOrigin' => [
            //                'required_if:observations.*.primarySource,false',
            //                'array'
            //            ],
            //            'observations.*.reportOrigin.coding.*.code' => [
            //                'required_if:observations.*.primarySource,false',
            //                'prohibited_if:observations.*.primarySource,true',
            //                'string'
            //            ],
            //            'observations.*.categories' => ['required_with:observations', 'array'],
            //            'observations.*.categories.coding.*.code' => [
            //                'required',
            //                'string',
            //                new InDictionary(['eHealth/observation_categories', 'eHealth/ICF/observation_categories'])
            //            ],
            //            'observations.*.code' => ['required_with:observations', 'array'],
            //            'observations.*.code.coding.*.code' => [
            //                'required',
            //                'string',
            //                new InDictionary(['eHealth/LOINC/observation_codes', 'eHealth/ICF/classifiers'])
            //            ],
            //            'observations.*.issuedDate' => ['required_with:observations', 'date', 'before_or_equal:now'],
            //            'observations.*.issuedTime' => ['required_with:observations', 'date_format:H:i'],
            //            'observations.*.effectiveDate' => ['nullable', 'date', 'before_or_equal:now'],
            //            'observations.*.effectiveTime' => ['nullable', 'date_format:H:i'],

            //            'procedures' => ['nullable', 'array'],
            //            'procedures.*.code.identifier.value' => ['required_with:procedures', 'uuid', 'max:255'],
            //            'procedures.*.category.coding.*.code' => [
            //                'required_with:procedures',
            //                'string',
            //                new InDictionary('eHealth/procedure_categories')
            //            ],
            //            'procedures.*.performedPeriod.start' => ['required_with:procedures', 'date', 'before_or_equal:now'],
            //            'procedures.*.performedPeriod.end' => [
            //                'required_with:procedures',
            //                'date',
            //                'before_or_equal:now',
            //                'after:procedures.*.performedPeriod.start'
            //            ],
            //
            //            'clinicalImpressions' => ['nullable', 'array'],
            //            'clinicalImpressions.*.code.coding.*.code' => [
            //                'required_with:clinicalImpressions',
            //                'string',
            //                'max:255',
            //                new InDictionary('eHealth/clinical_impression_patient_categories')
            //            ],
            //            'clinicalImpressions.*.description' => ['nullable', 'string', 'max:1000'],
            //            'clinicalImpressions.*.effectivePeriod.start' => [
            //                'required_with:clinicalImpressions',
            //                'date',
            //                'before_or_equal:now'
            //            ],
            //            'clinicalImpressions.*.effectivePeriod.end' => [
            //                'required_with:clinicalImpressions',
            //                'date',
            //                'before_or_equal:now',
            //                'after:clinicalImpressions.*.effectivePeriod.start'
            //            ]
        ];

        $this->addAllowedEncounterClasses($rules);
        $this->addAllowedEncounterTypes($rules);
        $this->addAllowedEpisodeCareManagerEmployeeTypes($rules);
        $this->addAllowedConditionCodes($rules);
        $this->addPsychiatryEvidenceValidation($rules);
        $this->addEmployeeTypeConditionsValidation($rules);
        $this->addSpecialityConditionsValidation($rules);

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
     * Required if vaccinationProtocols.authority.coding.*.code === MoH
     *
     * @return RequiredIf
     */
    private function requiredIfHasMoHAuthority(): RequiredIf
    {
        return Rule::requiredIf(function () {
            return collect($this->immunizations)
                ->flatMap(static fn (array $immunization) => $immunization['vaccinationProtocols'])
                ->contains(static fn (array $protocol) => $protocol['authorityCode'] === 'MoH');
        });
    }
}
