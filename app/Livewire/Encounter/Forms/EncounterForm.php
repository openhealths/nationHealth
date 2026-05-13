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
        'referralType' => '',
        'referralNumber' => '',
        'paperReferral' => [
            'requisition' => '',
            'requesterEmployeeName' => '',
            'requesterLegalEntityEdrpou' => '',
            'requesterLegalEntityName' => '',
            'serviceRequestDate' => '',
            'note' => '',
        ]
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

            'episode.id' => [
                'nullable',
                'uuid',
                'required_without_all:episode.typeCode,episode.name',
                Rule::prohibitedIf(!empty($this->episode['typeCode']) || !empty($this->episode['name']))
            ],
            'episode.typeCode' => [
                'nullable',
                'string',
                new InDictionary('eHealth/episode_types'),
                'required_without:episode.id',
                Rule::prohibitedIf(!empty($this->episode['id']))
            ],
            'episode.name' => [
                'nullable',
                'string',
                new Cyrillic(),
                'required_without:episode.id',
                Rule::prohibitedIf(!empty($this->episode['id']))
            ],

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
            'conditions.*.onsetDate' => ['required_with:conditions', 'before:tomorrow', 'date'],
            'conditions.*.onsetTime' => Rule::forEach(fn (mixed $value, string $attribute) => [
                'required_with:conditions',
                'date_format:H:i',
                new PastDateTime($this->conditions[(int)explode('.', $attribute)[1]]['onsetDate'] ?? '')
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
            'immunizations.*.time' => Rule::forEach(fn (mixed $value, string $attribute) => [
                'required_with:immunizations',
                'date_format:H:i',
                new PastDateTime($this->immunizations[(int)explode('.', $attribute)[1]]['date'])
            ]),
            'immunizations.*.reasons' => Rule::forEach(function (mixed $value, string $attribute) {
                $index = (int)explode('.', $attribute)[1];
                $notGiven = $this->immunizations[$index]['notGiven'] ?? null;

                return ['array', Rule::requiredIf($notGiven === false)];
            }),
            'immunizations.*.reasons.*.code' => [
                'required',
                'string',
                new InDictionary('eHealth/reason_explanations')
            ],
            'immunizations.*.reasonNotGivenCode' => Rule::forEach(function (mixed $value, string $attribute) {
                $index = (int)explode('.', $attribute)[1];
                $notGiven = $this->immunizations[$index]['notGiven'] ?? null;

                return [
                    Rule::requiredIf($notGiven === true),
                    $notGiven === false ? 'prohibited' : 'nullable',
                    'string',
                    new InDictionary('eHealth/reason_not_given_explanations')
                ];
            }),
            'immunizations.*.reportOriginCode' => Rule::forEach(function (mixed $value, string $attribute) {
                $index = (int)explode('.', $attribute)[1];
                $primarySource = $this->immunizations[$index]['primarySource'] ?? null;

                return [
                    Rule::requiredIf($primarySource === false),
                    $primarySource === true ? 'prohibited' : 'nullable',
                    'string',
                    new InDictionary('eHealth/immunization_report_origins')
                ];
            }),
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
            'immunizations.*.vaccinationProtocols' => ['required', 'array'],
            'immunizations.*.vaccinationProtocols.*.authorityCode' => [
                'required_with:immunizations.*.vaccinationProtocols',
                'string',
                new InDictionary('eHealth/vaccination_authorities')
            ],
            'immunizations.*.vaccinationProtocols.*.doseSequence' => Rule::forEach(
                fn (mixed $value, string $attribute) => [
                    'nullable',
                    'integer',
                    'min:1',
                    $this->requiredIfProtocolFieldsMandatory($attribute)
                ]
            ),
            'immunizations.*.vaccinationProtocols.*.series' => Rule::forEach(
                fn (mixed $value, string $attribute) => [
                    'nullable',
                    'string',
                    $this->requiredIfProtocolFieldsMandatory($attribute)
                ]
            ),
            'immunizations.*.vaccinationProtocols.*.seriesDoses' => Rule::forEach(
                fn (mixed $value, string $attribute) => [
                    'nullable',
                    'integer',
                    'min:1',
                    $this->requiredIfProtocolFieldsMandatory($attribute)
                ]
            ),
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
            'diagnosticReports.*.conclusion' => Rule::forEach(fn (mixed $value, string $attribute) => [
                Rule::requiredIf(
                    in_array(
                        $this->diagnosticReports[(int)explode('.', $attribute)[1]]['categoryCode'],
                        ['diagnostic_procedure', 'imaging']
                    )
                ),
                'nullable',
                'string',
                'max:3000'
            ]),
            'diagnosticReports.*.divisionId' => ['nullable', 'uuid'],
            'diagnosticReports.*.resultsInterpreterEmployeeId' => ['nullable', 'uuid'],
            'diagnosticReports.*.issuedDate' => ['required_with:diagnosticReports', 'date', 'before_or_equal:today'],
            'diagnosticReports.*.issuedTime' => Rule::forEach(fn (mixed $value, string $attribute) => [
                'required_with:diagnosticReports',
                'date_format:H:i',
                new PastDateTime($this->diagnosticReports[(int)explode('.', $attribute)[1]]['issuedDate'])
            ]),
            'diagnosticReports.*.effectivePeriodStartDate' => [
                'required_with:diagnosticReports',
                'date',
                'before_or_equal:today'
            ],
            'diagnosticReports.*.effectivePeriodStartTime' => Rule::forEach(fn (mixed $value, string $attribute) => [
                'required_with:diagnosticReports',
                'date_format:H:i',
                new PastDateTime($this->diagnosticReports[(int)explode('.', $attribute)[1]]['effectivePeriodStartDate'])
            ]),
            'diagnosticReports.*.effectivePeriodEndDate' => ['required_with:diagnosticReports', 'date'],
            'diagnosticReports.*.effectivePeriodEndTime' => Rule::forEach(function (mixed $value, string $attribute) {
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

            'observations' => ['nullable', 'array'],
            'observations.*.categorySystem' => ['required_with:observations', 'string'],
            'observations.*.categoryCode' => [
                'required_with:observations',
                'string',
                new InDictionary(['eHealth/observation_categories', 'eHealth/ICF/observation_categories'])
            ],
            'observations.*.codeSystem' => ['required_with:observations', 'string'],
            'observations.*.codeCode' => [
                'required_with:observations',
                'string',
                new InDictionary(['eHealth/LOINC/observation_codes', 'eHealth/ICF/classifiers'])
            ],
            'observations.*.effectiveDate' => ['nullable', 'date', 'before_or_equal:now'],
            'observations.*.effectiveTime' => ['nullable', 'date_format:H:i'],
            'observations.*.issuedDate' => ['required_with:observations', 'date', 'before_or_equal:today'],
            'observations.*.issuedTime' => Rule::forEach(fn (mixed $value, string $attribute) => [
                'required_with:observations',
                'date_format:H:i',
                new PastDateTime($this->observations[(int)explode('.', $attribute)[1]]['issuedDate'] ?? '')
            ]),
            'observations.*.primarySource' => ['required_with:observations', 'boolean'],
            'observations.*.reportOriginCode' => Rule::forEach(function (mixed $value, string $attribute) {
                $index = (int)explode('.', $attribute)[1];
                $primarySource = $this->observations[$index]['primarySource'] ?? null;

                return [
                    Rule::requiredIf($primarySource === false),
                    $primarySource === true ? 'prohibited' : 'nullable',
                    'string',
                    new InDictionary('eHealth/report_origins')
                ];
            }),
            'observations.*.interpretationCode' => [
                'nullable',
                'string',
                new InDictionary('eHealth/observation_interpretations')
            ],
            'observations.*.comment' => ['nullable', 'string'],
            'observations.*.bodySiteCode' => [
                'nullable',
                'string',
                new InDictionary('eHealth/body_sites')
            ],
            'observations.*.methodCode' => [
                'nullable',
                'string',
                new InDictionary('eHealth/observation_methods')
            ],
            'observations.*.dictionaryName' => ['nullable', 'string'],
            'observations.*.components' => ['nullable', 'array'],
            'observations.*.components.*.codeCode' => ['nullable', 'string'],
            'observations.*.components.*.codeSystem' => ['nullable', 'string'],
            'observations.*.components.*.valueCode' => ['nullable', 'string'],
            'observations.*.components.*.valueSystem' => ['nullable', 'string'],
            'observations.*.components.*.interpretationCode' => [
                'nullable',
                'string',
                new InDictionary('eHealth/observation_interpretations')
            ],

            'observations.*.valueQuantityValue' => ['nullable', 'numeric'],
            'observations.*.valueQuantityComparator' => ['nullable', 'string', Rule::in(['>', '>=', '=', '<=', '<'])],
            'observations.*.valueQuantityUnit' => ['nullable', 'string', new InDictionary('eHealth/ucum/units')],
            'observations.*.valueQuantitySystem' => ['nullable', 'string'],
            'observations.*.valueQuantityCode' => ['nullable', 'string'],
            'observations.*.valueCodeableConcept' => ['nullable', 'string'],
            'observations.*.valueString' => ['nullable', 'string'],
            'observations.*.valueBoolean' => ['nullable', 'boolean'],
            'observations.*.valueDate' => ['nullable', 'date', 'before_or_equal:now'],
            'observations.*.valueTime' => ['nullable', 'date_format:H:i'],
            'observations.*.valueSampledDataData' => ['nullable', 'string'],
            'observations.*.valueSampledDataOrigin' => ['nullable', 'numeric'],
            'observations.*.valueSampledDataPeriod' => ['nullable', 'numeric'],
            'observations.*.valueSampledDataFactor' => ['nullable', 'numeric'],
            'observations.*.valueSampledDataLowerLimit' => ['nullable', 'numeric'],
            'observations.*.valueSampledDataUpperLimit' => ['nullable', 'numeric'],
            'observations.*.valueSampledDataDimensions' => ['nullable', 'numeric'],
            'observations.*.valueRange' => ['nullable', 'array'],
            'observations.*.valueRange.low' => ['nullable', 'array'],
            'observations.*.valueRange.high' => ['nullable', 'array'],
            'observations.*.valueRatio' => ['nullable', 'array'],
            'observations.*.valueRatio.numerator' => ['nullable', 'array'],
            'observations.*.valueRatio.denominator' => ['nullable', 'array'],

            'procedures' => ['nullable', 'array'],
            'procedures.*.codeValue' => ['required_with:procedures', 'uuid', 'max:255'],
            'procedures.*.categoryCode' => [
                'required_with:procedures',
                'string',
                new InDictionary('eHealth/procedure_categories')
            ],
            'procedures.*.primarySource' => ['required_with:procedures', 'boolean'],
            'procedures.*.reportOriginCode' => Rule::forEach(function (mixed $value, string $attribute) {
                $index = (int)explode('.', $attribute)[1];
                $primarySource = $this->procedures[$index]['primarySource'] ?? null;

                return [
                    Rule::requiredIf($primarySource === false),
                    $primarySource === true ? 'prohibited' : 'nullable',
                    'string',
                    new InDictionary('eHealth/report_origins')
                ];
            }),
            'procedures.*.reportOriginText' => ['nullable', 'string'],
            'procedures.*.divisionId' => ['nullable', 'uuid'],
            'procedures.*.outcomeCode' => ['nullable', 'string', new InDictionary('eHealth/procedure_outcomes')],
            'procedures.*.performedPeriodStartDate' => ['required_with:procedures', 'date', 'before_or_equal:now'],
            'procedures.*.performedPeriodStartTime' => Rule::forEach(fn (mixed $value, string $attribute) => [
                'required_with:procedures',
                'date_format:H:i',
                new PastDateTime($this->procedures[(int)explode('.', $attribute)[1]]['performedPeriodStartDate'] ?? '')
            ]),
            'procedures.*.performedPeriodEndDate' => [
                'required_with:procedures',
                'date',
                'before_or_equal:now',
                'after_or_equal:procedures.*.performedPeriodStartDate'
            ],
            'procedures.*.performedPeriodEndTime' => Rule::forEach(function (mixed $value, string $attribute) {
                $index = (int)explode('.', $attribute)[1];
                $procedure = $this->procedures[$index];

                return [
                    'required_with:procedures',
                    'date_format:H:i',
                    function (string $attribute, mixed $value, Closure $fail) use ($procedure) {
                        $start = Carbon::createFromFormat(
                            'Y-m-d H:i',
                            $procedure['performedPeriodStartDate'] . ' ' . $procedure['performedPeriodStartTime']
                        );
                        $end = Carbon::createFromFormat(
                            'Y-m-d H:i',
                            $procedure['performedPeriodEndDate'] . ' ' . $value
                        );

                        if ($end->lessThan($start)) {
                            $fail(
                                __('validation.after', ['date' => __('validation.attributes.performed_period_start')])
                            );
                        }
                    }
                ];
            }),
            'procedures.*.note' => ['nullable', 'string'],
            'procedures.*.paperReferralRequisition' => ['nullable', 'string', 'max:255'],
            'procedures.*.paperReferralRequesterEmployeeName' => ['nullable', 'string', 'max:255'],
            'procedures.*.paperReferralRequesterLegalEntityEdrpou' => ['nullable', 'digits_between:8,10'],
            'procedures.*.paperReferralRequesterLegalEntityName' => ['nullable', 'string', 'max:255'],
            'procedures.*.paperReferralServiceRequestDate' => ['nullable', 'date'],
            'procedures.*.paperReferralNote' => ['nullable', 'string'],
            'procedures.*.usedCodes' => ['nullable', 'array'],
            'procedures.*.usedCodes.*.code' => [
                'required',
                'string',
                new InDictionary('eHealth/assistive_products')
            ],
            'procedures.*.reasonReferences' => ['nullable', 'array'],
            'procedures.*.reasonReferences.*.id' => ['nullable', 'uuid'],
            'procedures.*.reasonReferences.*.type' => ['nullable', 'string', 'in:observation,condition'],
            'procedures.*.reasonReferences.*.codeCode' => Rule::forEach(
                fn (mixed $value, string $attribute) => $this->reasonReferenceCodeRule($attribute)
            ),
            'procedures.*.complicationDetails' => ['nullable', 'array'],
            'procedures.*.complicationDetails.*.id' => ['nullable', 'uuid'],
            'procedures.*.complicationDetails.*.type' => ['nullable', 'string', 'in:condition'],
            'procedures.*.complicationDetails.*.codeCode' => [
                'nullable',
                'string',
                new InDictionary(['eHealth/ICPC2/condition_codes', 'eHealth/ICD10_AM/condition_codes'])
            ],

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
     * @param  string  $attribute  e.g. procedures.0.reasonReferences.1.codeCode
     * @return array
     */
    private function reasonReferenceCodeRule(string $attribute): array
    {
        $parts = explode('.', $attribute);
        $type = $this->procedures[(int)$parts[1]]['reasonReferences'][(int)$parts[3]]['type'] ?? null;

        $dictionaries = match ($type) {
            'observation' => ['eHealth/LOINC/observation_codes', 'eHealth/ICF/classifiers'],
            'condition' => ['eHealth/ICPC2/condition_codes', 'eHealth/ICD10_AM/condition_codes'],
            default => [
                'eHealth/LOINC/observation_codes',
                'eHealth/ICF/classifiers',
                'eHealth/ICPC2/condition_codes',
                'eHealth/ICD10_AM/condition_codes',
            ],
        };

        return ['nullable', 'string', new InDictionary($dictionaries)];
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
