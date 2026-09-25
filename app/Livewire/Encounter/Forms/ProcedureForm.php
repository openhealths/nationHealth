<?php

declare(strict_types=1);

namespace App\Livewire\Encounter\Forms;

use App\Enums\Equipment\AvailabilityStatus;
use App\Enums\Equipment\Status as EquipmentStatus;
use App\Enums\Person\ProcedureStatus;
use App\Enums\Status;
use App\Models\Employee\Employee;
use App\Models\Equipment;
use App\Rules\InDictionary;
use App\Rules\MedicalEvents\PaperReferralRules;
use App\Rules\PrimarySourceRequiredForAssistant;
use App\Rules\PastDateTime;
use App\Rules\AfterOrEqualDateTime;
use Closure;
use Illuminate\Validation\Rule;
use Livewire\Form;
use Carbon\CarbonImmutable;
use Throwable;

class ProcedureForm extends Form
{
    public array $procedures = [];

    /**
     * Name the fields of a procedure the way the form labels them.
     *
     * @return array
     */
    public function validationAttributes(): array
    {
        $names = __('procedures.attributes');
        // A field nested deeper than one record keeps the name it carries for every index
        $attributes = collect($names)
            ->mapWithKeys(static fn (string $name, string $field): array => ["procedures.*.$field" => $name])
            ->all();

        // Each name carries the procedure number, so an error points to the card it belongs to
        foreach ($this->procedures as $index => $procedure) {
            $number = __('procedures.position', ['position' => $index + 1]);

            foreach ($names as $field => $name) {
                if (!str_contains($field, '.*.')) {
                    $attributes["procedures.$index.$field"] = "$name, $number";

                    continue;
                }

                [$nestedProperty, $nestedField] = explode('.*.', $field, 2);

                foreach (array_keys($procedure[$nestedProperty] ?? []) as $nestedIndex) {
                    $attributes["procedures.$index.$nestedProperty.$nestedIndex.$nestedField"] = "$name, $number";
                }
            }
        }

        return $attributes;
    }

    protected function rules(): array
    {
        // A complication is picked among the conditions of the same package
        $conditionUuids = collect($this->component->conditionForm->conditions)
            ->pluck('uuid')
            ->filter()
            ->values()
            ->toArray();

        return [
            'procedures' => ['nullable', 'array'],
            // for edit page
            'procedures.*.uuid' => ['nullable', 'uuid'],
            'procedures.*.status' => [
                'required_with:procedures',
                Rule::in([
                    ProcedureStatus::COMPLETED->value,
                    ProcedureStatus::NOT_DONE->value,
                ])
            ],
            'procedures.*.codeValue' => [
                'required_with:procedures',
                'uuid',
                'max:255',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $index = (int) explode('.', $attribute)[1];

                    $categoryCode = data_get($this->procedures, $index . '.categoryCode');

                    $service = dictionary()
                        ->services()
                        ->flattened()
                        ->firstWhere('id', $value);

                    if ($service === null || data_get($service, 'category') !== $categoryCode) {
                        $fail(
                            __('validation.exists', [
                                'attribute' => __('procedures.attributes.codeValue')
                            ])
                        );
                    }
                }
            ],
            'procedures.*.categoryCode' => [
                'required_with:procedures',
                'string',
                new InDictionary('eHealth/procedure_categories')
            ],
            'procedures.*.primarySource' => [
                'required_with:procedures',
                'boolean',
                new PrimarySourceRequiredForAssistant()
            ],
            'procedures.*.performerEmployeeId' => Rule::forEach(
                function (mixed $value, string $attribute): array {
                    $index = (int) explode('.', $attribute)[1];
                    $procedure = $this->procedures[$index] ?? [];

                    $isPrimarySource = ($procedure['primarySource'] ?? true) === true;

                    return [
                        Rule::requiredIf($isPrimarySource),
                        Rule::prohibitedIf(!$isPrimarySource),
                        'nullable',
                        'uuid',
                        function (string $attribute, mixed $value, Closure $fail): void {
                            $this->validatePerformer($value, $fail);
                        }
                    ];
                }
            ),
            'procedures.*.reportOriginCode' => Rule::forEach(function (mixed $value, string $attribute) {
                $index = (int)explode('.', $attribute)[1];
                $primarySource = $this->procedures[$index]['primarySource'] ?? true;

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
            'procedures.*.performedType' => Rule::forEach(
                function (mixed $value, string $attribute): array {
                    $index = (int) explode('.', $attribute)[1];

                    $isCompleted =
                        ($this->procedures[$index]['status'] ?? null)
                        === ProcedureStatus::COMPLETED->value;

                    return [
                        Rule::requiredIf($isCompleted),
                        Rule::prohibitedIf(!$isCompleted),
                        'nullable',
                        Rule::in(['date_time', 'period']),
                    ];
                }
            ),
            'procedures.*.performedDate' => Rule::forEach(
                function (mixed $value, string $attribute): array {
                    $index = (int) explode('.', $attribute)[1];
                    $procedure = $this->procedures[$index] ?? [];

                    $isDateTime =
                        ($procedure['status'] ?? null)
                            === ProcedureStatus::COMPLETED->value
                        && ($procedure['performedType'] ?? null)
                            === 'date_time';

                    return [
                        Rule::requiredIf($isDateTime),
                        Rule::prohibitedIf(!$isDateTime),
                        'nullable',
                        'date_format:' . config('app.date_format'),
                        'before_or_equal:today',
                        'date_equals:' . (($this->encounter()['periodDate'] ?? '') ?: 'today'),
                    ];
                }
            ),
            'procedures.*.performedTime' => Rule::forEach(
                function (mixed $value, string $attribute): array {
                    $index = (int) explode('.', $attribute)[1];
                    $procedure = $this->procedures[$index] ?? [];

                    $isDateTime = ($procedure['status'] ?? null) === ProcedureStatus::COMPLETED->value && ($procedure['performedType'] ?? null) === 'date_time';

                    return [
                        Rule::requiredIf($isDateTime),
                        Rule::prohibitedIf(!$isDateTime),
                        'nullable',
                        'date_format:H:i',
                        new PastDateTime($procedure['performedDate'] ?? ''),
                        $this->withinEncounterPeriod($procedure['performedDate'] ?? ''),
                    ];
                }
            ),
            'procedures.*.performedPeriodStartDate' => Rule::forEach(
                function (mixed $value, string $attribute): array {
                    $index = (int) explode('.', $attribute)[1];
                    $procedure = $this->procedures[$index] ?? [];
                    $isPeriod = ($procedure['status'] ?? null) === ProcedureStatus::COMPLETED->value && ($procedure['performedType'] ?? null) === 'period';

                    return [
                        Rule::requiredIf($isPeriod),
                        Rule::prohibitedIf(!$isPeriod),
                        'nullable',
                        'date_format:' . config('app.date_format'),
                        'before_or_equal:today',
                        'date_equals:' . (($this->encounter()['periodDate'] ?? '') ?: 'today'),
                    ];
                }
            ),
            'procedures.*.performedPeriodStartTime' => Rule::forEach(
                function (mixed $value, string $attribute): array {
                    $index = (int) explode('.', $attribute)[1];
                    $procedure = $this->procedures[$index] ?? [];
                    $isPeriod = ($procedure['status'] ?? null) === ProcedureStatus::COMPLETED->value && ($procedure['performedType'] ?? null) === 'period';

                    return [
                        Rule::requiredIf($isPeriod),
                        Rule::prohibitedIf(!$isPeriod),
                        'nullable',
                        'date_format:H:i',
                        new PastDateTime($procedure['performedPeriodStartDate'] ?? ''),
                        $this->withinEncounterPeriod($procedure['performedPeriodStartDate'] ?? ''),
                    ];
                }
            ),
            'procedures.*.performedPeriodEndDate' => Rule::forEach(
                function (mixed $value, string $attribute): array {
                    $index = (int) explode('.', $attribute)[1];
                    $procedure = $this->procedures[$index] ?? [];
                    $isPeriod = ($procedure['status'] ?? null) === ProcedureStatus::COMPLETED->value && ($procedure['performedType'] ?? null) === 'period';

                    return [
                        Rule::requiredIf($isPeriod),
                        Rule::prohibitedIf(!$isPeriod),
                        'nullable',
                        'date_format:' . config('app.date_format'),
                        'before_or_equal:today',
                        'date_equals:' . (($this->encounter()['periodDate'] ?? '') ?: 'today'),
                        'after_or_equal:procedures.*.performedPeriodStartDate',
                    ];
                }
            ),
            'procedures.*.performedPeriodEndTime' => Rule::forEach(
                function (mixed $value, string $attribute): array {
                    $index = (int) explode('.', $attribute)[1];
                    $procedure = $this->procedures[$index] ?? [];
                    $isPeriod = ($procedure['status'] ?? null) === ProcedureStatus::COMPLETED->value && ($procedure['performedType'] ?? null) === 'period';

                    return [
                        Rule::requiredIf($isPeriod),
                        Rule::prohibitedIf(!$isPeriod),
                        'nullable',
                        'date_format:H:i',
                        new PastDateTime($procedure['performedPeriodEndDate'] ?? ''),
                        new AfterOrEqualDateTime(
                            $procedure['performedPeriodEndDate'] ?? '',
                            $procedure['performedPeriodStartDate'] ?? '',
                            $procedure['performedPeriodStartTime'] ?? '',
                            'performed_period_start'
                        ),
                        $this->withinEncounterPeriod($procedure['performedPeriodEndDate'] ?? ''),
                    ];
                }
            ),
            'procedures.*.note' => ['nullable', 'string'],
            ...PaperReferralRules::for('procedures.*', $this->procedures),
            'procedures.*.isReferralAvailable' => ['nullable', 'boolean'],
            'procedures.*.referralType' => Rule::forEach(function (mixed $value, string $attribute) {
                $index = (int)explode('.', $attribute)[1];
                $procedure = $this->procedures[$index] ?? [];

                $isReferralAvailable = ($procedure['isReferralAvailable'] ?? false) === true;

                return [
                    Rule::requiredIf($isReferralAvailable),
                    'nullable',
                    Rule::in(['electronic', 'paper']),
                ];
            }),
            'procedures.*.basedOnIdentifier' => Rule::forEach(function (mixed $value, string $attribute) {
                $index = (int)explode('.', $attribute)[1];
                $procedure = $this->procedures[$index] ?? [];

                $isElectronicReferral = ($procedure['referralType'] ?? '') === 'electronic';
                $isPaperReferral = ($procedure['referralType'] ?? '') === 'paper';

                return [
                    Rule::requiredIf($isElectronicReferral),
                    Rule::prohibitedIf($isPaperReferral),
                    'nullable',
                    'string',
                    'max:255',
                ];
            }),
            'procedures.*.usedCodes' => ['nullable', 'array'],
            'procedures.*.usedCodes.*.code' => ['required', new InDictionary('eHealth/assistive_products')],
            'procedures.*.reasonReferences' => ['nullable', 'array'],
            'procedures.*.reasonReferences.*.id' => ['nullable', 'uuid'],
            'procedures.*.reasonReferences.*.type' => [
                'nullable',
                'string',
                Rule::in(['observation', 'condition'])
            ],
            'procedures.*.reasonReferences.*.codeCode' => Rule::forEach(
                fn (mixed $value, string $attribute) => $this->reasonReferenceCodeRule($attribute)
            ),
            'procedures.*.complicationDetails' => ['nullable', 'array'],
            'procedures.*.complicationDetails.*.id' => ['nullable', 'uuid', Rule::in($conditionUuids)],
            'procedures.*.complicationDetails.*.type' => ['nullable', 'string', Rule::in(['condition'])],
            'procedures.*.complicationDetails.*.codeCode' => [
                'nullable',
                'string',
                new InDictionary(['eHealth/ICPC2/condition_codes', 'eHealth/ICD10_AM/condition_codes'])
            ],
            'procedures.*.usedReferences' => ['nullable', 'array'],
            'procedures.*.usedReferences.*.id' => [
                'nullable',
                'uuid',
                'distinct',
                Rule::exists('equipments', 'uuid')
                    ->where('legal_entity_id', legalEntity()->id)
                    ->where('status', EquipmentStatus::ACTIVE->value)
                    ->where('availability_status', AvailabilityStatus::AVAILABLE->value),

                function (string $attribute, mixed $value, Closure $fail): void {
                    if (!$value) {
                        return;
                    }

                    $index = (int)explode('.', $attribute)[1];
                    $divisionUuid = data_get($this->procedures[$index] ?? [], 'divisionId');

                    if (!$divisionUuid) {
                        return;
                    }

                    $belongsToDivision = Equipment::whereUuid($value)
                        ->whereHas('division', static fn ($query) => $query->where('uuid', $divisionUuid))
                        ->exists();

                    if (!$belongsToDivision) {
                        $fail(__('equipments.validation.not_belongs_to_division'));
                    }
                }
            ]
        ];
    }

    /**
     * The default wording says nothing about a procedure needing a performer at all.
     *
     * @return array
     */
    protected function messages(): array
    {
        return [
            'procedures.*.performerEmployeeId.required' => __('procedures.validation.performer_required')
        ];
    }

    private function withinEncounterPeriod(string $date): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($date): void {
            $encounter = $this->encounter();

            if (empty($date) || empty($value) || empty($encounter['periodDate']) || empty($encounter['periodStart']) || empty($encounter['periodEnd'])) {
                return;
            }

            try {
                $format = config('app.date_format') . ' H:i';
                $performed = CarbonImmutable::createFromFormat($format, $date . ' ' . $value);
                $encounterStart = CarbonImmutable::createFromFormat($format, $encounter['periodDate'] . ' ' . $encounter['periodStart']);
                $encounterEnd = CarbonImmutable::createFromFormat($format, $encounter['periodDate'] . ' ' . $encounter['periodEnd']);
            } catch (Throwable) {
                return;
            }

            if ($performed->lessThan($encounterStart) || $performed->greaterThan($encounterEnd)) {
                $fail(__('procedures.validation.performed_outside_encounter_period'));
            }
        };
    }

    private function encounter(): array
    {
        return $this->component->form->encounter;
    }

    /**
     * The performer has to be an approved employee of this legal entity, allowed to perform procedures,
     * and taking part in the encounter the procedure is written in.
     *
     * @param  string  $employeeUuid
     * @param  Closure  $fail
     * @return void
     */
    private function validatePerformer(string $employeeUuid, Closure $fail): void
    {
        $employee = Employee::whereUuid($employeeUuid)
            ->first([
                'uuid',
                'legal_entity_id',
                'status',
                'employee_type',
                'is_active'
            ]);

        if ($employee === null) {
            $fail(__('procedures.validation.performer_employee_not_found'));

            return;
        }

        if ($employee->legalEntityId !== legalEntity()->id) {
            $fail(__('procedures.validation.performer_wrong_legal_entity', ['employee' => $employeeUuid]));

            return;
        }

        if ($employee->status !== Status::APPROVED || !$employee->isActive) {
            $fail(__('procedures.validation.performer_invalid_status'));

            return;
        }

        $allowedEmployeeTypes = config('ehealth.encounter_package_allowed_procedure_performer_employee_types', []);

        if (!in_array($employee->employeeType, $allowedEmployeeTypes, true)) {
            $fail(__('procedures.validation.performer_employee_invalid_type'));

            return;
        }

        $isParticipant = collect($this->component->form->encounter['participant'] ?? [])->contains(
            static fn (array $participant): bool => ($participant['uuid'] ?? '') === $employeeUuid
        );

        if (!$isParticipant) {
            $fail(__('procedures.validation.performer_not_participant'));
        }
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
                'eHealth/ICD10_AM/condition_codes'
            ],
        };

        return ['nullable', 'string', new InDictionary($dictionaries)];
    }
}
