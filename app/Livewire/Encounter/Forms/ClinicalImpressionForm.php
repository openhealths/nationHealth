<?php

declare(strict_types=1);

namespace App\Livewire\Encounter\Forms;

use App\Enums\Status;
use App\Models\Employee\Employee;
use App\Rules\AfterOrEqualDateTime;
use App\Rules\InDictionary;
use App\Rules\PastDateTime;
use Illuminate\Validation\Rule;
use Livewire\Form;

class ClinicalImpressionForm extends Form
{
    public array $clinicalImpressions = [];

    /**
     * Name the fields of a clinical impression the way the form labels them.
     *
     * @return array
     */
    public function validationAttributes(): array
    {
        $names = __('clinical-impressions.attributes');
        // A field nested deeper than one record keeps the name it carries for every index
        $attributes = collect($names)
            ->mapWithKeys(static fn (string $name, string $field): array => ["clinicalImpressions.*.$field" => $name])
            ->all();

        // Each name carries the clinical impression number, so an error points to the card it belongs to
        foreach ($this->clinicalImpressions as $index => $clinicalImpression) {
            $number = __('clinical-impressions.position', ['position' => $index + 1]);

            foreach ($names as $field => $name) {
                if (!str_contains($field, '.*.')) {
                    $attributes["clinicalImpressions.$index.$field"] = "$name, $number";

                    continue;
                }

                [$nestedProperty, $nestedField] = explode('.*.', $field, 2);

                foreach (array_keys($clinicalImpression[$nestedProperty] ?? []) as $nestedIndex) {
                    $attributes["clinicalImpressions.$index.$nestedProperty.$nestedIndex.$nestedField"] = "$name, $number";
                }
            }
        }

        return $attributes;
    }

    protected function rules(): array
    {
        return [
            'clinicalImpressions' => ['nullable', 'array'],
            // for edit page
            'clinicalImpressions.*.uuid' => ['nullable', 'uuid'],
            'clinicalImpressions.*.codeCode' => [
                'required_with:clinicalImpressions',
                'string',
                'max:255',
                new InDictionary('eHealth/clinical_impression_patient_categories')
            ],
            'clinicalImpressions.*.assessorEmployeeId' => [
                'required_with:clinicalImpressions',
                'uuid',
                Rule::exists(Employee::class, 'uuid')
                    ->where('legal_entity_id', legalEntity()->id)
                    ->where('status', Status::APPROVED->value)
                    ->where('is_active', true)
            ],
            'clinicalImpressions.*.description' => ['nullable', 'string', 'max:1000'],
            'clinicalImpressions.*.effectivePeriodStartDate' => [
                'required_with:clinicalImpressions',
                'date',
                'before_or_equal:' . (($this->component->form->encounter['periodDate'] ?? '') ?: 'today'),
            ],
            'clinicalImpressions.*.effectivePeriodStartTime' => [
                'required_with:clinicalImpressions',
                'date_format:H:i',
            ],
            'clinicalImpressions.*.effectivePeriodEndDate' => Rule::forEach(fn (mixed $value, string $attribute) => [
                'required_with:clinicalImpressions',
                'date',
                'before_or_equal:today',
                'after_or_equal:' . ($this->clinicalImpressions[(int)explode(
                    '.',
                    $attribute
                )[1]]['effectivePeriodStartDate'] ?? 'today'),
            ]),
            'clinicalImpressions.*.effectivePeriodEndTime' => Rule::forEach(function (mixed $value, string $attribute) {
                $index = (int)explode('.', $attribute)[1];
                $clinicalImpression = $this->clinicalImpressions[$index];

                return [
                    'required_with:clinicalImpressions',
                    'date_format:H:i',
                    new PastDateTime($clinicalImpression['effectivePeriodEndDate'] ?? ''),
                    new AfterOrEqualDateTime(
                        $clinicalImpression['effectivePeriodEndDate'] ?? '',
                        $clinicalImpression['effectivePeriodStartDate'] ?? '',
                        $clinicalImpression['effectivePeriodStartTime'] ?? ''
                    ),
                ];
            }),
            'clinicalImpressions.*.note' => ['nullable', 'string', 'max:3000'],
            'clinicalImpressions.*.summary' => ['nullable', 'string'],
            'clinicalImpressions.*.previous' => ['nullable', 'array'],
            'clinicalImpressions.*.previous.*.id' => ['required_with:clinicalImpressions.*.previous', 'uuid'],
            'clinicalImpressions.*.problems' => ['nullable', 'array'],
            'clinicalImpressions.*.problems.*.id' => ['required_with:clinicalImpressions.*.problems', 'uuid'],
            'clinicalImpressions.*.findings' => ['nullable', 'array'],
            'clinicalImpressions.*.findings.*.id' => ['required_with:clinicalImpressions.*.findings', 'uuid'],
            'clinicalImpressions.*.findings.*.type' => ['required_with:clinicalImpressions.*.findings', 'string'],
            'clinicalImpressions.*.findings.*.basis' => ['nullable', 'string', 'max:255'],
            'clinicalImpressions.*.supportingInfo' => ['nullable', 'array'],
            'clinicalImpressions.*.supportingInfo.*.uuid' => [
                'required_with:clinicalImpressions.*.supportingInfo',
                'uuid'
            ],
            'clinicalImpressions.*.supportingInfo.*.type' => [
                'required_with:clinicalImpressions.*.supportingInfo',
                'string'
            ],
        ];
    }
}
