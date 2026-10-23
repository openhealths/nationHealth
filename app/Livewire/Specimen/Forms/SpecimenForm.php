<?php

declare(strict_types=1);

namespace App\Livewire\Specimen\Forms;

use App\Core\BaseForm;
use App\Enums\Specimen\Status;
use App\Rules\AfterOrEqualDateTime;
use App\Rules\InDictionary;
use App\Rules\PastDateTime;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Validation\Rule;

class SpecimenForm extends BaseForm
{
    public array $specimen = [];

    /**
     * Get the validation rules for the specimen.
     *
     * @return array
     */
    protected function rules(): array
    {
        $collectedType = $this->specimen['collectedType'] ?? '';
        // The range picker keeps both bounds in one field
        $periodBounds = array_map('trim', explode('—', $this->specimen['collectedPeriodRange'] ?? ''));
        $minCollectedDate = CarbonImmutable::today()->subDays(config('ehealth.specimen_max_days_passed'));

        return [
            'specimen.registeredById' => [
                'required',
                'uuid',
                Rule::in(collect($this->component->registeredByEmployees)->pluck('uuid'))
            ],
            'specimen.typeCode' => ['required', 'string', new InDictionary('specimen_types')],
            'specimen.typeText' => ['nullable', 'string'],
            'specimen.conditionCode' => ['nullable', 'string', new InDictionary('specimen_conditions')],
            // A clarification only reaches eHealth as the text of the code it clarifies
            'specimen.conditionText' => [
                Rule::prohibitedIf(empty($this->specimen['conditionCode'] ?? '')),
                'nullable',
                'string'
            ],
            'specimen.note' => ['nullable', 'string'],
            'specimen.parentIds' => ['nullable', 'array'],
            'specimen.parentIds.*' => [
                'nullable',
                'uuid',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $parentSpecimen = collect($this->component->specimens)->firstWhere('uuid', $value);

                    if ($parentSpecimen === null) {
                        $fail(__('specimens.validation.not_found'));

                        return;
                    }

                    if ($parentSpecimen['status'] !== Status::AVAILABLE->value) {
                        $fail(__('specimens.validation.not_available'));
                    }
                }
            ],
            'specimen.collectorType' => ['required', Rule::in(['current', 'other', 'patient'])],
            'specimen.collectorId' => [
                'required',
                'uuid',
                ($this->specimen['collectorType'] ?? '') === 'patient'
                    ? Rule::in([$this->component->patientUuid])
                    : Rule::in(collect($this->component->employees)->pluck('uuid'))
            ],
            'specimen.collectedType' => ['required', Rule::in(['date_time', 'period'])],
            'specimen.collectedDate' => [
                Rule::requiredIf($collectedType === 'date_time'),
                'nullable',
                'date',
                'after:' . $minCollectedDate->format(config('app.date_format')),
                'before_or_equal:today'
            ],
            'specimen.collectedTime' => [
                Rule::requiredIf($collectedType === 'date_time'),
                'nullable',
                'date_format:H:i',
                new PastDateTime($this->specimen['collectedDate'] ?? '')
            ],
            'specimen.collectedPeriodRange' => [
                Rule::requiredIf($collectedType === 'period'),
                'nullable',
                'string',
                static function (string $attribute, mixed $value, Closure $fail) use ($periodBounds, $minCollectedDate): void {
                    $periodStart = CarbonImmutable::createFromFormat(config('app.date_format'), $periodBounds[0])->startOfDay();

                    if ($periodStart->lessThanOrEqualTo($minCollectedDate)) {
                        $fail(__('validation.after', ['date' => $minCollectedDate->format(config('app.date_format'))]));
                    }
                }
            ],
            'specimen.collectedPeriodStartTime' => [
                Rule::requiredIf($collectedType === 'period'),
                'nullable',
                'date_format:H:i',
                new PastDateTime($periodBounds[0])
            ],
            'specimen.collectedPeriodEndTime' => [
                'nullable',
                'date_format:H:i',
                new PastDateTime($periodBounds[1] ?? ''),
                new AfterOrEqualDateTime(
                    $periodBounds[1] ?? '',
                    $periodBounds[0],
                    $this->specimen['collectedPeriodStartTime'] ?? '',
                    'collected_period_start'
                )
            ],
            'specimen.durationValue' => ['nullable', 'numeric', 'gt:0'],
            'specimen.durationCode' => [
                'required_with:specimen.durationValue',
                'nullable',
                'string',
                new InDictionary('eHealth/ucum/units'),
                Rule::in(config('ehealth.specimen_duration_allowed_codes'))
            ],
            'specimen.quantityValue' => [
                'nullable',
                'numeric',
                'gt:0',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $containersQuantity = collect($this->specimen['containers'] ?? [])
                        ->sum(static fn (array $container): float => (float) ($container['specimenQuantityValue'] ?? 0));

                    if ($containersQuantity > (float) $value) {
                        $fail(__('specimens.validation.quantity_exceeded_by_containers'));
                    }
                }
            ],
            'specimen.quantityCode' => [
                'required_with:specimen.quantityValue',
                'nullable',
                'string',
                new InDictionary('eHealth/ucum/units')
            ],
            'specimen.methodCode' => ['nullable', 'string', new InDictionary('specimen_collection_methods')],
            'specimen.bodySiteCode' => ['nullable', 'string', new InDictionary('eHealth/body_sites')],
            'specimen.fastingStatusCode' => ['nullable', 'string', new InDictionary('fasting_statuses')],
            'specimen.containers' => ['required', 'array', 'min:1'],
            'specimen.containers.*.identifier' => ['required', 'string', 'distinct'],
            'specimen.containers.*.description' => ['nullable', 'string'],
            'specimen.containers.*.typeCode' => ['nullable', 'string', new InDictionary('specimen_container_types')],
            'specimen.containers.*.typeText' => Rule::forEach(fn (mixed $value, string $attribute): array => [
                Rule::prohibitedIf(empty($this->specimen['containers'][(int) explode('.', $attribute)[2]]['typeCode'] ?? '')),
                'nullable',
                'string'
            ]),
            'specimen.containers.*.capacityValue' => ['nullable', 'numeric', 'gt:0'],
            'specimen.containers.*.capacityCode' => [
                'required_with:specimen.containers.*.capacityValue',
                'nullable',
                'string',
                new InDictionary('eHealth/ucum/units')
            ],
            'specimen.containers.*.specimenQuantityValue' => ['nullable', 'numeric', 'gt:0'],
            'specimen.containers.*.specimenQuantityCode' => [
                'required_with:specimen.containers.*.specimenQuantityValue',
                'nullable',
                'string',
                new InDictionary('eHealth/ucum/units'),
                function (string $attribute, mixed $value, Closure $fail): void {
                    $containerIndex = (int) explode('.', $attribute)[2];
                    $containerQuantity = $this->specimen['containers'][$containerIndex]['specimenQuantityValue'] ?? '';

                    // Units are compared only when both the collected and the container quantity are filled in
                    if (empty($this->specimen['quantityValue']) || empty($containerQuantity)) {
                        return;
                    }

                    if ($value !== ($this->specimen['quantityCode'] ?? '')) {
                        $fail(__('specimens.validation.quantity_code_mismatch'));
                    }
                }
            ],
            'specimen.containers.*.additiveCode' => ['nullable', 'string', new InDictionary('specimen_container_additives')],
            'specimen.containers.*.additiveText' => Rule::forEach(fn (mixed $value, string $attribute): array => [
                Rule::prohibitedIf(empty($this->specimen['containers'][(int) explode('.', $attribute)[2]]['additiveCode'] ?? '')),
                'nullable',
                'string'
            ])
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
        $attributes = collect($names)
            ->mapWithKeys(static fn (string $name, string $field): array => ["specimen.$field" => $name])
            ->all();

        // Each container field carries the container number, so an error points to the card it belongs to
        foreach (array_keys($this->specimen['containers'] ?? []) as $containerIndex) {
            $containerNumber = __('specimens.container_position', ['position' => $containerIndex + 1]);

            foreach ($names as $field => $name) {
                if (str_starts_with($field, $containerPrefix)) {
                    $containerField = str_replace($containerPrefix, '', $field);
                    $attributes["specimen.containers.$containerIndex.$containerField"] = "$name, $containerNumber";
                }
            }
        }

        return $attributes;
    }
}
