<?php

declare(strict_types=1);

namespace App\Livewire\Procedure\Forms;

use App\Enums\Equipment\AvailabilityStatus;
use App\Enums\Equipment\Status as EquipmentStatus;
use App\Enums\Person\ProcedureStatus;
use App\Enums\Status;
use App\Enums\User\Role;
use App\Rules\AfterOrEqualDateTime;
use App\Rules\InDictionary;
use App\Rules\PastDateTime;
use Closure;
use Illuminate\Validation\Rule;
use App\Models\Equipment;
use App\Core\BaseForm;

class ProcedureForm extends BaseForm
{
    public array $procedure = [];

    protected function rules(): array
    {
        $isCompleted = data_get($this->procedure, 'status') === ProcedureStatus::COMPLETED->value;

        $isPaperReferral = data_get($this->procedure, 'referralType') === 'paper';
        $isElectronicReferral = data_get($this->procedure, 'referralType') === 'electronic';

        $hasBasedOn = !empty(data_get($this->procedure, 'basedOnIdentifier'));
        $hasPaperReferralData = !empty(data_get($this->procedure, 'paperReferralRequesterLegalEntityEdrpou'));

        $isPrimarySourceFalse = data_get($this->procedure, 'primarySource') === false;
        $isPrimarySourceTrue = data_get($this->procedure, 'primarySource') === true;

        return [
            'procedure.uuid' => ['nullable', 'uuid'],
            'procedure.status' => ['required', Rule::in([
                ProcedureStatus::COMPLETED->value,
                ProcedureStatus::NOT_DONE->value,
            ])],
            'procedure.categoryCode' => ['required', 'string', new InDictionary('eHealth/procedure_categories')],
            'procedure.codeValue' => ['required', 'uuid'],
            'procedure.primarySource' => [
                'required',
                'boolean',
            ],
            'procedure.performerEmployeeId' => [
                Rule::requiredIf($isPrimarySourceTrue),
                Rule::prohibitedIf($isPrimarySourceFalse),
                'nullable',
                'uuid',
                Rule::exists('employees', 'uuid')->where(
                    function ($query): void {
                        $query
                            ->where('legal_entity_id', legalEntity()->id)
                            ->where('status', Status::APPROVED->value)
                            ->where('is_active', true)
                            ->whereIn('employee_type', [
                                Role::DOCTOR->value,
                                Role::SPECIALIST->value,
                                Role::ASSISTANT->value,
                            ]);

                        $divisionUuid = data_get($this->procedure, 'divisionId');

                        if (filled($divisionUuid)) {
                            $query->where('division_uuid',  $divisionUuid);
                        }
                    }
                ),
            ],
            'procedure.divisionId' => ['nullable', 'uuid'],
            'procedure.outcomeCode' => ['nullable', 'string', new InDictionary('eHealth/procedure_outcomes')],
            'procedure.note' => ['nullable', 'string', 'max:255'],

            'procedure.isReferralAvailable' => ['nullable', 'boolean'],
            'procedure.referralType' => [
                'required',
                Rule::in(['electronic', 'paper']),
            ],
            'procedure.reportOriginCode' => [
                Rule::requiredIf($isPrimarySourceFalse),
                Rule::prohibitedIf($isPrimarySourceTrue),
                'nullable',
                'string',
                new InDictionary('eHealth/report_origins'),
            ],
            'procedure.reportOriginText' => ['nullable', 'string', 'max:255'],
            'procedure.basedOnIdentifier' => [
                Rule::requiredIf($isElectronicReferral),
                Rule::prohibitedIf($isPaperReferral),
                'nullable',
                'uuid',
                function (string $attribute, mixed $value, Closure $fail) use ($hasBasedOn, $hasPaperReferralData): void {
                    if (!$hasBasedOn && !$hasPaperReferralData) {
                        $fail('Потрібно вказати електронне направлення або паперове направлення.');
                    }

                    if ($hasBasedOn && $hasPaperReferralData) {
                        $fail('Можна вказати лише одне: електронне направлення або паперове направлення.');
                    }
                },
            ],
            'procedure.paperReferralRequisition' => ['nullable', 'string', 'max:255'],
            'procedure.paperReferralRequesterEmployeeName' => [
                Rule::requiredIf($isPaperReferral),
                Rule::prohibitedIf($isElectronicReferral),
                'nullable',
                'string',
                'max:255',
            ],
            'procedure.paperReferralRequesterLegalEntityEdrpou' => [
                Rule::requiredIf($isPaperReferral),
                Rule::prohibitedIf($isElectronicReferral),
                'nullable',
                'digits_between:8,10',
            ],
            'procedure.paperReferralRequesterLegalEntityName' => [
                Rule::prohibitedIf($isElectronicReferral),
                'nullable',
                'string',
                'max:255',
            ],
            'procedure.paperReferralServiceRequestDate' => [
                Rule::requiredIf($isPaperReferral),
                Rule::prohibitedIf($isElectronicReferral),
                'nullable',
                'date_format:' . config('app.date_format'),
            ],
            'procedure.paperReferralNote' => ['nullable', 'string', 'max:255'],
            'procedure.performedType' => [
                Rule::requiredIf($isCompleted),
                Rule::prohibitedIf(!$isCompleted),
                'nullable',
                Rule::in(['date_time', 'period']),
            ],

            'procedure.performedDate' => [
                Rule::requiredIf(
                    $isCompleted  && data_get($this->procedure, 'performedType')  === 'date_time'
                ),
                Rule::prohibitedIf(
                    !$isCompleted || data_get($this->procedure, 'performedType') !== 'date_time'
                ),
                'nullable',
                'date_format:' . config('app.date_format'),
                'before_or_equal:today',
            ],

            'procedure.performedTime' => [
                Rule::requiredIf(
                    $isCompleted && data_get($this->procedure, 'performedType') === 'date_time'
                ),
                Rule::prohibitedIf(
                    !$isCompleted || data_get($this->procedure, 'performedType') !== 'date_time'
                ),
                'nullable',
                'date_format:H:i',
                new PastDateTime(
                    data_get($this->procedure, 'performedDate', '')
                ),
            ],

            'procedure.performedPeriodStartDate' => [
                Rule::requiredIf(
                    $isCompleted && data_get($this->procedure, 'performedType') === 'period'
                ),
                Rule::prohibitedIf(
                    !$isCompleted || data_get($this->procedure, 'performedType') !== 'period'
                ),
                'nullable',
                'date_format:' . config('app.date_format'),
                'before_or_equal:today',
            ],
            'procedure.performedPeriodStartTime' => [
                Rule::requiredIf(
                    $isCompleted && data_get($this->procedure, 'performedType') === 'period'
                ),
                Rule::prohibitedIf(
                    !$isCompleted || data_get($this->procedure, 'performedType') !== 'period'
                ),
                'nullable',
                'date_format:H:i',
                new PastDateTime(
                    data_get($this->procedure, 'performedPeriodStartDate', '')
                ),
            ],
            'procedure.performedPeriodEndDate' => [
                Rule::requiredIf(
                    $isCompleted && data_get($this->procedure, 'performedType') === 'period'
                ),
                Rule::prohibitedIf(
                    !$isCompleted || data_get($this->procedure, 'performedType') !== 'period'
                ),
                'nullable',
                'date_format:' . config('app.date_format'),
                'before_or_equal:today',
                'after_or_equal:procedure.performedPeriodStartDate',
            ],
            'procedure.performedPeriodEndTime' => [
                Rule::requiredIf(
                    $isCompleted && data_get($this->procedure, 'performedType') === 'period'
                ),
                Rule::prohibitedIf(
                    !$isCompleted || data_get($this->procedure, 'performedType') !== 'period'
                ),
                'nullable',
                'date_format:H:i',
                new PastDateTime(
                    data_get($this->procedure, 'performedPeriodEndDate', '')
                ),
                new AfterOrEqualDateTime(
                    data_get($this->procedure, 'performedPeriodEndDate', ''),
                    data_get($this->procedure, 'performedPeriodStartDate', ''),
                    data_get($this->procedure, 'performedPeriodStartTime', '')
                ),
            ],
            'procedure.reasonReferences' => ['nullable', 'array'],
            'procedure.reasonReferences.*.id' => ['nullable', 'uuid'],
            'procedure.reasonReferences.*.type' => ['nullable', 'string', Rule::in(['condition', 'observation'])],

            'procedure.usedCodes' => ['nullable', 'array'],
            'procedure.usedCodes.*.code' => [
                'required',
                Rule::in(
                    dictionary()->basics()
                        ->byName('eHealth/assistive_products')
                        ->flattenedChildValues(true, true)
                        ->keys()
                        ->values()
                        ->toArray()
                ),
            ],

            'procedure.usedReferences' => ['nullable', 'array'],
            'procedure.usedReferences.*.id' => [
                'nullable',
                'uuid',
                'distinct',
                Rule::exists('equipments', 'uuid')
                    ->where('legal_entity_id', legalEntity()->id)
                    ->where('status', EquipmentStatus::ACTIVE->value)
                    ->where('availability_status', AvailabilityStatus::AVAILABLE->value),

                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (!$value) {
                        return;
                    }

                    $divisionUuid = data_get($this->procedure, 'divisionId');

                    if (!$divisionUuid) {
                        return;
                    }

                    $belongsToDivision = Equipment::query()
                        ->where('uuid', $value)
                        ->whereHas('division', static fn ($query) => $query->where('uuid', $divisionUuid))
                        ->exists();

                    if (!$belongsToDivision) {
                        $fail('Обладнання не належить вибраному підрозділу процедури.');
                    }
                },
            ],
        ];
    }
}