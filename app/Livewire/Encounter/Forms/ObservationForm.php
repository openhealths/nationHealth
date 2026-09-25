<?php

declare(strict_types=1);

namespace App\Livewire\Encounter\Forms;

use App\Enums\Equipment\AvailabilityStatus;
use App\Enums\Equipment\Status as EquipmentStatus;
use App\Models\Equipment;
use App\Rules\AfterOrEqualDateTime;
use App\Rules\InDictionary;
use App\Rules\PrimarySourceRequiredForAssistant;
use App\Rules\PastDateTime;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
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
        $names = __('observations.attributes');
        // A field nested deeper than one record keeps the name it carries for every index
        $attributes = collect($names)
            ->mapWithKeys(static fn (string $name, string $field): array => ["observations.*.$field" => $name])
            ->all();

        // Each name carries the observation number, so an error points to the card it belongs to
        foreach ($this->observations as $index => $observation) {
            $number = __('observations.position', ['position' => $index + 1]);

            foreach ($names as $field => $name) {
                if (!str_contains($field, '.*.')) {
                    $attributes["observations.$index.$field"] = "$name, $number";

                    continue;
                }

                [$nestedProperty, $nestedField] = explode('.*.', $field, 2);

                foreach (array_keys($observation[$nestedProperty] ?? []) as $nestedIndex) {
                    $attributes["observations.$index.$nestedProperty.$nestedIndex.$nestedField"] = "$name, $number";
                }
            }
        }

        return $attributes;
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
            'observations.*.effectiveType' => ['nullable', 'string', Rule::in(['date_time', 'period'])],
            'observations.*.effectiveDate' => Rule::forEach(fn (mixed $value, string $attribute) => [
                Rule::requiredIf(
                    ($this->observations[(int)explode('.', $attribute)[1]]['effectiveType'] ?? '') === 'date_time'
                ),
                'nullable',
                'date',
                'before_or_equal:now'
            ]),
            'observations.*.effectiveTime' => Rule::forEach(function (mixed $value, string $attribute): array {
                $observation = $this->observations[(int)explode('.', $attribute)[1]];

                return [
                    Rule::requiredIf(($observation['effectiveType'] ?? '') === 'date_time'),
                    'nullable',
                    'date_format:H:i',
                    $this->notAfterEncounterEnd($observation['effectiveDate'] ?? '')
                ];
            }),
            // Both bounds live in one range picker, the way the encounter and care plan filters keep them
            'observations.*.effectivePeriodRange' => Rule::forEach(fn (mixed $value, string $attribute) => [
                Rule::requiredIf(
                    ($this->observations[(int)explode('.', $attribute)[1]]['effectiveType'] ?? '') === 'period'
                ),
                'nullable',
                'string',
                'regex:/^\d{2}\.\d{2}\.\d{4}( — \d{2}\.\d{2}\.\d{4})?$/u'
            ]),
            'observations.*.effectivePeriodStartTime' => Rule::forEach(function (mixed $value, string $attribute) {
                $observation = $this->observations[(int)explode('.', $attribute)[1]];
                $bounds = array_map('trim', explode('—', $observation['effectivePeriodRange'] ?? ''));
                $encounter = $this->component->form->encounter ?? [];

                return [
                    Rule::requiredIf(($observation['effectiveType'] ?? '') === 'period'),
                    'nullable',
                    'date_format:H:i',
                    new PastDateTime($bounds[0] ?? ''),
                    new AfterOrEqualDateTime(
                        $bounds[0] ?? '',
                        $encounter['periodDate'] ?? '',
                        $encounter['periodStart'] ?? '',
                        'encounter_period_start'
                    ),
                    $this->notAfterEncounterEnd($bounds[0] ?? '')
                ];
            }),
            'observations.*.effectivePeriodEndTime' => Rule::forEach(function (mixed $value, string $attribute) {
                $observation = $this->observations[(int)explode('.', $attribute)[1]];
                $bounds = array_map('trim', explode('—', $observation['effectivePeriodRange'] ?? ''));
                // A period within one day leaves the picker with a single date
                $endDate = empty($bounds[1]) ? ($bounds[0] ?? '') : $bounds[1];

                return [
                    Rule::requiredIf(($observation['effectiveType'] ?? '') === 'period'),
                    'nullable',
                    'date_format:H:i',
                    new PastDateTime($endDate),
                    new AfterOrEqualDateTime(
                        $endDate,
                        $bounds[0] ?? '',
                        $observation['effectivePeriodStartTime'] ?? ''
                    ),
                    $this->notAfterEncounterEnd($endDate)
                ];
            }),
            'observations.*.issuedDate' => ['required_with:observations', 'date', 'before_or_equal:today'],
            'observations.*.issuedTime' => Rule::forEach(function (mixed $value, string $attribute): array {
                $issuedDate = $this->observations[(int)explode('.', $attribute)[1]]['issuedDate'] ?? '';
                $encounter = $this->component->form->encounter ?? [];

                return [
                    'required_with:observations',
                    'date_format:H:i',
                    new PastDateTime($issuedDate),
                    new AfterOrEqualDateTime(
                        $issuedDate,
                        $encounter['periodDate'] ?? '',
                        $encounter['periodStart'] ?? '',
                        'encounter_period_start'
                    ),
                    $this->notAfterEncounterEnd($issuedDate)
                ];
            }),
            'observations.*.primarySource' => [
                'required_with:observations',
                'boolean',
                new PrimarySourceRequiredForAssistant()
            ],
            'observations.*.reportOriginCode' => Rule::forEach(function (mixed $value, string $attribute) {
                $index = (int)explode('.', $attribute)[1];
                $primarySource = $this->observations[$index]['primarySource'] ?? true;

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
            'observations.*.deviceId' => [
                'bail',
                'nullable',
                'uuid',
                Rule::exists('equipments', 'uuid')
                    ->where('legal_entity_id', legalEntity()->id)
                    ->where('status', EquipmentStatus::ACTIVE->value)
                    ->where('availability_status', AvailabilityStatus::AVAILABLE->value),
                function (string $attribute, mixed $value, Closure $fail): void {
                    $encounterDivisionId = $this->component->form->encounter['divisionId'] ?? '';

                    if ($encounterDivisionId === '') {
                        return;
                    }

                    // Equipment without a division fits any encounter division
                    $isInOtherDivision = Equipment::whereUuid($value)
                        ->whereHas(
                            'division',
                            static fn (Builder $query): Builder => $query->where('uuid', '!=', $encounterDivisionId)
                        )
                        ->exists();

                    if ($isInOtherDivision) {
                        $fail(__('equipments.validation.not_belongs_to_division'));
                    }
                }
            ],
            'observations.*.specimenId' => [
                'nullable',
                'uuid',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $this->component->specimenForm->validateReference($value, $fail);
                }
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
            'observations.*.valueSampledDataDimensions' => ['nullable', 'numeric']
        ];
    }

    /**
     * Fail when the date and time are later than the end of the encounter.
     *
     * @param  string  $date  Date portion of the validated value, e.g. 23.09.2026
     * @return Closure
     */
    private function notAfterEncounterEnd(string $date): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($date): void {
            $encounter = $this->component->form->encounter ?? [];

            if (empty($date) || empty($value) || empty($encounter['periodDate']) || empty($encounter['periodEnd'])) {
                return;
            }

            $format = config('app.date_format') . ' H:i';
            $dateTime = CarbonImmutable::createFromFormat($format, $date . ' ' . $value);
            $periodEnd = CarbonImmutable::createFromFormat(
                $format,
                $encounter['periodDate'] . ' ' . $encounter['periodEnd']
            );

            if ($dateTime->greaterThan($periodEnd)) {
                $fail(__('validation.before_or_equal', ['date' => __('validation.attributes.encounter_period_end')]));
            }
        };
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
