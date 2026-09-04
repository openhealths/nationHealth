<?php

declare(strict_types=1);

namespace App\Livewire\Encounter\Forms;

use App\Rules\InDictionary;
use App\Rules\PastDateTime;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Form;

class ObservationForm extends Form
{
    public array $observations = [];

    /**
     * Name the fields of an observation the way the form labels them.
     *
     * @return array
     */
    public function validationAttributes(): array
    {
        return collect(__('observations.attributes'))
            ->mapWithKeys(static fn (string $name, string $field): array => ["observations.*.$field" => $name])
            ->all();
    }

    protected function rules(): array
    {
        return [
            'observations' => ['nullable', 'array'],
            'observations.*' => [
                'array',
                function (string $attribute, mixed $value, Closure $fail): void {
                    // The mapper names the writer employee as the performer, but only of an observation made here
                    if (data_get($value, 'primarySource') === true) {
                        $this->validatePerformer($fail);
                    }
                }
            ],
            // for edit page
            'observations.*.uuid' => ['nullable', 'uuid'],
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
                new InDictionary(
                    ['eHealth/LOINC/observation_codes', 'eHealth/custom/observation_codes', 'eHealth/ICF/classifiers']
                )
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
                $primarySource = $this->observations[$index]['primarySource'];

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
            'observations.*.reactionOn' => ['nullable', 'uuid'],
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
            'observations.*.valueQuantitySystem' => [
                'required_with:observations.*.valueQuantityValue',
                'string'
            ],
            'observations.*.valueQuantityCode' => [
                'required_with:observations.*.valueQuantityValue',
                'string'
            ],
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
            'observations.*.valueRatio.denominator' => ['nullable', 'array']
        ];
    }

    /**
     * The performer has to be an employee allowed to record observations and taking part in the encounter
     * the observation is written in.
     *
     * @param  Closure  $fail
     * @return void
     */
    private function validatePerformer(Closure $fail): void
    {
        $performer = Auth::user()->getEncounterWriterEmployee($this->component->form->encounter['classCode'] ?? null);

        if ($performer === null) {
            $fail(__('observations.validation.performer_employee_not_found'));

            return;
        }

        $allowedEmployeeTypes = config('ehealth.encounter_package_allowed_observation_performer_employee_types', []);

        if (!in_array($performer->employeeType, $allowedEmployeeTypes, true)) {
            $fail(__('observations.validation.performer_employee_invalid_type'));

            return;
        }

        $isParticipant = collect($this->component->form->encounter['participant'] ?? [])->contains(
            static fn (array $participant): bool => ($participant['uuid'] ?? '') === $performer->uuid
        );

        if (!$isParticipant) {
            $fail(__('observations.validation.performer_not_participant'));
        }
    }
}
