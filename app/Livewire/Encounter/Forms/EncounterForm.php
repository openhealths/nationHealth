<?php

declare(strict_types=1);

namespace App\Livewire\Encounter\Forms;

use App\Core\BaseForm;
use App\Enums\Status;
use App\Models\LegalEntity;
use App\Rules\InDictionary;
use App\Rules\OnlyOnePrimaryDiagnosis;
use App\Rules\PastDateTime;
use App\Models\Employee\Employee;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

class EncounterForm extends BaseForm
{
    public array $encounter = [
        'classCode' => '',
        'typeCode' => '',
        'diagnoses' => [],
        'reasons' => [],
        'actions' => [],
        'referralType' => '',
        'hospitalization' => [],
        'actionReferences' => [],
        'participant' => [],
        'supportingInfo' => []
    ];

    public array $episode = ['id' => '', 'typeCode' => '', 'name' => ''];

    /**
     * Name the hospitalization fields the way the form labels them.
     *
     * @return array
     */
    public function validationAttributes(): array
    {
        return [
            'encounter.hospitalization.admitSource' => __('encounters.hospitalization.admit_source'),
            'encounter.hospitalization.reAdmission' => __('encounters.hospitalization.re_admission'),
            'encounter.hospitalization.preAdmissionIdentifier' => __('encounters.hospitalization.pre_admission_identifier'),
            'encounter.hospitalization.destination' => __('encounters.hospitalization.destination'),
            'encounter.hospitalization.dischargeDisposition' => __('encounters.hospitalization.discharge_disposition'),
            'encounter.hospitalization.dischargeDepartment' => __('encounters.hospitalization.discharge_department')
        ];
    }

    protected function rules(): array
    {
        $conditions = $this->component->conditionForm->conditions;

        return [
            'encounter.periodDate' => ['required', 'date', 'before_or_equal:today'],
            'encounter.periodStart' => [
                'required',
                'date_format:H:i',
                new PastDateTime($this->encounter['periodDate'])
            ],
            'encounter.periodEnd' => [
                'required',
                'date_format:H:i',
                'after:encounter.periodStart',
                new PastDateTime($this->encounter['periodDate']),
            ],
            'encounter.classCode' => [
                'required',
                'string',
                new InDictionary('eHealth/encounter_classes'),
                $this->classAllowedForEpisodeType(),
                $this->classAllowedForLegalEntity(),
                Rule::when($this->component->isDischarge, [Rule::in(['INPATIENT'])]),
                Rule::when($this->component->isHospitalizationRefusal, [Rule::in(['AMB'])])
            ],
            'encounter.typeCode' => [
                'required',
                'string',
                new InDictionary('eHealth/encounter_types'),
                $this->typeAllowedForClass(),
                $this->patientIdentityObservationCodes(),
                Rule::when($this->component->isDischarge, [Rule::in(['discharge'])]),
                Rule::when($this->component->isHospitalizationRefusal, [Rule::in(['service_delivery_location'])])
            ],
            'encounter.performerId' => ['required', 'uuid', $this->performerAllowed()],
            'encounter.priorityCode' => [
                Rule::requiredIf(($this->encounter['classCode'] ?? '') === 'INPATIENT'),
                'string',
                new InDictionary('eHealth/encounter_priority')
            ],
            'encounter.reasons' => ['required_if:encounter.classCode,PHC', 'array'],
            'encounter.reasons.*.code' => ['required', 'string', new InDictionary('eHealth/ICPC2/reasons')],
            'encounter.reasons.*.text' => ['nullable', 'string'],
            'encounter.diagnoses' => [
                'required_unless:encounter.typeCode,intervention',
                'array',
                new OnlyOnePrimaryDiagnosis($this->encounter['classCode'] ?? null, $conditions),
                $this->diagnosisCodeInSingleRole($conditions)
            ],
            'encounter.diagnoses.*.roleCode' => [
                // The conditions live in their own form, so this cannot lean on required_with
                Rule::requiredIf($conditions !== []),
                'string',
                new InDictionary('eHealth/diagnosis_roles')
            ],
            'encounter.diagnoses.*.rank' => ['nullable', 'integer', 'min:1', 'max:10'],
            'encounter.actions' => [
                'required_if:encounter.classCode,PHC',
                'prohibited_unless:encounter.classCode,PHC',
                'array'
            ],
            'encounter.actions.*.code' => ['required', 'string', new InDictionary('eHealth/ICPC2/actions')],
            'encounter.actions.*.text' => ['nullable', 'string'],
            'encounter.divisionId' => [
                Rule::requiredIf(($this->encounter['classCode'] ?? '') === 'INPATIENT'),
                'nullable',
                'uuid',
                Rule::prohibitedIf(in_array($this->encounter['typeCode'] ?? '', ['field', 'home']))
            ],
            'encounter.hospitalization' => ['exclude_unless:encounter.classCode,INPATIENT', 'nullable', 'array'],
            'encounter.hospitalization.admitSource' => [
                'exclude_unless:encounter.classCode,INPATIENT',
                'required_if:encounter.typeCode,discharge',
                'nullable',
                'string',
                new InDictionary('eHealth/encounter_admit_source')
            ],
            'encounter.hospitalization.reAdmission' => [
                'exclude_unless:encounter.classCode,INPATIENT',
                'nullable',
                'string',
                new InDictionary('eHealth/encounter_re_admission')
            ],
            'encounter.hospitalization.preAdmissionIdentifier' => [
                'exclude_unless:encounter.classCode,INPATIENT',
                'nullable',
                'string',
                'max:255'
            ],
            'encounter.hospitalization.destination' => [
                'exclude_unless:encounter.classCode,INPATIENT',
                'required_if:encounter.hospitalization.dischargeDisposition,transfer_general',
                'nullable',
                'uuid',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (!LegalEntity::active()->whereUuid($value)->exists()) {
                        $fail(__('validation.custom.encounter.hospitalization.destination_not_active'));
                    }
                }
            ],
            'encounter.hospitalization.dischargeDisposition' => [
                'exclude_unless:encounter.classCode,INPATIENT',
                'required_if:encounter.typeCode,discharge',
                'nullable',
                'string',
                new InDictionary('eHealth/encounter_discharge_disposition')
            ],
            'encounter.hospitalization.dischargeDepartment' => [
                'exclude_unless:encounter.classCode,INPATIENT',
                'required_if:encounter.typeCode,discharge',
                'nullable',
                'string',
                new InDictionary('eHealth/encounter_discharge_department')
            ],
            'encounter.referralType' => ['nullable', 'string', Rule::in(['', 'electronic', 'paper'])],
            'encounter.referralNumber' => [
                Rule::requiredIf(($this->encounter['referralType'] ?? '') === 'electronic'),
                'nullable',
                'string',
                'max:255'
            ],
            'encounter.paperReferral' => [
                Rule::requiredIf(($this->encounter['referralType'] ?? '') === 'paper'),
                'nullable',
                'array'
            ],
            'encounter.paperReferral.requisition' => ['nullable', 'string', 'max:255'],
            'encounter.paperReferral.requesterLegalEntityName' => ['nullable', 'string', 'max:255'],
            'encounter.paperReferral.requesterLegalEntityEdrpou' => [
                Rule::requiredIf(($this->encounter['referralType'] ?? '') === 'paper'),
                'digits_between:8,10',
                'nullable',
                'string',
                'max:255'
            ],
            'encounter.paperReferral.requesterEmployeeName' => [
                Rule::requiredIf(($this->encounter['referralType'] ?? '') === 'paper'),
                'nullable',
                'string',
                'max:255'
            ],
            'encounter.paperReferral.serviceRequestDate' => [
                Rule::requiredIf(($this->encounter['referralType'] ?? '') === 'paper'),
                'nullable',
                'date'
            ],
            'encounter.paperReferral.note' => ['nullable', 'string', 'max:1000'],
            'encounter.prescriptions' => ['nullable', 'string', 'max:3000'],
            'encounter.actionReferences' => ['nullable', 'array', $this->encounterHasActivity()],
            'encounter.actionReferences.*.uuid' => [
                'nullable',
                'uuid',
                $this->actionReferenceIsAllowedService()
            ],
            'encounter.participant' => [
                'nullable',
                'array',
                Rule::when(($this->encounter['typeCode'] ?? '') === 'concilium', ['min:2'])
            ],
            'encounter.participant.*.uuid' => [
                'nullable',
                'uuid',
                'distinct:strict',
                $this->participantEmployeeAllowed()
            ],
            'encounter.supportingInfo' => ['nullable', 'array'],
            'encounter.supportingInfo.*.uuid' => ['required_with:encounter.supportingInfo', 'uuid'],
            'encounter.supportingInfo.*.type' => [
                'required_with:encounter.supportingInfo',
                'string',
                Rule::in(['condition', 'observation', 'diagnostic_report'])
            ],
            'encounter.supportingInfo.*.code' => ['nullable', 'string'],
            'encounter.supportingInfo.*.name' => ['nullable', 'string'],
            'encounter.supportingInfo.*.date' => ['nullable', 'string'],
            'encounter.supportingInfo.*.typeLabel' => ['nullable', 'string'],

            'episode.id' => [
                'nullable',
                'uuid',
                'required_without_all:episode.typeCode,episode.name',
                Rule::prohibitedIf(
                    !empty($this->episode['typeCode'])
                    || !empty($this->episode['name'])
                    || $this->component->isHospitalizationRefusal
                )
            ],
            'episode.typeCode' => [
                'nullable',
                'string',
                new InDictionary('eHealth/episode_types'),
                'required_without:episode.id',
                Rule::prohibitedIf(!empty($this->episode['id'])),
                $this->episodeTypeAllowedForLegalEntityAndEmployee()
            ],
            'episode.name' => [
                'nullable',
                'string',
                'required_without:episode.id',
                Rule::prohibitedIf(!empty($this->episode['id']))
            ]
        ];
    }

    /**
     * @return array
     */
    protected function messages(): array
    {
        return [
            'encounter.priorityCode.required' => __('validation.custom.encounter.priorityCode.required_if'),
            'encounter.reasons.required_if' => __('validation.custom.encounter.reasons.required_if'),
            'encounter.diagnoses.required_unless' => __('validation.custom.encounter.diagnoses.required_unless'),
            'encounter.divisionId.required' => __('validation.custom.encounter.divisionId.required_if'),
            'encounter.divisionId.prohibited' => __('validation.custom.encounter.divisionId.prohibited'),
            'encounter.hospitalization.admitSource.required_if' => __('validation.custom.encounter.hospitalization.admit_source_required_if'),
            'encounter.hospitalization.destination.required_if' => __('validation.custom.encounter.hospitalization.destination_required_if'),
            'encounter.hospitalization.dischargeDisposition.required_if' => __('validation.custom.encounter.hospitalization.discharge_disposition_required_if'),
            'encounter.hospitalization.dischargeDepartment.required_if' => __('validation.custom.encounter.hospitalization.discharge_department_required_if'),
            'encounter.actions.required_if' => __('validation.custom.encounter.actions.required_if'),
            'encounter.actions.prohibited_unless' => __('validation.custom.encounter.actions.prohibited_unless'),
            'encounter.participant.min' => __('validation.custom.encounter.participant.concilium_min'),
            'encounter.participant.*.uuid.distinct' => __('validation.custom.encounter.participant.unique'),
        ];
    }

    /**
     * The episode type has to be allowed both for the legal entity and for the employee writing the encounter.
     *
     * @return In
     */
    private function episodeTypeAllowedForLegalEntityAndEmployee(): In
    {
        return Rule::in(array_intersect(
            config('ehealth.legal_entity_episode_types')[legalEntity()->type->name],
            config('ehealth.employee_episode_types')[Auth::user()->getEncounterWriterEmployee()->employeeType]
        ));
    }

    /**
     * The same condition code cannot be used as primary, comorbidity and complication diagnoses at once.
     *
     * @param  array  $conditions  Conditions of the package, indexed the same way as the diagnoses
     * @return Closure
     */
    private function diagnosisCodeInSingleRole(array $conditions): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($conditions): void {
            $rolesByCode = [];

            foreach ((array) $value as $index => $diagnosis) {
                $roleCode = $diagnosis['roleCode'] ?? '';
                $codeCode = $conditions[$index]['codeCode'] ?? '';

                if ($codeCode === '' || !in_array($roleCode, ['primary', 'comorbidity', 'complication'], true)) {
                    continue;
                }

                $code = ($conditions[$index]['codeSystem'] ?? '') . ':' . $codeCode;
                $rolesByCode[$code][$roleCode] = true;

                if (count($rolesByCode[$code]) > 1) {
                    $fail(__('conditions.validation.diagnosis_code_in_several_roles', ['code' => $codeCode]));

                    return;
                }
            }
        };
    }

    /**
     * The encounter class has to be allowed for the type of the episode the encounter belongs to.
     *
     * @return Closure
     */
    private function classAllowedForEpisodeType(): Closure
    {
        $encounterClassLabels = $this->component->dictionaries['eHealth/encounter_classes'];

        return function (string $attribute, mixed $value, Closure $fail) use (
            $encounterClassLabels
        ): void {
            $episodeTypeCode = $this->episode['typeCode'] ?? null;

            if (empty($episodeTypeCode) && !empty($this->episode['id'])) {
                $episode = collect($this->component->episodes)->firstWhere('uuid', $this->episode['id']);
                $episodeTypeCode = data_get($episode, 'type.code');
            }

            if (empty($episodeTypeCode)) {
                return;
            }

            $allowed = config("ehealth.episode_type_encounter_classes.$episodeTypeCode", []);
            if (!in_array($value, $allowed, true)) {
                $fail(__('validation.custom.encounter.classCode.episode_type_forbidden', [
                    'value' => $encounterClassLabels[$value]
                ]));
            }
        };
    }

    /**
     * The encounter class has to be allowed for the type of the legal entity.
     *
     * @return Closure
     */
    private function classAllowedForLegalEntity(): Closure
    {
        $encounterClassLabels = $this->component->dictionaries['eHealth/encounter_classes'];

        return static function (string $attribute, mixed $value, Closure $fail) use (
            $encounterClassLabels
        ): void {
            $allowed = config('ehealth.legal_entity_encounter_classes.' . legalEntity()->type->name, []);

            if (!in_array($value, $allowed, true)) {
                $fail(__('validation.custom.encounter.classCode.legal_entity_forbidden', [
                    'value' => $encounterClassLabels[$value]
                ]));
            }
        };
    }

    /**
     * The encounter type has to be allowed for the encounter class.
     *
     * @return Closure
     */
    private function typeAllowedForClass(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $classCode = $this->encounter['classCode'] ?? null;

            if (empty($classCode)) {
                return;
            }

            $classTypes = config("ehealth.encounter_class_encounter_types.$classCode", []);

            if (!in_array($value, $classTypes, true)) {
                $fail(__('validation.custom.encounter.typeCode.class_forbidden', ['value' => $value]));
            }
        };
    }

    /**
     * The performer has to be an active employee of this legal entity whose type allows the encounter class and type.
     * An encounter ending today, or one carrying records registered from primary source, is performed by the auth user.
     *
     * @return Closure
     */
    private function performerAllowed(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $performer = Employee::whereUuid($value)
                ->first([
                    'uuid',
                    'party_id',
                    'legal_entity_id',
                    'status',
                    'is_active',
                    'employee_type'
                ]);

            if ($performer === null) {
                $fail(__('validation.custom.encounter.performer_not_found'));

                return;
            }

            if ($performer->status !== Status::APPROVED || !$performer->isActive) {
                $fail(__('validation.custom.encounter.performer_not_active'));

                return;
            }

            if ($performer->legalEntityId !== legalEntity()->id) {
                $fail(__('validation.custom.encounter.performer_wrong_legal_entity'));

                return;
            }

            $classCode = $this->encounter['classCode'] ?? '';
            $allowedClasses = config("ehealth.performer_employee_encounter_classes.$performer->employeeType", []);

            if ($classCode !== '' && !in_array($classCode, $allowedClasses, true)) {
                $fail(__('validation.custom.encounter.performer_class_forbidden', ['type' => $performer->employeeType]));

                return;
            }

            $typeCode = $this->encounter['typeCode'] ?? '';
            $allowedTypes = config("ehealth.performer_employee_encounter_types.$performer->employeeType", []);

            if ($typeCode !== '' && !in_array($typeCode, $allowedTypes, true)) {
                $fail(__('validation.custom.encounter.performer_type_forbidden', ['type' => $performer->employeeType]));

                return;
            }

            $periodDate = $this->encounter['periodDate'] ?? '';

            if (empty($periodDate)) {
                return;
            }

            $hasPrimarySource = collect([
                $this->component->conditionForm->conditions,
                $this->component->immunizationForm->immunizations,
                $this->component->diagnosticReportForm->diagnosticReports,
                $this->component->observationForm->observations,
                $this->component->procedureForm->procedures
            ])
                ->flatten(1)
                ->contains(static fn (array $record): bool => ($record['primarySource'] ?? false) === true);

            $isToday = CarbonImmutable::createFromFormat(config('app.date_format'), $periodDate)->isToday();

            if (($isToday || $hasPrimarySource) && $performer->partyId !== Auth::user()->partyId) {
                $fail(__('validation.custom.encounter.performer_not_current_user'));
            }
        };
    }

    /**
     * A participant has to be an approved employee of this legal entity, allowed for the encounter type.
     *
     * @return Closure
     */
    private function participantEmployeeAllowed(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (empty($value)) {
                return;
            }

            $employee = Employee::whereUuid($value)
                ->first([
                    'uuid',
                    'legal_entity_id',
                    'status',
                    'employee_type',
                ]);

            if ($employee === null) {
                $fail(__('validation.custom.encounter.participant.employee_not_found'));

                return;
            }

            if ($employee->legalEntityId !== legalEntity()->id) {
                $fail(__('validation.custom.encounter.participant.employee_wrong_legal_entity', ['employee' => $value,]));

                return;
            }

            if ($employee->status !== Status::APPROVED) {
                $fail(__('validation.custom.encounter.participant.employee_invalid_status'));

                return;
            }

            $allowedEmployeeTypes = config('ehealth.encounter_package_allowed_encounter_participant_employee_types');

            if (!in_array($employee->employeeType, $allowedEmployeeTypes, true)) {
                $fail(__('validation.custom.encounter.participant.employee_invalid_type'));

                return;
            }

            $encounterType = $this->encounter['typeCode'] ?? null;

            if ($encounterType === null) {
                return;
            }

            $allowedEmployeeTypesForEncounter = config("ehealth.encounter_type_{$encounterType}_encounter_participant_employee_types_allowed", []);

            if ($allowedEmployeeTypesForEncounter !== [] && !in_array($employee->employeeType, $allowedEmployeeTypesForEncounter, true)) {
                $fail(__('validation.custom.encounter.participant.employee_type_forbidden_for_encounter', ['type' => $employee->employeeType,]));
            }
        };
    }

    /**
     * The encounter has to carry some activity — a counselling action reference, a diagnostic report
     * or a procedure — depending on its class and type.
     *
     * @return Closure
     */
    private function encounterHasActivity(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $type = $this->encounter['typeCode'] ?? null;

            if ($type === 'patient_identity') {
                return;
            }

            $actionReferenceIds = collect($this->encounter['actionReferences'] ?? [])
                ->pluck('uuid')
                ->filter();

            // PHC encounters describe their activity through actions, concilium encounters through participants
            if (($this->encounter['classCode'] ?? null) === 'PHC') {
                if ($actionReferenceIds->isNotEmpty()) {
                    $fail(__('validation.custom.encounter.actionReferences.prohibited_phc'));
                }

                return;
            }

            if ($type === 'concilium') {
                if ($actionReferenceIds->isNotEmpty()) {
                    $fail(__('validation.custom.encounter.actionReferences.prohibited_concilium'));
                }

                return;
            }

            $serviceCategories = dictionary()->services()->flattened()->pluck('category', 'id');

            $hasCounsellingReference = $actionReferenceIds->contains(
                static fn (string $serviceId): bool => $serviceCategories->get($serviceId) === 'counselling'
            );

            if (
                !$hasCounsellingReference
                && empty($this->component->diagnosticReportForm->diagnosticReports)
                && empty($this->component->procedureForm->procedures)
            ) {
                $fail(__('validation.custom.encounter.actionReferences.required_activity'));
            }
        };
    }

    /**
     * A "patient_identity" encounter carries every mandatory observation code and no code beyond the allowed ones.
     *
     * @return Closure
     */
    private function patientIdentityObservationCodes(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value !== 'patient_identity') {
                return;
            }

            $codes = collect($this->component->observationForm->observations)
                ->pluck('codeCode')
                ->filter()
                ->all();

            $missing = array_diff(config('ehealth.preperson_required_observation_codes', []), $codes);

            if ($missing !== []) {
                $fail(__('validation.custom.encounter.observations.patient_identity_required', [
                    'codes' => implode(', ', $missing)
                ]));
            }

            $notAllowed = array_diff($codes, config('ehealth.preperson_allowed_observation_codes', []));

            if ($notAllowed !== []) {
                $fail(__('validation.custom.encounter.observations.patient_identity_not_allowed', [
                    'codes' => implode(', ', array_unique($notAllowed))
                ]));
            }
        };
    }

    /**
     * An action reference points to a service and not to a service group, and the service belongs to the
     * "counselling" category when the encounter class is AMB.
     *
     * @return Closure
     */
    private function actionReferenceIsAllowedService(): Closure
    {
        $isAmbulatory = ($this->encounter['classCode'] ?? null) === 'AMB';

        $serviceCategories = dictionary()->services()->flattened()->pluck('category', 'id');

        return static function (
            string $attribute,
            mixed $value,
            Closure $fail
        ) use ($serviceCategories, $isAmbulatory): void {
            if (empty($value)) {
                return;
            }

            $category = $serviceCategories->get($value);

            // Service groups share the dictionary tree with services but carry no category
            if ($category === null) {
                $fail(__('validation.custom.encounter.actionReferences.service_not_found'));

                return;
            }

            if ($isAmbulatory && $category !== 'counselling') {
                $fail(__('validation.custom.encounter.actionReferences.invalid_amb_category'));
            }
        };
    }

    public function syncParticipants(): void
    {
        $encounterWriterEmployeeUuid = Auth::user()
            ->getEncounterWriterEmployee($this->encounter['classCode'] ?? null)?->uuid;

        $procedurePerformerUuids = collect($this->component->procedureForm->procedures)
            ->filter(static fn (array $procedure): bool => ($procedure['primarySource'] ?? false) === true && !empty($procedure['performerEmployeeId']))
            ->pluck('performerEmployeeId');

        $diagnosticReportPerformerUuids = collect($this->component->diagnosticReportForm->diagnosticReports)
            ->filter(static fn (array $diagnosticReport): bool => ($diagnosticReport['primarySource'] ?? false) === true)
            ->flatMap(
                static fn (array $diagnosticReport): array => array_filter([
                    $diagnosticReport['resultsInterpreterEmployeeId'] ?? null,
                    ...($diagnosticReport['performerEmployeeIds'] ?? []),
                ])
            );

        $requiredParticipantUuids = $procedurePerformerUuids
            ->merge($diagnosticReportPerformerUuids)
            ->push($this->encounter['performerId'] ?? null)
            ->when(
                $encounterWriterEmployeeUuid !== null,
                static fn ($participants) => $participants->push($encounterWriterEmployeeUuid)
            )
            ->filter()
            ->unique()
            ->values();

        $currentParticipants = collect($this->encounter['participant'] ?? []);

        $manualParticipants = $currentParticipants
            ->filter(
                static fn (array $participant): bool =>
                    !empty($participant['uuid'])
                    && ($participant['locked'] ?? false) !== true
                    && !$requiredParticipantUuids->contains($participant['uuid'])
            )
            ->map(
                static fn (array $participant): array => [
                    'uuid' => $participant['uuid'],
                    'locked' => false,
                ]
            );

        $emptyManualParticipant = $currentParticipants
            ->first(
                static fn (array $participant): bool =>
                    empty($participant['uuid'])
                    && ($participant['locked'] ?? false) !== true
            );

        $employeeNames = collect($this->component->employees ?? [])
            ->merge($this->component->diagnosticReportEmployees ?? [])
            ->filter(static fn (array $employee): bool => !empty($employee['uuid']))
            ->pluck('name', 'uuid');

        $automaticParticipants = $requiredParticipantUuids
            ->map(
                static function (string $uuid) use ($employeeNames): array {
                    $name = $employeeNames->get($uuid);

                    return array_filter([
                        'uuid' => $uuid,
                        'name' => is_string($name) && $name !== '' ? $name : null,
                        'locked' => true,
                    ], static fn (mixed $value): bool => $value !== null);
                }
            );

        $participants = $manualParticipants
            ->merge($automaticParticipants)
            ->unique('uuid')
            ->values();

        if ($emptyManualParticipant !== null) {
            $participants->push([
                'uuid' => '',
                'locked' => false,
            ]);
        }

        $this->encounter['participant'] = $participants->toArray();
    }
}
