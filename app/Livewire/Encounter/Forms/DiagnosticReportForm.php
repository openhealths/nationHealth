<?php

declare(strict_types=1);

namespace App\Livewire\Encounter\Forms;

use App\Enums\Equipment\AvailabilityStatus;
use App\Enums\Equipment\Status as EquipmentStatus;
use App\Enums\Status;
use App\Enums\User\Role;
use App\Models\Employee\Employee;
use App\Models\Equipment;
use App\Rules\InDictionary;
use App\Rules\MedicalEvents\PaperReferralRules;
use App\Rules\PrimarySourceRequiredForAssistant;
use App\Rules\PastDateTime;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Form;
use Throwable;

class DiagnosticReportForm extends Form
{
    public array $diagnosticReports = [];

    /**
     * Name the fields of a diagnostic report the way the form labels them.
     *
     * @return array
     */
    public function validationAttributes(): array
    {
        $names = __('diagnostic-reports.attributes');
        // A field nested deeper than one record keeps the name it carries for every index
        $attributes = collect($names)
            ->mapWithKeys(static fn (string $name, string $field): array => ["diagnosticReports.*.$field" => $name])
            ->all();

        // Each name carries the diagnostic report number, so an error points to the card it belongs to
        foreach ($this->diagnosticReports as $index => $diagnosticReport) {
            $number = __('diagnostic-reports.position', ['position' => $index + 1]);

            foreach ($names as $field => $name) {
                if (!str_contains($field, '.*.')) {
                    $attributes["diagnosticReports.$index.$field"] = "$name, $number";

                    continue;
                }

                [$nestedProperty, $nestedField] = explode('.*.', $field, 2);

                foreach (array_keys($diagnosticReport[$nestedProperty] ?? []) as $nestedIndex) {
                    $attributes["diagnosticReports.$index.$nestedProperty.$nestedIndex.$nestedField"] = "$name, $number";
                }
            }
        }

        return $attributes;
    }

    protected function rules(): array
    {
        return [
            'diagnosticReports' => ['nullable', 'array'],
            'diagnosticReports.*' => [
                'array',
                function (string $attribute, mixed $value, Closure $fail): void {
                    // The mapper names the writer employee among the performers of a report made here
                    if (data_get($value, 'primarySource') === true) {
                        $writer = Auth::user()
                            ->getEncounterWriterEmployee($this->component->form->encounter['classCode'] ?? null);

                        $this->validatePerformer($writer?->uuid ?? '', $fail);
                    }
                }
            ],
            // for edit page
            'diagnosticReports.*.uuid' => ['nullable', 'uuid'],
            'diagnosticReports.*.categoryCode' => [
                'required_with:diagnosticReports',
                'string',
                new InDictionary('eHealth/diagnostic_report_categories')
            ],
            'diagnosticReports.*.codeValue' => [
                'required_with:diagnosticReports',
                'uuid',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $index = (int) explode('.', $attribute)[1];

                    $categoryCode = data_get($this->diagnosticReports, $index . '.categoryCode');

                    $service = dictionary()
                        ->services()
                        ->flattened()
                        ->firstWhere('id', $value);

                    if ($service === null || data_get($service, 'category') !== $categoryCode) {
                        $fail(
                            __('validation.exists', [
                                'attribute' => __('diagnostic-reports.attributes.codeValue')
                            ])
                        );
                    }
                }
            ],
            'diagnosticReports.*.primarySource' => [
                'required_with:diagnosticReports',
                'boolean',
                new PrimarySourceRequiredForAssistant()
            ],
            'diagnosticReports.*.reportOriginCode' => [
                'required_if:diagnosticReports.*.primarySource,false',
                'prohibited_if:diagnosticReports.*.primarySource,true',
                'string',
                new InDictionary('eHealth/report_origins')
            ],
            'diagnosticReports.*.reportOriginText' => ['nullable', 'string'],
            ...PaperReferralRules::for('diagnosticReports.*', $this->diagnosticReports),
            'diagnosticReports.*.isReferralAvailable' => ['nullable', 'boolean'],
            'diagnosticReports.*.referralType' => Rule::forEach(
                function (mixed $value, string $attribute): array {
                    $index = (int) explode('.', $attribute)[1];
                    $diagnosticReport = $this->diagnosticReports[$index] ?? [];

                    return [
                        Rule::requiredIf(($diagnosticReport['isReferralAvailable'] ?? false) === true),
                        'nullable',
                        Rule::in(['electronic', 'paper'])
                    ];
                }
            ),
            'diagnosticReports.*.basedOnIdentifier' => Rule::forEach(
                function (mixed $value, string $attribute): array {
                    $index = (int) explode('.', $attribute)[1];
                    $diagnosticReport = $this->diagnosticReports[$index] ?? [];
                    $isElectronic = ($diagnosticReport['referralType'] ?? null) === 'electronic';
                    $isPaper = ($diagnosticReport['referralType'] ?? null) === 'paper';

                    return [
                        Rule::requiredIf($isElectronic),
                        Rule::prohibitedIf($isPaper),
                        'nullable',
                        'uuid'
                    ];
                }
            ),
            'diagnosticReports.*.conclusionCode' => [
                'nullable',
                'string',
                new InDictionary('eHealth/ICD10_AM/condition_codes')
            ],
            'diagnosticReports.*.conclusion' => ['nullable', 'string', 'max:3000'],
            'diagnosticReports.*.specimenIds' => ['nullable', 'array'],
            'diagnosticReports.*.specimenIds.*' => [
                'required',
                'uuid',
                'distinct',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $diagnosticReportsWithSpecimen = collect($this->diagnosticReports)
                        ->filter(static fn (array $diagnosticReport): bool => in_array($value, $diagnosticReport['specimenIds'] ?? [], true))
                        ->count();

                    if ($diagnosticReportsWithSpecimen > 1) {
                        $fail(__('specimens.validation.used_in_another_diagnostic_report'));

                        return;
                    }

                    $this->component->specimenForm->validateReference($value, $fail);
                }
            ],
            'diagnosticReports.*.usedReferences' => ['nullable', 'array'],
            'diagnosticReports.*.usedReferences.*.id' => [
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

                    $divisionUuid = data_get($this->component->form->encounter, 'divisionId');

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
            ],
            'diagnosticReports.*.divisionId' => [
                'nullable',
                'uuid',
                Rule::in([data_get($this->component->form->encounter, 'divisionId')])
            ],
            'diagnosticReports.*.performerEmployeeIds' => Rule::forEach(
                function (mixed $value, string $attribute): array {
                    $index = (int) explode('.', $attribute)[1];
                    $diagnosticReport = $this->diagnosticReports[$index] ?? [];

                    return [
                        'nullable',
                        'array',
                        Rule::when(
                            ($diagnosticReport['primarySource'] ?? false) === true
                            && empty($diagnosticReport['resultsInterpreterEmployeeId']),
                            ['min:1']
                        ),
                        static function (string $attribute, mixed $value, Closure $fail): void {
                            $employeeIds = array_values(array_filter((array) $value));

                            if (count($employeeIds) !== count(array_unique($employeeIds))) {
                                $fail(__('validation.distinct', ['attribute' => __('diagnostic-reports.attributes.performerEmployeeIds')]));
                            }
                        }
                    ];
                }
            ),
            'diagnosticReports.*.performerEmployeeIds.*' => [
                'required',
                'uuid',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $this->validatePerformer($value, $fail);
                }
            ],
            'diagnosticReports.*.resultsInterpreterEmployeeId' => Rule::forEach(
                function (mixed $value, string $attribute): array {
                    $index = (int) explode('.', $attribute)[1];

                    $categoryCode = data_get($this->diagnosticReports[$index] ?? [], 'categoryCode');

                    return [
                        Rule::requiredIf(in_array($categoryCode, ['diagnostic_procedure', 'imaging'], true)),
                        'nullable',
                        'uuid',
                        Rule::exists('employees', 'uuid')->where(
                            static fn ($query) => $query->where('legal_entity_id', legalEntity()->id)
                                ->where('status', Status::APPROVED->value)
                                ->where('is_active', true)
                                ->whereIn('employee_type', [Role::DOCTOR->value, Role::SPECIALIST->value])
                        ),
                        // The mapper names the interpreter among the performers too
                        function (string $attribute, mixed $value, Closure $fail): void {
                            if ($value && !$this->isParticipant($value)) {
                                $fail(__('diagnostic-reports.validation.performer_not_participant'));
                            }
                        }
                    ];
                }
            ),
            'diagnosticReports.*.issuedDate' => [
                'required_with:diagnosticReports',
                'date_format:' . config('app.date_format'),
                'before_or_equal:today'
            ],
            'diagnosticReports.*.issuedTime' => Rule::forEach(
                function (mixed $value, string $attribute): array {
                    $index = (int)explode('.', $attribute)[1];
                    $diagnosticReport = $this->diagnosticReports[$index] ?? [];
                    $issuedDate = $diagnosticReport['issuedDate'] ?? '';
                    $effectiveType = $diagnosticReport['effectiveType'] ?? null;

                    return [
                        'required_with:diagnosticReports',
                        'date_format:H:i',
                        new PastDateTime($issuedDate),
                        function (string $attribute, mixed $value, Closure $fail) use (
                            $issuedDate,
                            $effectiveType
                        ): void {
                            $encounter = $this->component->form->encounter;
                            $periodDate = $encounter['periodDate'] ?? '';
                            $periodStart = $encounter['periodStart'] ?? '';
                            $periodEnd = $encounter['periodEnd'] ?? '';

                            if (
                                empty($issuedDate) || empty($value) || empty($periodDate)
                                || empty($periodStart) || empty($periodEnd)
                            ) {
                                return;
                            }

                            try {
                                $format = config('app.date_format') . ' H:i';
                                $issued = CarbonImmutable::createFromFormat($format, $issuedDate . ' ' . $value);
                                $encounterStart = CarbonImmutable::createFromFormat(
                                    $format,
                                    $periodDate . ' ' . $periodStart
                                );
                                $encounterEnd = CarbonImmutable::createFromFormat(
                                    $format,
                                    $periodDate . ' ' . $periodEnd
                                );
                            } catch (Throwable) {
                                return;
                            }

                            if ($issued->lessThan($encounterStart) || $issued->greaterThan($encounterEnd)) {
                                $fail(__('diagnostic-reports.issued_outside_encounter_period'));

                                return;
                            }

                            if ($effectiveType === 'period' && $encounterEnd->greaterThan($issued)) {
                                $fail(__('validation.after_or_equal', [
                                    'date' => __('validation.attributes.encounter_period_end')
                                ]));
                            }
                        }
                    ];
                }
            ),
            'diagnosticReports.*.effectiveType' => ['nullable', Rule::in(['date_time', 'period'])],
            'diagnosticReports.*.effectiveDate' => Rule::forEach(function (mixed $value, string $attribute): array {
                $index = (int) explode('.', $attribute)[1];
                $report = $this->diagnosticReports[$index] ?? [];
                $isDateTime = ($report['effectiveType'] ?? null) === 'date_time';

                return [
                    Rule::requiredIf($isDateTime),
                    Rule::prohibitedIf(!$isDateTime),
                    'nullable',
                    'date_format:' . config('app.date_format'),
                    'before_or_equal:today'
                ];
            }),
            'diagnosticReports.*.effectiveTime' => Rule::forEach(function (mixed $value, string $attribute): array {
                $index = (int) explode('.', $attribute)[1];
                $report = $this->diagnosticReports[$index] ?? [];
                $isDateTime = ($report['effectiveType'] ?? null) === 'date_time';

                return [
                    Rule::requiredIf($isDateTime),
                    Rule::prohibitedIf(!$isDateTime),
                    'nullable',
                    'date_format:H:i',
                    new PastDateTime($report['effectiveDate'] ?? '')
                ];
            }),
            'diagnosticReports.*.effectivePeriodStartDate' => ['nullable'],
            'diagnosticReports.*.effectivePeriodStartTime' => ['nullable'],
            'diagnosticReports.*.effectivePeriodEndDate' => ['nullable'],
            'diagnosticReports.*.effectivePeriodEndTime' => ['nullable']
        ];
    }

    /**
     * A performer has to be an approved employee of this legal entity, allowed to perform diagnostic reports,
     * and taking part in the encounter the report is written in.
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
                'employee_type'
            ]);

        if ($employee === null) {
            $fail(__('diagnostic-reports.validation.performer_employee_not_found'));

            return;
        }

        if ($employee->legalEntityId !== legalEntity()->id) {
            $fail(__('diagnostic-reports.validation.performer_wrong_legal_entity', [
                'employee' => $employeeUuid
            ]));

            return;
        }

        if ($employee->status !== Status::APPROVED) {
            $fail(__('diagnostic-reports.validation.performer_invalid_status'));

            return;
        }

        $allowedEmployeeTypes = config(
            'ehealth.encounter_package_allowed_diagnostic_report_performer_employee_types',
            []
        );

        if (!in_array($employee->employeeType, $allowedEmployeeTypes, true)) {
            $fail(__('diagnostic-reports.validation.performer_employee_invalid_type'));

            return;
        }

        if (!$this->isParticipant($employeeUuid)) {
            $fail(__('diagnostic-reports.validation.performer_not_participant'));
        }
    }

    /**
     * @param  string  $employeeUuid
     * @return bool
     */
    private function isParticipant(string $employeeUuid): bool
    {
        return collect($this->component->form->encounter['participant'] ?? [])->contains(
            static fn (array $participant): bool => ($participant['uuid'] ?? '') === $employeeUuid
        );
    }
}
