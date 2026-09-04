<?php

declare(strict_types=1);

namespace App\Livewire\Encounter\Forms;

use App\Rules\InDictionary;
use App\Rules\PastDateTime;
use App\Services\Dictionary\Mappers\ImmunizationDictionaryMapper;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\RequiredIf;
use Livewire\Form;

class ImmunizationForm extends Form
{
    public array $immunizations = [];

    /**
     * Name the fields of an immunization the way the form labels them.
     *
     * @return array
     */
    public function validationAttributes(): array
    {
        return collect(__('immunizations.attributes'))
            ->mapWithKeys(static fn (string $name, string $field): array => ["immunizations.*.$field" => $name])
            ->all();
    }

    protected function rules(): array
    {
        return [
            'immunizations' => ['nullable', 'array'],
            // for edit page
            'immunizations.*.uuid' => ['nullable', 'uuid'],
            'immunizations.*.primarySource' => ['required_with:immunizations', 'boolean'],
            'immunizations.*.notGiven' => [
                'required_with:immunizations',
                'boolean',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $primarySource = $this->immunizations[(int) explode('.', $attribute)[1]]['primarySource'] ?? null;

                    if ($value === true && $primarySource === false) {
                        $fail(__('immunizations.validation.not_given_by_patient'));
                    }
                }
            ],
            'immunizations.*.vaccineCode' => [
                'required_with:immunizations',
                'string',
                new InDictionary('eHealth/vaccine_codes')
            ],
            'immunizations.*.date' => ['required_with:immunizations', 'before:tomorrow', 'date'],
            'immunizations.*.time' => Rule::forEach(fn (mixed $value, string $attribute) => [
                'required_with:immunizations',
                'date_format:H:i',
                new PastDateTime($this->immunizations[(int) explode('.', $attribute)[1]]['date'])
            ]),
            'immunizations.*.reasons' => Rule::forEach(function (mixed $value, string $attribute) {
                $index = (int) explode('.', $attribute)[1];
                $notGiven = $this->immunizations[$index]['notGiven'] ?? null;

                return ['array', Rule::requiredIf($notGiven === false), Rule::prohibitedIf($notGiven === true)];
            }),
            'immunizations.*.reasons.*.code' => [
                'required',
                'string',
                new InDictionary('eHealth/reason_explanations')
            ],
            'immunizations.*.reasonNotGivenCode' => Rule::forEach(function (mixed $value, string $attribute) {
                $index = (int) explode('.', $attribute)[1];
                $notGiven = $this->immunizations[$index]['notGiven'] ?? null;

                return [
                    Rule::requiredIf($notGiven === true),
                    $notGiven === false ? 'prohibited' : 'nullable',
                    'string',
                    new InDictionary('eHealth/reason_not_given_explanations')
                ];
            }),
            'immunizations.*.reportOriginCode' => Rule::forEach(function (mixed $value, string $attribute) {
                $index = (int) explode('.', $attribute)[1];
                $primarySource = $this->immunizations[$index]['primarySource'] ?? null;

                return [
                    Rule::requiredIf($primarySource === false),
                    $primarySource === true ? 'prohibited' : 'nullable',
                    'string',
                    new InDictionary('eHealth/immunization_report_origins')
                ];
            }),
            'immunizations.*.reportOriginText' => ['nullable', 'string', 'max:255'],
            'immunizations.*.manufacturer' => Rule::forEach(fn (mixed $value, string $attribute) => [
                $this->requiredIfGivenByPerformer($attribute),
                'nullable',
                'string',
                'max:255'
            ]),
            'immunizations.*.lotNumber' => Rule::forEach(fn (mixed $value, string $attribute) => [
                $this->requiredIfGivenByPerformer($attribute),
                'nullable',
                'string',
                'max:255'
            ]),
            'immunizations.*.expirationDate' => Rule::forEach(fn (mixed $value, string $attribute) => [
                $this->requiredIfGivenByPerformer($attribute),
                'nullable',
                'date'
            ]),
            'immunizations.*.siteCode' => Rule::forEach(fn (mixed $value, string $attribute) => [
                $this->requiredIfGivenByPerformer($attribute),
                'nullable',
                'string',
                new InDictionary('eHealth/immunization_body_sites')
            ]),
            'immunizations.*.routeCode' => Rule::forEach(fn (mixed $value, string $attribute) => [
                $this->requiredIfGivenByPerformer($attribute),
                'nullable',
                'string',
                new InDictionary('eHealth/vaccination_routes')
            ]),
            'immunizations.*.doseQuantityValue' => Rule::forEach(function (mixed $value, string $attribute) {
                $notGiven = $this->immunizations[(int) explode('.', $attribute)[1]]['notGiven'] ?? null;

                return [Rule::requiredIf($notGiven === false), 'nullable', 'numeric', 'min:0'];
            }),
            'immunizations.*.doseQuantityCode' => Rule::forEach(fn (mixed $value, string $attribute) => [
                $this->requiredIfGivenByPerformer($attribute),
                'nullable',
                'string',
                new InDictionary('eHealth/immunization_dosage_units')
            ]),
            'immunizations.*.doseQuantityUnit' => Rule::forEach(
                function (mixed $value, string $attribute): array {
                    $notGiven = $this->immunizations[(int) explode('.', $attribute)[1]]['notGiven'] ?? null;

                    return [
                        Rule::requiredIf($notGiven === false),
                        'nullable',
                        'string'
                    ];
                }
            ),
            'immunizations.*.vaccinationProtocols' => [
                'required',
                'array',

                function (string $attribute, mixed $value, Closure $fail): void {
                    if (!is_array($value)) {
                        return;
                    }

                    $usedTargetDiseaseCodes = [];

                    foreach ($value as $vaccinationProtocol) {
                        $targetDiseaseCodes = data_get($vaccinationProtocol, 'targetDiseaseCodes', []);

                        if (!is_array($targetDiseaseCodes)) {
                            continue;
                        }

                        foreach ($targetDiseaseCodes as $targetDiseaseCode) {
                            if (!is_string($targetDiseaseCode) || $targetDiseaseCode === '') {
                                continue;
                            }

                            if (isset($usedTargetDiseaseCodes[$targetDiseaseCode])) {
                                $fail(__('immunizations.duplicate_target_disease_in_protocol'));

                                return;
                            }

                            $usedTargetDiseaseCodes[$targetDiseaseCode] = true;
                        }
                    }
                }
            ],
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
                'bail',
                'required',
                'string',
                new InDictionary('eHealth/vaccination_target_diseases'),

                function (string $attribute, mixed $value, Closure $fail): void {
                    $attributeParts = explode('.', $attribute);
                    $immunizationIndex = (int) ($attributeParts[1] ?? 0);
                    $vaccineCode = data_get($this->immunizations, "{$immunizationIndex}.vaccineCode", '');

                    if (!is_string($vaccineCode) || $vaccineCode === '' || !is_string($value) || $value === '') {
                        return;
                    }

                    $isAllowed = app(ImmunizationDictionaryMapper::class)
                        ->isTargetDiseaseAllowed($vaccineCode, $value);

                    if (!$isAllowed) {
                        $fail(__('immunizations.vaccine_target_disease_mismatch'));
                    }
                }
            ]
        ];
    }

    /**
     * Required if the immunization was performed by the author and the vaccine was given.
     *
     * @param  string  $attribute  e.g. immunizations.0.manufacturer
     * @return RequiredIf
     */
    private function requiredIfGivenByPerformer(string $attribute): RequiredIf
    {
        $immunization = $this->immunizations[(int) explode('.', $attribute)[1]] ?? [];

        return Rule::requiredIf(
            ($immunization['primarySource'] ?? null) === true && ($immunization['notGiven'] ?? null) === false
        );
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
        $immunizationIndex = (int) $parts[1];
        $protocolIndex = (int) $parts[3];

        $immunization = $this->immunizations[$immunizationIndex] ?? [];
        $authorityCode = $immunization['vaccinationProtocols'][$protocolIndex]['authorityCode'] ?? null;
        $primarySource = $immunization['primarySource'] ?? null;

        return Rule::requiredIf($authorityCode === 'MoH' || $primarySource === true);
    }
}
