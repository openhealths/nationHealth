<?php

declare(strict_types=1);

namespace App\Livewire\EncounterPackage\Forms;

use App\Enums\Specimen\Status;
use App\Rules\AfterOrEqualDateTime;
use App\Rules\InDictionary;
use App\Rules\PastDateTime;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Validation\Rule;
use Livewire\Form;

class SpecimenForm extends Form
{
    public array $specimens = [];

    /**
     * Get the validation rules for specimens.
     *
     * @return array
     */
    protected function rules(): array
    {
        return [
            'specimens' => ['nullable', 'array'],
            'specimens.*.uuid' => ['required_with:specimens', 'uuid', 'distinct'],
            'specimens.*.receivedDate' => Rule::forEach(fn (mixed $value, string $attribute): array => [
                $this->isReferenced($this->specimens[(int) explode('.', $attribute)[1]]['uuid'] ?? '') ? 'nullable' : 'prohibited',
                'date'
            ]),
            'specimens.*.receivedTime' => Rule::forEach(fn (mixed $value, string $attribute): array => [
                $this->isReferenced($this->specimens[(int) explode('.', $attribute)[1]]['uuid'] ?? '') ? 'nullable' : 'prohibited',
                'date_format:H:i'
            ]),
            'specimens.*.typeCode' => ['required_with:specimens', 'string', new InDictionary('specimen_types')],
            'specimens.*.conditionCode' => ['nullable', 'string', new InDictionary('specimen_conditions')],
            'specimens.*.note' => ['nullable', 'string'],
            'specimens.*.parentIds' => ['nullable', 'array'],
            'specimens.*.parentIds.*' => [
                'required',
                'uuid',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value === ($this->specimens[(int) explode('.', $attribute)[1]]['uuid'] ?? '')) {
                        $fail(__('specimens.validation.parent.not_found', $this->positionReplacements($attribute)));

                        return;
                    }

                    $this->validateReference(
                        $value,
                        $fail,
                        'specimens.validation.parent',
                        $this->positionReplacements($attribute)
                    );
                }
            ],
            'specimens.*.collectorType' => ['required_with:specimens', Rule::in(['current', 'other', 'patient'])],
            'specimens.*.collectorId' => Rule::forEach(function (mixed $value, string $attribute): array {
                $collectorType = $this->specimens[(int) explode('.', $attribute)[1]]['collectorType'] ?? '';

                return [
                    'required',
                    'uuid',
                    function (string $attribute, mixed $value, Closure $fail) use ($collectorType): void {
                        if ($collectorType === 'patient') {
                            if ($value !== $this->component->patientUuid) {
                                $fail(__(
                                    'specimens.validation.collector_not_current_patient',
                                    $this->positionReplacements($attribute)
                                ));
                            }

                            return;
                        }

                        if (!collect($this->component->employees)->contains('uuid', $value)) {
                            $fail(__(
                                'specimens.validation.collector_not_found',
                                $this->positionReplacements($attribute)
                            ));
                        }
                    }
                ];
            }),
            'specimens.*.collectedType' => ['required_with:specimens', Rule::in(['date_time', 'period'])],
            'specimens.*.collectedDate' => Rule::forEach(fn (mixed $value, string $attribute): array => [
                Rule::requiredIf(($this->specimens[(int) explode('.', $attribute)[1]]['collectedType'] ?? '') === 'date_time'),
                'nullable',
                'date',
                'after:' . CarbonImmutable::today()->subDays(config('ehealth.specimen_max_days_passed'))->format(config('app.date_format')),
                'before_or_equal:today'
            ]),
            'specimens.*.collectedTime' => Rule::forEach(fn (mixed $value, string $attribute): array => [
                Rule::requiredIf(($this->specimens[(int) explode('.', $attribute)[1]]['collectedType'] ?? '') === 'date_time'),
                'nullable',
                'date_format:H:i',
                new PastDateTime($this->specimens[(int) explode('.', $attribute)[1]]['collectedDate'] ?? '')
            ]),
            'specimens.*.collectedPeriodRange' => Rule::forEach(fn (mixed $value, string $attribute): array => [
                Rule::requiredIf(($this->specimens[(int) explode('.', $attribute)[1]]['collectedType'] ?? '') === 'period'),
                'nullable',
                'string',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (!$value) {
                        return;
                    }

                    $periodStart = CarbonImmutable::createFromFormat(
                        config('app.date_format'),
                        trim(explode('—', $value)[0])
                    )->startOfDay();
                    $minDate = CarbonImmutable::today()->subDays(config('ehealth.specimen_max_days_passed'));

                    if ($periodStart->lessThanOrEqualTo($minDate)) {
                        $fail(__('validation.after', ['date' => $minDate->format(config('app.date_format'))]));
                    }
                },
                $this->withinEncounterDate()
            ]),
            'specimens.*.collectedPeriodStartTime' => Rule::forEach(function (mixed $value, string $attribute): array {
                $specimen = $this->specimens[(int) explode('.', $attribute)[1]];
                // The range picker keeps both bounds in one field
                $periodBounds = array_map('trim', explode('—', $specimen['collectedPeriodRange'] ?? ''));
                $encounter = $this->component->form->encounter ?? [];

                return [
                    Rule::requiredIf(($specimen['collectedType'] ?? '') === 'period'),
                    'nullable',
                    'date_format:H:i',
                    new PastDateTime($periodBounds[0]),
                    new AfterOrEqualDateTime(
                        $periodBounds[0],
                        $encounter['periodDate'] ?? '',
                        $encounter['periodStart'] ?? '',
                        'encounter_period_start'
                    ),
                    $this->notAfterEncounterEnd($periodBounds[0])
                ];
            }),
            'specimens.*.collectedPeriodEndTime' => Rule::forEach(function (mixed $value, string $attribute): array {
                $specimen = $this->specimens[(int) explode('.', $attribute)[1]];
                $periodBounds = array_map('trim', explode('—', $specimen['collectedPeriodRange'] ?? ''));

                return [
                    'nullable',
                    'date_format:H:i',
                    new PastDateTime($periodBounds[1] ?? ''),
                    new AfterOrEqualDateTime(
                        $periodBounds[1] ?? '',
                        $periodBounds[0],
                        $specimen['collectedPeriodStartTime'] ?? '',
                        'collected_period_start'
                    ),
                    $this->notAfterEncounterEnd($periodBounds[1] ?? '')
                ];
            }),
            'specimens.*.durationValue' => ['nullable', 'numeric', 'gt:0'],
            'specimens.*.durationCode' => [
                'required_with:specimens.*.durationValue',
                'nullable',
                'string',
                new InDictionary('eHealth/ucum/units'),
                Rule::in(config('ehealth.specimen_duration_allowed_codes'))
            ],
            'specimens.*.quantityValue' => [
                'nullable',
                'numeric',
                'gt:0',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $containersQuantity = collect($this->specimens[(int) explode('.', $attribute)[1]]['containers'] ?? [])
                        ->sum(static fn (array $container): float => (float) ($container['specimenQuantityValue'] ?? 0));

                    if ($containersQuantity > (float) $value) {
                        $fail(__(
                            'specimens.validation.containers_quantity_exceeds_collected',
                            $this->positionReplacements($attribute)
                        ));
                    }
                }
            ],
            'specimens.*.quantityCode' => [
                'required_with:specimens.*.quantityValue',
                'nullable',
                'string',
                new InDictionary('eHealth/ucum/units')
            ],
            'specimens.*.methodCode' => ['nullable', 'string', new InDictionary('specimen_collection_methods')],
            'specimens.*.bodySiteCode' => ['nullable', 'string', new InDictionary('eHealth/body_sites')],
            'specimens.*.fastingStatusCode' => ['nullable', 'string', new InDictionary('fasting_statuses')],
            'specimens.*.procedureId' => [
                'nullable',
                'uuid',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (!collect($this->component->procedureForm->procedures)->contains('uuid', $value)) {
                        $fail(__('specimens.validation.procedure_not_found', $this->positionReplacements($attribute)));
                    }
                }
            ],
            'specimens.*.containers' => ['required_with:specimens', 'array', 'min:1'],
            'specimens.*.containers.*.identifier' => [
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $sameIdentifierCount = collect($this->specimens[(int) explode('.', $attribute)[1]]['containers'] ?? [])
                        ->where('identifier', $value)
                        ->count();

                    if ($sameIdentifierCount > 1) {
                        $fail(__(
                            'specimens.validation.container_identifier_not_unique',
                            $this->positionReplacements($attribute)
                        ));
                    }
                }
            ],
            'specimens.*.containers.*.description' => ['nullable', 'string'],
            'specimens.*.containers.*.typeCode' => ['nullable', 'string', new InDictionary('specimen_container_types')],
            'specimens.*.containers.*.capacityValue' => ['nullable', 'numeric', 'gt:0'],
            'specimens.*.containers.*.capacityCode' => [
                'required_with:specimens.*.containers.*.capacityValue',
                'nullable',
                'string',
                new InDictionary('eHealth/ucum/units')
            ],
            'specimens.*.containers.*.specimenQuantityValue' => ['nullable', 'numeric', 'gt:0'],
            'specimens.*.containers.*.specimenQuantityCode' => [
                'required_with:specimens.*.containers.*.specimenQuantityValue',
                'nullable',
                'string',
                new InDictionary('eHealth/ucum/units'),
                function (string $attribute, mixed $value, Closure $fail): void {
                    [, $specimenIndex, , $containerIndex] = explode('.', $attribute);
                    $specimen = $this->specimens[(int) $specimenIndex];
                    $containerQuantity = $specimen['containers'][(int) $containerIndex]['specimenQuantityValue'] ?? '';

                    // Units are compared only when both the collected and the container quantity are filled in
                    if (empty($specimen['quantityValue']) || empty($containerQuantity)) {
                        return;
                    }

                    if ($value !== ($specimen['quantityCode'] ?? '')) {
                        $fail(__(
                            'specimens.validation.container_quantity_code_mismatch',
                            $this->positionReplacements($attribute)
                        ));
                    }
                }
            ],
            'specimens.*.containers.*.additiveCode' => ['nullable', 'string', new InDictionary('specimen_container_additives')]
        ];
    }

    /**
     * Name specimen fields the way the form labels them.
     *
     * @return array
     */
    public function validationAttributes(): array
    {
        $names = __('specimens.attributes');
        $containerPrefix = 'containers.*.';
        // A field nested deeper than one record keeps the name it carries for every index
        $attributes = collect($names)
            ->mapWithKeys(static fn (string $name, string $field): array => ["specimens.*.$field" => $name])
            ->all();

        // Each name carries the specimen number, so an error points to the card it belongs to
        foreach ($this->specimens as $index => $specimen) {
            $number = __('specimens.position', ['position' => $index + 1]);

            foreach ($names as $field => $name) {
                if ($field === 'parentIds.*') {
                    foreach (array_keys($specimen['parentIds'] ?? []) as $parentIndex) {
                        $attributes["specimens.$index.parentIds.$parentIndex"] = "$name, $number";
                    }

                    continue;
                }

                if (!str_starts_with($field, $containerPrefix)) {
                    $attributes["specimens.$index.$field"] = "$name, $number";

                    continue;
                }

                $containerField = str_replace($containerPrefix, '', $field);

                foreach (array_keys($specimen['containers'] ?? []) as $containerIndex) {
                    $containerNumber = __('specimens.container_position', ['position' => $containerIndex + 1]);
                    $attributes["specimens.$index.containers.$containerIndex.$containerField"] = "$name, $number, $containerNumber";
                }
            }
        }

        return $attributes;
    }

    /**
     * Fail when a bound of the collected period falls on another day than the encounter.
     *
     * @return Closure
     */
    private function withinEncounterDate(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $encounterDate = $this->component->form->encounter['periodDate'] ?? '';

            if (empty($value) || empty($encounterDate)) {
                return;
            }

            // The range picker keeps both bounds in one field
            $periodBounds = array_filter(array_map('trim', explode('—', $value)));

            if (array_diff($periodBounds, [$encounterDate]) !== []) {
                $fail(__('specimens.validation.collected_period_outside_encounter', ['date' => $encounterDate]));
            }
        };
    }

    /**
     * Fail when the collected period bound is later than the end of the encounter.
     *
     * @param  string  $date  Date portion of the period bound, e.g. 16.09.2026
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
            $periodEnd = CarbonImmutable::createFromFormat($format, $encounter['periodDate'] . ' ' . $encounter['periodEnd']);

            if ($dateTime->greaterThan($periodEnd)) {
                $fail(__('validation.before_or_equal', ['date' => __('validation.attributes.encounter_period_end')]));
            }
        };
    }

    /**
     * Determine whether an observation or a diagnostic report of the package references the specimen.
     *
     * @param  string  $specimenId
     * @return bool
     */
    public function isReferenced(string $specimenId): bool
    {
        $isObservationSpecimen = collect($this->component->observationForm->observations)
            ->contains('specimenId', $specimenId);

        $isDiagnosticReportSpecimen = collect($this->component->diagnosticReportForm->diagnosticReports)
            ->contains(static fn (array $diagnosticReport): bool => in_array($specimenId, $diagnosticReport['specimenIds'] ?? [], true));

        return $specimenId !== '' && ($isObservationSpecimen || $isDiagnosticReportSpecimen);
    }

    /**
     * Number the specimen, and the container when the attribute belongs to one, for a validation message.
     *
     * @param  string  $attribute  Validated attribute, e.g. specimens.1.containers.0.identifier
     * @return array
     */
    private function positionReplacements(string $attribute): array
    {
        $parts = explode('.', $attribute);
        $replacements = ['position' => (int) $parts[1] + 1];

        if (($parts[2] ?? '') === 'containers') {
            $replacements['second-position'] = (int) $parts[3] + 1;
        }

        return $replacements;
    }

    /**
     * Ensure that a referenced specimen is either added in the current package
     * or already belongs to the patient and is still available.
     *
     * @param  string  $specimenId
     * @param  Closure  $fail
     * @param  string  $messagesKey  Translation group holding the not_found and not_available messages
     * @param  array  $replace  Replacements the messages of the group carry
     * @return void
     */
    public function validateReference(
        string $specimenId,
        Closure $fail,
        string $messagesKey = 'specimens.validation',
        array $replace = []
    ): void {
        if (collect($this->specimens)->contains('uuid', $specimenId)) {
            return;
        }

        $patientSpecimen = collect($this->component->patientSpecimens)->firstWhere('uuid', $specimenId);

        if ($patientSpecimen === null) {
            $fail(__("$messagesKey.not_found", $replace));

            return;
        }

        if ($patientSpecimen['status'] !== Status::AVAILABLE->value) {
            $fail(__("$messagesKey.not_available", $replace));
        }
    }
}
