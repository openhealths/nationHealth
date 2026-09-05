<?php

declare(strict_types=1);

namespace App\Livewire\Encounter\Forms;

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
        return collect(__('clinical-impressions.attributes'))
            ->mapWithKeys(static fn (string $name, string $field): array => ["clinicalImpressions.*.$field" => $name])
            ->all();
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
            'clinicalImpressions.*.previous' => ['nullable', 'array'],
            'clinicalImpressions.*.previous.*.id' => ['required_with:clinicalImpressions.*.previous', 'uuid'],
            'clinicalImpressions.*.problems' => ['nullable', 'array'],
            'clinicalImpressions.*.problems.*.id' => ['required_with:clinicalImpressions.*.problems', 'uuid'],
            'clinicalImpressions.*.findings' => ['nullable', 'array'],
            'clinicalImpressions.*.findings.*.id' => ['required_with:clinicalImpressions.*.findings', 'uuid'],
            'clinicalImpressions.*.findings.*.type' => ['required_with:clinicalImpressions.*.findings', 'string'],
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
