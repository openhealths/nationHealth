<?php

declare(strict_types=1);

namespace App\Livewire\DiagnosticReport\Forms;

use App\Core\BaseForm;
use App\Enums\User\Role;
use App\Enums\Status;
use App\Enums\Equipment\AvailabilityStatus;
use App\Enums\Equipment\Status as EquipmentStatus;
use App\Rules\AfterOrEqualDateTime;
use App\Rules\InDictionary;
use App\Rules\PastDateTime;
use App\Models\Employee\Employee;
use App\Models\Equipment;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;

class DiagnosticReportForm extends BaseForm
{
    public array $diagnosticReport = [];

    public array $observations = [];

    private const array LABORANT_ALLOWED_CATEGORIES = ['laboratory_procedure'];

    private const array RESULTS_INTERPRETER_REQUIRED_CATEGORIES = [
        'diagnostic_procedure',
        'imaging',
    ];

    /**
     * Name the fields of a diagnostic report and of its observations the way the form labels them.
     *
     * @return array
     */
    public function validationAttributes(): array
    {
        return [
            ...collect(__('diagnostic-reports.attributes'))
                ->mapWithKeys(static fn (string $name, string $field): array => ["diagnosticReport.$field" => $name])
                ->all(),
            ...collect(__('observations.attributes'))
                ->mapWithKeys(static fn (string $name, string $field): array => ["observations.*.$field" => $name])
                ->all()
        ];
    }

    protected function rules(): array
    {
        $isReferralAvailable = data_get($this->diagnosticReport, 'isReferralAvailable') === true;
        $isElectronicReferral = $isReferralAvailable && data_get($this->diagnosticReport, 'referralType') === 'electronic';
        $isPaperReferral = $isReferralAvailable && data_get($this->diagnosticReport, 'referralType') === 'paper';
        $effectiveType = data_get($this->diagnosticReport, 'effectiveType');

        return [
            'diagnosticReport.isReferralAvailable' => ['nullable', 'boolean'],
            'diagnosticReport.referralType' => [
                Rule::requiredIf($isReferralAvailable),
                'nullable',
                Rule::in(['electronic', 'paper'])
            ],
            'diagnosticReport.basedOnIdentifier' => [
                Rule::requiredIf($isElectronicReferral),
                Rule::prohibitedIf($isPaperReferral),
                'nullable',
                'uuid'
            ],
            'diagnosticReport.primarySource' => [
                'required',
                'boolean:strict',
                Rule::in([true])
            ],
            'diagnosticReport.categoryCode' => [
                'required',
                'string',
                new InDictionary('eHealth/diagnostic_report_categories'),
                function (string $attribute, mixed $value, Closure $fail): void {
                    $employeeType = Auth::user()->getDiagnosticReportWriterEmployee()?->employeeType;

                    if (
                        $employeeType === Role::LABORANT->value
                        && !in_array($value, self::LABORANT_ALLOWED_CATEGORIES, true)
                    ) {
                        $fail(__('diagnostic-reports.validation.laborant_category'));
                    }
                }
            ],
            'diagnosticReport.codeValue' => [
                'required',
                'uuid',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $categoryCode = data_get($this->diagnosticReport, 'categoryCode');

                    $service = dictionary()
                        ->services()
                        ->flattened()
                        ->firstWhere('id', $value);

                    if ($service === null || data_get($service, 'category') !== $categoryCode) {
                        $fail(
                            __('validation.exists', [
                                'attribute' => __('diagnostic-reports.attributes.codeValue'),
                            ])
                        );
                    }
                }
            ],
            'diagnosticReport.paperReferralRequisition' => ['nullable', 'string', 'max:255'],
            'diagnosticReport.paperReferralRequesterEmployeeName' => [
                Rule::requiredIf(
                    data_get($this->diagnosticReport, 'isReferralAvailable') === true
                    && data_get($this->diagnosticReport, 'referralType') === 'paper'
                ),
                'nullable',
                'string',
                'max:255'
            ],
            'diagnosticReport.paperReferralRequesterLegalEntityEdrpou' => [
                Rule::requiredIf(
                    data_get($this->diagnosticReport, 'isReferralAvailable') === true
                    && data_get($this->diagnosticReport, 'referralType') === 'paper'
                ),
                'nullable',
                'digits_between:8,10'
            ],
            'diagnosticReport.paperReferralRequesterLegalEntityName' => [
                'nullable',
                'string',
                'max:255'
            ],
            'diagnosticReport.paperReferralServiceRequestDate' => [
                Rule::requiredIf(
                    data_get($this->diagnosticReport, 'isReferralAvailable') === true
                    && data_get($this->diagnosticReport, 'referralType') === 'paper'
                ),
                'nullable',
                'date_format:' . config('app.date_format')
            ],
            'diagnosticReport.paperReferralNote' => ['nullable', 'string', 'max:255'],
            'diagnosticReport.effectiveType' => [
                'nullable',
                Rule::in(['date_time', 'period'])
            ],

            'diagnosticReport.effectiveDate' => [
                Rule::requiredIf($effectiveType === 'date_time'),
                Rule::prohibitedIf($effectiveType !== 'date_time'),
                'nullable',
                'date_format:' . config('app.date_format'),
                'before_or_equal:today'
            ],

            'diagnosticReport.effectiveTime' => [
                Rule::requiredIf($effectiveType === 'date_time'),
                Rule::prohibitedIf($effectiveType !== 'date_time'),
                'nullable',
                'date_format:H:i',
                new PastDateTime(data_get($this->diagnosticReport, 'effectiveDate', '')),
            ],

            'diagnosticReport.effectivePeriodStartDate' => [
                Rule::requiredIf($effectiveType === 'period'),
                Rule::prohibitedIf($effectiveType !== 'period'),
                'nullable',
                'date_format:' . config('app.date_format'),
                'before_or_equal:today'
            ],

            'diagnosticReport.effectivePeriodStartTime' => [
                Rule::requiredIf($effectiveType === 'period'),
                Rule::prohibitedIf($effectiveType !== 'period'),
                'nullable',
                'date_format:H:i',
                new PastDateTime(data_get($this->diagnosticReport, 'effectivePeriodStartDate', ''))
            ],

            'diagnosticReport.effectivePeriodEndDate' => [
                Rule::prohibitedIf($effectiveType !== 'period'),
                Rule::requiredIf(
                    $effectiveType === 'period' && !empty(data_get($this->diagnosticReport, 'effectivePeriodEndTime'))
                ),
                'nullable',
                'date_format:' . config('app.date_format'),
                'before_or_equal:today',
                'after_or_equal:diagnosticReport.effectivePeriodStartDate'
            ],

            'diagnosticReport.effectivePeriodEndTime' => [
                Rule::prohibitedIf($effectiveType !== 'period'),
                Rule::requiredIf(
                    $effectiveType === 'period' && !empty(data_get($this->diagnosticReport, 'effectivePeriodEndDate'))
                ),
                'nullable',
                'date_format:H:i',
                new PastDateTime(data_get($this->diagnosticReport, 'effectivePeriodEndDate', '')),
                new AfterOrEqualDateTime(
                    data_get($this->diagnosticReport, 'effectivePeriodEndDate', ''),
                    data_get($this->diagnosticReport, 'effectivePeriodStartDate', ''),
                    data_get($this->diagnosticReport, 'effectivePeriodStartTime', '')
                ),
                function (string $attribute, mixed $value, Closure $fail): void {
                    $issuedDate = data_get($this->diagnosticReport, 'issuedDate');
                    $issuedTime = data_get($this->diagnosticReport, 'issuedTime');
                    $endDate = data_get($this->diagnosticReport, 'effectivePeriodEndDate');

                    if (
                        empty($issuedDate)
                        || empty($issuedTime)
                        || empty($endDate)
                        || empty($value)
                    ) {
                        return;
                    }

                    $end = CarbonImmutable::createFromFormat(
                        config('app.date_format') . ' H:i',
                        $endDate . ' ' . $value
                    );

                    $issued = CarbonImmutable::createFromFormat(
                        config('app.date_format') . ' H:i',
                        $issuedDate . ' ' . $issuedTime
                    );

                    if ($end->isAfter($issued)) {
                        $fail(
                            __('validation.before_or_equal', [
                                'date' => __(
                                    'validation.attributes.issued'
                                ),
                            ])
                        );
                    }
                },
            ],
            'diagnosticReport.issuedDate' => [
                'required',
                'date_format:' . config('app.date_format'),
                'before_or_equal:today',
            ],
            'diagnosticReport.issuedTime' => [
                'required',
                'date_format:H:i',
                new PastDateTime(data_get($this->diagnosticReport, 'issuedDate', '')),
            ],
            'diagnosticReport.conclusionCode' => [
                'nullable',
                'string',
                new InDictionary('eHealth/ICD10_AM/condition_codes')
            ],
            'diagnosticReport.conclusion' => [
                'nullable',
                'string',
                'max:1000',
            ],
            'diagnosticReport.usedReferences' => ['nullable', 'array'],
            'diagnosticReport.usedReferences.*.id' => [
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

                    $divisionUuid = data_get($this->diagnosticReport, 'divisionId');

                    if (!$divisionUuid) {
                        return;
                    }

                    $belongsToDivision = Equipment::whereUuid($value)
                        ->whereHas('division', static fn ($query) => $query->where('uuid', $divisionUuid))
                        ->exists();

                    if (!$belongsToDivision) {
                        $fail(__('equipments.validation.not_belongs_to_division'));
                    }
                },
            ],
            'diagnosticReport.divisionId' => ['nullable', 'uuid'],
            'diagnosticReport.performerEmployeeIds' => [
                'required_without:diagnosticReport.resultsInterpreterEmployeeId',
                'nullable',
                'array',
                'min:1'
            ],
            'diagnosticReport.performerEmployeeIds.*' => [
                'required',
                'uuid',
                'distinct',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $employee = Employee::whereUuid($value)
                        ->first([
                            'uuid',
                            'legal_entity_id',
                            'status',
                            'employee_type',
                        ]);

                    if ($employee === null) {
                        $fail(__('diagnostic-reports.validation.performer_employee_not_found'));

                        return;
                    }

                    if ($employee->legalEntityId !== legalEntity()->id) {
                        $fail(__('diagnostic-reports.validation.performer_wrong_legal_entity', ['employee' => $value,]));

                        return;
                    }

                    if ($employee->status !== Status::APPROVED) {
                        $fail(__('diagnostic-reports.validation.performer_invalid_status'));

                        return;
                    }

                    if (!in_array($employee->employeeType, [Role::DOCTOR->value, Role::SPECIALIST->value, Role::ASSISTANT->value, Role::LABORANT->value,], true)) {
                        $fail(__('diagnostic-reports.validation.performer_employee_invalid_type'));
                    }
                },
            ],
            'diagnosticReport.resultsInterpreterEmployeeId' => [
                Rule::requiredIf(
                    in_array(
                        data_get($this->diagnosticReport, 'categoryCode'),
                        self::RESULTS_INTERPRETER_REQUIRED_CATEGORIES,
                        true
                    )
                ),
                'nullable',
                'uuid',
                Rule::exists('employees', 'uuid')->where(
                    static fn ($query) => $query->where('legal_entity_id', legalEntity()->id)
                        ->where('status', Status::APPROVED->value)
                        ->where('is_active', true)
                        ->whereIn('employee_type', [
                            Role::DOCTOR->value,
                            Role::SPECIALIST->value,
                        ])
                )
            ],

            'observations' => ['nullable', 'array'],
            'observations.*.uuid' => ['nullable', 'uuid'],
            'observations.*.categorySystem' => ['required_with:observations', 'string'],
            'observations.*.categoryCode' => [
                'required_with:observations',
                'string',
                new InDictionary(['eHealth/observation_categories', 'eHealth/ICF/observation_categories']),
            ],
            'observations.*.codeSystem' => ['required_with:observations', 'string'],
            'observations.*.codeCode' => [
                'required_with:observations',
                'string',
                new InDictionary([
                    'eHealth/LOINC/observation_codes',
                    'eHealth/custom/observation_codes',
                    'eHealth/ICF/classifiers',
                ]),
            ],

            'observations.*.issuedDate' => ['required_with:observations', 'date', 'before_or_equal:today'],
            'observations.*.issuedTime' => ['required_with:observations', 'date_format:H:i'],
            'observations.*.effectiveDate' => ['nullable', 'date', 'before_or_equal:today'],
            'observations.*.effectiveTime' => ['nullable', 'date_format:H:i'],

            'observations.*.primarySource' => ['required_with:observations', 'boolean'],
            'observations.*.reportOriginCode' => Rule::forEach(function (mixed $value, string $attribute) {
                $index = (int) explode('.', $attribute)[1];
                $primarySource = $this->observations[$index]['primarySource'] ?? true;

                return [
                    Rule::requiredIf($primarySource === false),
                    $primarySource === true ? 'prohibited' : 'nullable',
                    'string',
                    new InDictionary('eHealth/report_origins'),
                ];
            }),
            'observations.*.reportOriginText' => ['nullable', 'string', 'max:255'],
            'observations.*.interpretationCode' => [
                'nullable',
                'string',
                new InDictionary('eHealth/observation_interpretations'),
            ],
            'observations.*.bodySiteCode' => [
                'nullable',
                'string',
                new InDictionary('eHealth/body_sites'),
            ],
            'observations.*.methodCode' => [
                'nullable',
                'string',
                new InDictionary('eHealth/observation_methods'),
            ],
            'observations.*.dictionaryName' => ['nullable', 'string'],
            'observations.*.comment' => ['nullable', 'string', 'max:1000'],

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
            'observations.*.valueDate' => ['nullable', 'date', 'before_or_equal:today'],
            'observations.*.valueTime' => ['nullable', 'date_format:H:i'],

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
        ];
    }
}
