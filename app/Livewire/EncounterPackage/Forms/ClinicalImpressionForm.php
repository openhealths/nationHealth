<?php

declare(strict_types=1);

namespace App\Livewire\EncounterPackage\Forms;

use App\Enums\Status;
use App\Enums\User\Role;
use App\Models\Employee\Employee;
use App\Models\MedicalEvents\Sql\ClinicalImpression;
use App\Rules\AfterOrEqualDateTime;
use App\Rules\InDictionary;
use App\Rules\PastDateTime;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
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

    /**
     * Get the records the clinical impressions refer to as problems, findings and supporting info.
     *
     * @return array
     */
    public function referencedRecords(): array
    {
        return collect($this->clinicalImpressions)
            ->flatMap(static fn (array $impression): array => [
                // A problem is always a condition, so it comes without a type
                ...array_map(
                    static fn (array $problem): array => ['id' => $problem['id'], 'type' => 'condition'],
                    $impression['problems'] ?? []
                ),
                ...($impression['findings'] ?? []),
                ...array_map(
                    static fn (array $supportingInfo): array => ['id' => $supportingInfo['uuid'], 'type' => $supportingInfo['type']],
                    $impression['supportingInfo'] ?? []
                )
            ])
            ->all();
    }

    /**
     * Get the clinical impression a nested attribute such as "clinicalImpressions.0.effectiveDate" belongs to.
     *
     * @param  string  $attribute
     * @return array
     */
    protected function clinicalImpressionAt(string $attribute): array
    {
        return $this->clinicalImpressions[(int) explode('.', $attribute)[1]];
    }

    protected function rules(): array
    {
        return [
            'clinicalImpressions' => [
                'nullable',
                'array',
                Rule::prohibitedIf(static fn (): bool => Auth::user()->cannot('create', ClinicalImpression::class))
            ],
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
                    ->whereIn('employee_type', [Role::DOCTOR->value, Role::SPECIALIST->value])
                    ->where(static fn (Builder $query): Builder => $query->whereNull('end_date')
                        ->orWhere('end_date', '>=', today()->toDateString()))
            ],
            'clinicalImpressions.*.description' => ['nullable', 'string', 'max:1000'],
            // An impression is made either at a moment or over a period, whose end may stay open
            'clinicalImpressions.*.effectiveType' => ['required_with:clinicalImpressions', Rule::in(['date_time', 'period'])],
            'clinicalImpressions.*.effectiveDate' => Rule::forEach(function (mixed $value, string $attribute): array {
                $isDateTime = ($this->clinicalImpressionAt($attribute)['effectiveType'] ?? null) === 'date_time';

                return [
                    Rule::requiredIf($isDateTime),
                    Rule::prohibitedIf(!$isDateTime),
                    'nullable',
                    'date',
                    'before_or_equal:' . (($this->component->form->encounter['periodDate'] ?? '') ?: 'today')
                ];
            }),
            'clinicalImpressions.*.effectiveTime' => Rule::forEach(function (mixed $value, string $attribute): array {
                $clinicalImpression = $this->clinicalImpressionAt($attribute);
                $isDateTime = ($clinicalImpression['effectiveType'] ?? null) === 'date_time';

                return [
                    Rule::requiredIf($isDateTime),
                    Rule::prohibitedIf(!$isDateTime),
                    'nullable',
                    'date_format:H:i',
                    new PastDateTime($clinicalImpression['effectiveDate'] ?? '')
                ];
            }),
            'clinicalImpressions.*.effectivePeriodStartDate' => Rule::forEach(function (mixed $value, string $attribute): array {
                $isPeriod = ($this->clinicalImpressionAt($attribute)['effectiveType'] ?? null) === 'period';

                return [
                    Rule::requiredIf($isPeriod),
                    Rule::prohibitedIf(!$isPeriod),
                    'nullable',
                    'date',
                    'before_or_equal:' . (($this->component->form->encounter['periodDate'] ?? '') ?: 'today')
                ];
            }),
            'clinicalImpressions.*.effectivePeriodStartTime' => Rule::forEach(function (mixed $value, string $attribute): array {
                $isPeriod = ($this->clinicalImpressionAt($attribute)['effectiveType'] ?? null) === 'period';

                return [Rule::requiredIf($isPeriod), Rule::prohibitedIf(!$isPeriod), 'nullable', 'date_format:H:i'];
            }),
            'clinicalImpressions.*.effectivePeriodEndDate' => Rule::forEach(function (mixed $value, string $attribute): array {
                $clinicalImpression = $this->clinicalImpressionAt($attribute);
                $isPeriod = ($clinicalImpression['effectiveType'] ?? null) === 'period';

                return [
                    Rule::requiredIf($isPeriod && !empty($clinicalImpression['effectivePeriodEndTime'])),
                    Rule::prohibitedIf(!$isPeriod),
                    'nullable',
                    'date',
                    'before_or_equal:today',
                    'after_or_equal:' . (($clinicalImpression['effectivePeriodStartDate'] ?? '') ?: 'today')
                ];
            }),
            'clinicalImpressions.*.effectivePeriodEndTime' => Rule::forEach(function (mixed $value, string $attribute): array {
                $clinicalImpression = $this->clinicalImpressionAt($attribute);
                $isPeriod = ($clinicalImpression['effectiveType'] ?? null) === 'period';

                return [
                    Rule::requiredIf($isPeriod && !empty($clinicalImpression['effectivePeriodEndDate'])),
                    Rule::prohibitedIf(!$isPeriod),
                    'nullable',
                    'date_format:H:i',
                    new PastDateTime($clinicalImpression['effectivePeriodEndDate'] ?? ''),
                    new AfterOrEqualDateTime(
                        $clinicalImpression['effectivePeriodEndDate'] ?? '',
                        $clinicalImpression['effectivePeriodStartDate'] ?? '',
                        $clinicalImpression['effectivePeriodStartTime'] ?? ''
                    )
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
