<?php

declare(strict_types=1);

namespace App\Livewire\DiagnosticReport\Forms;

use App\Core\BaseForm;
use App\Enums\User\Role;
use App\Rules\AfterOrEqualDateTime;
use App\Rules\InDictionary;
use App\Rules\PastDateTime;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;

class DiagnosticReportForm extends BaseForm
{
    public array $diagnosticReport = [];

    public array $observations = [];

    private const LABORANT_ALLOWED_CATEGORIES = ['laboratory_procedure'];

    private const RESULTS_INTERPRETER_REQUIRED_CATEGORIES = [
        'diagnostic_procedure',
        'imaging',
    ];

    protected function rules(): array
    {
        return [
            'diagnosticReport.referralType' => ['nullable', 'string'],
            'diagnosticReport.primarySource' => ['required', 'boolean:strict'],
            'diagnosticReport.categoryCode' => [
                'required',
                'string',
                new InDictionary('eHealth/diagnostic_report_categories'),
                function (string $attribute, mixed $value, Closure $fail): void {
                    $employeeType = Auth::user()
                        ?->getDiagnosticReportWriterEmployee()
                        ?->employeeType;

                    if (
                        $employeeType === Role::LABORANT->value
                        && !in_array($value, self::LABORANT_ALLOWED_CATEGORIES, true)
                    ) {
                        $fail(__('validation.custom.diagnosticReport.categoryCode.laborant_category'));
                    }
                },
            ],
            'diagnosticReport.codeValue' => [
                'required',
                'uuid',
            ],
            'diagnosticReport.paperReferralRequisition' => ['nullable', 'string', 'max:255'],
            'diagnosticReport.paperReferralRequesterEmployeeName' => [
                Rule::requiredIf(
                    data_get($this->diagnosticReport, 'isReferralAvailable') === true
                    && data_get($this->diagnosticReport, 'referralType') === 'paper'
                ),
                'nullable',
                'string',
                'max:255',
            ],
            'diagnosticReport.paperReferralRequesterLegalEntityEdrpou' => [
                Rule::requiredIf(
                    data_get($this->diagnosticReport, 'isReferralAvailable') === true
                    && data_get($this->diagnosticReport, 'referralType') === 'paper'
                ),
                'nullable',
                'digits_between:8,10',
            ],
            'diagnosticReport.paperReferralRequesterLegalEntityName' => [
                'nullable',
                'string',
                'max:255',
            ],
            'diagnosticReport.paperReferralServiceRequestDate' => [
                Rule::requiredIf(
                    data_get($this->diagnosticReport, 'isReferralAvailable') === true
                    && data_get($this->diagnosticReport, 'referralType') === 'paper'
                ),
                'nullable',
                'date_format:' . config('app.date_format'),
            ],
            'diagnosticReport.paperReferralNote' => ['nullable', 'string', 'max:255'],
            'diagnosticReport.effectivePeriodStartDate' => [
                'nullable',
                'date_format:' . config('app.date_format'),
                'before_or_equal:today',
            ],
            'diagnosticReport.effectivePeriodStartTime' => [
                'nullable',
                'date_format:H:i',
                new PastDateTime(data_get($this->diagnosticReport, 'effectivePeriodStartDate', '')),
            ],
            'diagnosticReport.effectivePeriodEndDate' => [
                'nullable',
                'date_format:' . config('app.date_format'),
                'before_or_equal:today',
                'after_or_equal:diagnosticReport.effectivePeriodStartDate',
            ],
            'diagnosticReport.effectivePeriodEndTime' => [
                'nullable',
                'date_format:H:i',
                new PastDateTime(data_get($this->diagnosticReport, 'effectivePeriodEndDate', '')),
                new AfterOrEqualDateTime(
                    data_get($this->diagnosticReport, 'effectivePeriodEndDate', ''),
                    data_get($this->diagnosticReport, 'effectivePeriodStartDate', ''),
                    data_get($this->diagnosticReport, 'effectivePeriodStartTime', '')
                ),
                function (string $attribute, mixed $value, Closure $fail) {
                    $issuedDate = data_get($this->diagnosticReport, 'issuedDate');
                    $issuedTime = data_get($this->diagnosticReport, 'issuedTime');
                    $endDate = data_get($this->diagnosticReport, 'effectivePeriodEndDate');

                    if (empty($issuedDate) || empty($issuedTime) || empty($endDate) || empty($value)) {
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
                        $fail(__('validation.before_or_equal', [
                            'date' => __('validation.attributes.issued'),
                        ]));
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
                Rule::requiredIf(function () {
                    return in_array(
                        data_get($this->diagnosticReport, 'categoryCode'),
                        ['diagnostic_procedure', 'imaging'],
                        true
                    );
                }),
                'nullable',
                'string',
                'max:1000',
            ],
            'diagnosticReport.usedReferences' => ['nullable', 'array'],
            'diagnosticReport.usedReferences.*.id' => [
                'nullable',
                'uuid',
                'distinct',
                Rule::exists('equipments', 'uuid')->where('legal_entity_id', legalEntity()->id),
            ],
            'diagnosticReport.divisionId' => ['nullable', 'uuid'],
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
            'observations.*.valueQuantitySystem' => ['nullable', 'string'],
            'observations.*.valueQuantityCode' => ['nullable', 'string'],

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
                new InDictionary('eHealth/observation_interpretations'),
            ],
        ];
    }
}
