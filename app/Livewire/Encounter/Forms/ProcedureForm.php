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
use App\Rules\PastDateTime;
use Closure;
use Illuminate\Validation\Rule;
use Livewire\Form;

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
        return collect(__('procedures.attributes'))
            ->mapWithKeys(static fn (string $name, string $field): array => ["procedures.*.$field" => $name])
            ->all();
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
            'procedures.*.codeValue' => ['required_with:procedures', 'uuid', 'max:255'],
            'procedures.*.categoryCode' => [
                'required_with:procedures',
                'string',
                new InDictionary('eHealth/procedure_categories')
            ],
            'procedures.*.primarySource' => ['required_with:procedures', 'boolean'],
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
                $primarySource = $this->procedures[$index]['primarySource'];

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
                        new PastDateTime(
                            $procedure['performedDate'] ?? ''
                        ),
                    ];
                }
            ),
            'procedures.*.performedPeriodStartDate' => ['nullable'],
            'procedures.*.performedPeriodStartTime' => ['nullable'],
            'procedures.*.performedPeriodEndDate' => ['nullable'],
            'procedures.*.performedPeriodEndTime' => ['nullable'],
            'procedures.*.note' => ['nullable', 'string'],
            'procedures.*.paperReferralRequisition' => ['nullable', 'string', 'max:255'],
            'procedures.*.paperReferralNote' => ['nullable', 'string'],
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
            'procedures.*.paperReferralRequesterEmployeeName' => Rule::forEach(
                function (mixed $value, string $attribute) {
                    $index = (int)explode('.', $attribute)[1];
                    $procedure = $this->procedures[$index] ?? [];

                    $isPaperReferral = ($procedure['referralType'] ?? '') === 'paper';
                    $isElectronicReferral = ($procedure['referralType'] ?? '') === 'electronic';

                    return [
                        Rule::requiredIf($isPaperReferral),
                        Rule::prohibitedIf($isElectronicReferral),
                        'nullable',
                        'string',
                        'max:255',
                    ];
                }
            ),
            'procedures.*.paperReferralRequesterLegalEntityEdrpou' => Rule::forEach(
                function (mixed $value, string $attribute) {
                    $index = (int)explode('.', $attribute)[1];
                    $procedure = $this->procedures[$index] ?? [];

                    $isPaperReferral = ($procedure['referralType'] ?? '') === 'paper';
                    $isElectronicReferral = ($procedure['referralType'] ?? '') === 'electronic';

                    return [
                        Rule::requiredIf($isPaperReferral),
                        Rule::prohibitedIf($isElectronicReferral),
                        'nullable',
                        'digits_between:8,10',
                    ];
                }
            ),
            'procedures.*.paperReferralRequesterLegalEntityName' => Rule::forEach(
                function (mixed $value, string $attribute) {
                    $index = (int)explode('.', $attribute)[1];
                    $procedure = $this->procedures[$index] ?? [];

                    $isElectronicReferral = ($procedure['referralType'] ?? '') === 'electronic';

                    return [
                        Rule::prohibitedIf($isElectronicReferral),
                        'nullable',
                        'string',
                        'max:255',
                    ];
                }
            ),
            'procedures.*.paperReferralServiceRequestDate' => Rule::forEach(function (mixed $value, string $attribute) {
                $index = (int)explode('.', $attribute)[1];
                $procedure = $this->procedures[$index] ?? [];

                $isPaperReferral = ($procedure['referralType'] ?? '') === 'paper';
                $isElectronicReferral = ($procedure['referralType'] ?? '') === 'electronic';

                return [
                    Rule::requiredIf($isPaperReferral),
                    Rule::prohibitedIf($isElectronicReferral),
                    'nullable',
                    'date_format:' . config('app.date_format')
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
