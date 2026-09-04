<?php

declare(strict_types=1);

namespace App\Livewire\Encounter\Forms;

use App\Core\BaseForm;
use App\Enums\Status;
use App\Rules\InDictionary;
use App\Rules\OnlyOnePrimaryDiagnosis;
use App\Rules\PastDateTime;
use App\Models\Employee\Employee;
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
        'actionReferences' => [],
        'participant' => [],
        'supportingInfo' => []
    ];

    public array $episode = ['id' => '', 'typeCode' => '', 'name' => ''];

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
                $this->classAllowedForLegalEntity()
            ],
            'encounter.typeCode' => [
                'required',
                'string',
                new InDictionary('eHealth/encounter_types'),
                $this->typeAllowedForClassAndRole(),
                $this->patientIdentityObservationCodes()
            ],
            'encounter.priorityCode' => [
                Rule::requiredIf(
                    ($this->encounter['classCode'] ?? '') === 'INPATIENT'
                    && ($this->encounter['typeCode'] ?? '') !== 'patient_identity'
                ),
                'string',
                new InDictionary('eHealth/encounter_priority')
            ],
            'encounter.reasons' => ['required_if:encounter.classCode,PHC', 'array'],
            'encounter.reasons.*.code' => ['required', 'string', new InDictionary('eHealth/ICPC2/reasons')],
            'encounter.reasons.*.text' => ['nullable', 'string'],
            'encounter.diagnoses' => [
                'required_unless:encounter.typeCode,intervention',
                Rule::when(
                    ($this->encounter['typeCode'] ?? '') !== 'intervention',
                    new OnlyOnePrimaryDiagnosis($this->encounter['classCode'] ?? null, $conditions)
                ),
                'array'
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
                Rule::requiredIf(
                    ($this->encounter['classCode'] ?? '') === 'INPATIENT'
                    && ($this->encounter['typeCode'] ?? '') !== 'patient_identity'
                ),
                'nullable',
                'uuid',
                Rule::prohibitedIf(in_array($this->encounter['typeCode'] ?? '', ['field', 'home']))
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
                Rule::prohibitedIf(!empty($this->episode['typeCode']) || !empty($this->episode['name']))
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
     * The encounter type has to be allowed both for the encounter class and for the user's role.
     *
     * @return Closure
     */
    private function typeAllowedForClassAndRole(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $classCode = $this->encounter['classCode'] ?? null;

            if (empty($classCode)) {
                return;
            }

            $classTypes = config("ehealth.encounter_class_encounter_types.$classCode", []);

            if (!in_array($value, $classTypes, true)) {
                $fail(__('validation.custom.encounter.typeCode.class_forbidden', ['value' => $value]));

                return;
            }

            $roleEncounterTypes = Auth::user()->allowedRoles
                ->flatMap(static fn (string $role): array => config("ehealth.performer_employee_encounter_types.$role", []))
                ->unique();

            if (!$roleEncounterTypes->contains($value)) {
                $fail(__('validation.custom.encounter.typeCode.employee_forbidden', ['value' => $value]));
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

        $automaticParticipants = $requiredParticipantUuids
            ->map(
                static fn (string $uuid): array => [
                    'uuid' => $uuid,
                    'locked' => true,
                ]
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
