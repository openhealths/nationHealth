<?php

declare(strict_types=1);

namespace App\Livewire\Encounter;

use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\Exceptions\ApiException as eHealthApiException;
use App\Classes\eHealth\Api\ServiceRequestApi;
use App\Core\Arr;
use App\Enums\Equipment\AvailabilityStatus;
use App\Enums\Person\ClinicalImpressionStatus;
use App\Enums\Person\EpisodeStatus;
use App\Enums\Person\ObservationStatus;
use App\Enums\Status;
use App\Enums\User\Role;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Livewire\Encounter\Forms\Api\EncounterRequestApi;
use App\Models\Employee\Employee;
use App\Models\Icd10;
use App\Models\Person\Person;
use App\Models\Equipment;
use App\Models\User;
use App\Repositories\Repository;
use App\Traits\FormTrait;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;
use App\Livewire\Encounter\Forms\EncounterForm as Form;
use Livewire\WithFileUploads;

class EncounterComponent extends Component
{
    use FormTrait;
    use WithFileUploads;

    public Form $form;

    public bool $showSignatureModal = false;

    /**
     * ID of the patient for which create an encounter.
     *
     * @var int
     */
    #[Locked]
    public int $personId;

    /**
     * Patient full name.
     *
     * @var string
     */
    public string $patientFullName;

    /**
     * List of authorized user's divisions.
     *
     * @var array
     */
    public array $divisions;

    /**
     * List of existing patient episodes.
     *
     * @var array
     */
    public array $episodes = [];

    /**
     * List of existing patient clinical impressions.
     *
     * @var array
     */
    public array $clinicalImpressions = [];

    /**
     * List of found encounters, procedures, or diagnostic reports for clinical impression supporting info.
     *
     * @var array
     */
    public array $supportingInfoResults = [];

    /**
     * Episode type, new or existing.
     *
     * @var string
     */
    public string $episodeType = 'new';

    /**
     * Full name of employee.
     *
     * @var string
     */
    public string $employeeFullName;

    /**
     * Patient UUID for API requests.
     *
     * @var string
     */
    public string $patientUuid;

    /**
     * Legal entity type of auth user.
     *
     * @var string
     */
    protected string $legalEntityType;

    /**
     * Role of auth user.
     *
     * @var string
     */
    protected string $role;

    /**
     * Found the ICD-10 code and description.
     *
     * @var array
     */
    public array $results;

    /**
     * List of LOINC observation codes per category.
     *
     * @var array
     */
    public array $observationLoincCodeMap;

    /**
     * List of custom observation codes per category.
     *
     * @var array
     */
    public array $observationCustomCodeMap;

    /**
     * List of observation values and type of data for specific categories.
     *
     * @var array
     */
    public array $observationValueMap;

    /**
     * Allowed condition codes per code system for the current user, based on employee type and speciality.
     * Key absent = no restriction; key present with empty array = system forbidden; key present with codes = allowed codes.
     *
     * @var array
     */
    public array $allowedConditionCodesBySystem = [];

    /**
     * List of values for codeable concept.
     *
     * @var array
     */
    public array $codeableConceptValues;

    /**
     * List of employees of current legal entity.
     *
     * @var array
     */
    public array $employees;

    /**
     * List of founded conditions and observations.
     *
     * @var array
     */
    public array $evidenceDetails = [];

    /**
     * List of founded conditions and observations.
     *
     * @var array
     */
    public array $conditionsAndObservations = [];

    /**
     * List of founded conditions or observations for clinical impression findings.
     *
     * @var array
     */
    public array $findingResults = [];

    /**
     * List of founded conditions or observations for procedure reason references.
     *
     * @var array
     */
    public array $reasonReferenceResults = [];

    /**
     * List of founded problems for current episode.
     *
     * @var array
     */
    public array $problems = [];

    /**
     * List of equipment options for combobox.
     *
     * @var array
     */
    public array $equipmentOptions = [];

    /**
     * List of equipment options by division for combobox.
     *
     * @var array
     */
    public array $equipmentOptionsByDivision = [];

    /**
     * List of dictionary names.
     *
     * @var array|string[]
     */
    protected array $dictionaryNames = [
        'eHealth/encounter_statuses',
        'eHealth/encounter_classes',
        'eHealth/encounter_types',
        'eHealth/encounter_priority',
        'eHealth/episode_types',
        'eHealth/ICPC2/condition_codes',
        'eHealth/ICPC2/reasons',
        'eHealth/ICPC2/actions',
        'eHealth/diagnosis_roles',
        'eHealth/condition_clinical_statuses',
        'eHealth/condition_verification_statuses',
        'eHealth/condition_severities',
        'eHealth/report_origins',
        'eHealth/reason_explanations',
        'eHealth/reason_not_given_explanations',
        'eHealth/immunization_report_origins',
        'eHealth/vaccine_codes',
        'eHealth/immunization_dosage_units',
        'eHealth/vaccination_routes',
        'eHealth/immunization_body_sites',
        'eHealth/vaccination_authorities',
        'eHealth/vaccination_target_diseases',
        'eHealth/observation_categories',
        'eHealth/ICF/observation_categories',
        'eHealth/LOINC/observation_codes',
        'eHealth/custom/observation_codes',
        'GENDER',
        'eHealth/ICF/qualifiers',
        'eHealth/ICF/qualifiers/extent_or_magnitude_of_impairment',
        'eHealth/ICF/qualifiers/nature_of_change_in_body_structure',
        'eHealth/ICF/qualifiers/anatomical_localization',
        'eHealth/ICF/qualifiers/performance',
        'eHealth/ICF/qualifiers/capacity',
        'eHealth/ICF/qualifiers/barrier_or_facilitator',
        'eHealth/observation_methods',
        'eHealth/observation_interpretations',
        'eHealth/body_sites',
        'eHealth/ucum/units',
        'eHealth/diagnostic_report_categories',
        'eHealth/procedure_categories',
        'eHealth/procedure_outcomes',
        'eHealth/clinical_impression_patient_categories',
        'POSITION'
    ];

    public function boot(): void
    {
        $icd10Cache = $this->dictionaries['eHealth/ICD10_AM/condition_codes'] ?? [];

        $observationConfigRepository = Repository::observationConfig();

        $this->dictionaryNames = [
            ...$this->dictionaryNames,
            ...$observationConfigRepository->codeableConceptBindings()
        ];

        $this->getDictionary();

        $this->dictionaries['eHealth/ICD10_AM/condition_codes'] = $icd10Cache;

        $this->observationLoincCodeMap = $observationConfigRepository->loincCodeMap();
        $this->observationCustomCodeMap = $observationConfigRepository->customCodeMap();
        $this->observationValueMap = $observationConfigRepository->valueMap();

        $this->loadCustomDictionaries();

        $this->codeableConceptValues = collect($this->observationValueMap)
            ->filter(static fn (array $value) => $value[1] === 'valueCodeableConcept')
            ->mapWithKeys(fn (array $value) => [
                $value[0] => $this->dictionaries[$value[0]] ?? [],
            ])
            ->toArray();

        $this->legalEntityType = legalEntity()->type->name;
        $this->role = Auth::user()->roles->first()->name;

        $this->adjustEpisodeTypes();
        $this->adjustEncounterClasses();
        $this->adjustEncounterTypes();
    }

    /**
     * Search for referral number.
     *
     * @return void
     * @throws eHealthApiException
     */
    public function searchForReferralNumber(): void
    {
        $buildSearchRequest = EncounterRequestApi::buildGetServiceRequestList($this->form->referralNumber);
        ServiceRequestApi::searchForServiceRequestsByParams($buildSearchRequest);
    }

    /**
     * Batch-fetch ICD-10 descriptions for given codes into $results.
     * Used by Alpine init() to populate icd10Descriptions without blocking the UI.
     *
     * @param  array  $codes
     * @return void
     */
    public function fetchIcd10Descriptions(array $codes): void
    {
        $this->results = Icd10::whereIn('code', $codes)
            ->get(['code', 'description'])
            ->toArray();
    }

    /**
     * Search for ICD-10 in DB by the provided value.
     *
     * @param  string  $value
     * @return void
     */
    public function searchICD10(string $value): void
    {
        $query = Icd10::search($value)->limit(50);

        $allowedCodes = $this->allowedConditionCodesBySystem['eHealth/ICD10_AM/condition_codes'] ?? null;
        if ($allowedCodes !== null) {
            $query->whereIn('code', $allowedCodes);
        }

        $this->results = $query->get(['code', 'description'])->toArray();
    }

    /**
     * Initialize the component data based on the patient ID.
     *
     * @param  int  $personId
     * @return void
     */
    protected function initializeComponent(int $personId): void
    {
        $authUser = Auth::user();

        $employees = $authUser->party->employees()
            ->whereEmployeeType(Role::DOCTOR)
            ->select(['uuid', 'position', 'party_id'])
            ->with('party:id,last_name,first_name,second_name')
            ->whereLegalEntityId(legalEntity()->id)
            ->get();
        $this->employees = $employees->map(function (Employee $employee) {
            return [
                'uuid' => $employee->uuid,
                'name' => $employee->fullName,
                'position' => $employee->position
            ];
        })->toArray();

        $this->personId = $personId;
        $this->legalEntityType = legalEntity()->type->name;
        $this->role = $authUser->roles->first()->name;
        $this->divisions = legalEntity()->divisions()->whereStatus(Status::ACTIVE)->get()->toArray();

        $this->logEncounterScopeDebugInfo($authUser);

        $encounterWriterEmployee = $authUser->getEncounterWriterEmployee();
        $this->employeeFullName = $encounterWriterEmployee->fullName;
        $this->allowedConditionCodesBySystem = $this->computeAllowedConditionCodesBySystem($encounterWriterEmployee);

        $this->equipmentOptions = Equipment::query()
            ->where('legal_entity_id', legalEntity()->id)
            ->where('availability_status', AvailabilityStatus::AVAILABLE)
            ->active()
            ->with(['names', 'division:id,uuid'])
            ->get()
            ->map(static fn (Equipment $equipment) => [
                'uuid' => $equipment->uuid,
                'name' => $equipment->names->first()?->name ?? $equipment->uuid,
                'divisionUuid' => $equipment->division?->uuid,
            ])
            ->values()
            ->toArray();

        $this->equipmentOptionsByDivision = collect($this->equipmentOptions)
            ->filter(static fn (array $equipment) => !empty($equipment['divisionUuid']))
            ->groupBy('divisionUuid')
            ->map(static fn ($items) => $items->values()->toArray())
            ->toArray();

        $this->setPatientData();

        // set division ID if only one exist
        if (count($this->divisions) === 1) {
            $this->form->encounter['divisionId'] = $this->divisions[0]['uuid'];
        }

        $this->getEpisodes();
    }

    /**
     * Search for conditions or observations by type.
     * Used for: evidence details (condition modal), reason references (procedure modal).
     *
     * @param  string  $type  'condition' or 'observation'
     * @return void
     */
    public function searchConditionsOrObservations(string $type): void
    {
        try {
            $this->evidenceDetails = $this->fetchConditionsOrObservations($type);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while getting evidence details');
        }
    }

    /**
     * Search conditions or observations to use as clinical impression findings.
     *
     * @param  string  $type  'condition' or 'observation'
     * @return void
     */
    public function searchFindings(string $type): void
    {
        try {
            $this->findingResults = $this->fetchConditionsOrObservations($type);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while getting findings');
        }
    }

    /**
     * Search conditions or observations to use as procedure reason references.
     *
     * @param  string  $type  'condition' or 'observation'
     * @return void
     */
    public function searchReasonReferences(string $type): void
    {
        try {
            $this->reasonReferenceResults = $this->fetchConditionsOrObservations($type);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while getting reason references');
        }
    }

    /**
     * @param  string  $type  'condition' or 'observation'
     * @return array
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    private function fetchConditionsOrObservations(string $type): array
    {
        $api = $type === 'observation' ? EHealth::observation() : EHealth::condition();

        $response = $api->getBySearchParams(
            $this->patientUuid,
            ['managing_organization_id' => legalEntity()->uuid]
        );

        $results = collect($response->validate())
            ->when($type === 'observation', fn ($collection) => $collection->filter(
                static fn (array $item) => data_get($item, 'status') !== ObservationStatus::ENTERED_IN_ERROR->value
            ))
            ->map(static fn (array $item) => [
                'id' => data_get($item, 'uuid'),
                'ehealthInsertedAt' => convertToAppDateFormat(data_get($item, 'ehealth_inserted_at')),
                'codeCode' => data_get($item, 'code.coding.0.code'),
                'codeSystem' => data_get($item, 'code.coding.0.system'),
                'type' => $type
            ])
            ->values()
            ->all();

        $this->loadIcd10Descriptions($results);

        return $results;
    }

    /**
     * Search for clinical impressions in episodes.
     *
     * @return void
     */
    public function searchClinicalImpressions(): void
    {
        if (!empty($this->clinicalImpressions)) {
            return;
        }

        try {
            $this->clinicalImpressions = collect(
                EHealth::clinicalImpression()->getSummary(
                    $this->patientUuid,
                    ['status' => ClinicalImpressionStatus::COMPLETED->value]
                )->validate()
            )->map(static function (array $item) {
                $item = Arr::toCamelCase($item);
                $item['ehealthInsertedAt'] = convertToAppDateFormat($item['ehealthInsertedAt'] ?? null);

                return $item;
            })->all();
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while getting clinical impressions');

            return;
        }
    }

    /**
     * Search for complication details in conditions for selected episode.
     *
     * @return void
     */
    public function searchProblems(): void
    {
        if (!empty($this->problems)) {
            return;
        }

        try {
            $this->problems = collect(
                EHealth::condition()->getBySearchParams(
                    $this->patientUuid,
                    ['managing_organization_id' => legalEntity()->uuid]
                )->validate()
            )->map(static fn (array $item) => [
                'id' => data_get($item, 'uuid'),
                'ehealthInsertedAt' => convertToAppDateFormat(data_get($item, 'ehealth_inserted_at')),
                'codeCode' => data_get($item, 'code.coding.0.code'),
                'codeSystem' => data_get($item, 'code.coding.0.system')
            ])
                ->values()
                ->all();

            $this->loadIcd10Descriptions($this->problems);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while searching for problems');
        }
    }

    /**
     * @param  string  $type  One of: episodes, encounter, procedure, diagnostic_report.
     * @return void
     */
    public function searchSupportingInfo(string $type): void
    {
        try {
            $params = ['managing_organization_id' => legalEntity()->uuid];

            $this->supportingInfoResults = match ($type) {
                'episodes' => collect($this->episodes)
                    ->map(fn (array $episode) => [
                        'uuid' => data_get($episode, 'uuid'),
                        'ehealthInsertedAt' => convertToAppDateFormat(data_get($episode, 'ehealthInsertedAt')),
                        'code' => data_get($episode, 'name'),
                        'type' => 'episode_of_care'
                    ])
                    ->values()
                    ->all(),
                'encounter' => collect(EHealth::encounter()->getBySearchParams($this->patientUuid, $params)->validate())
                    ->map(function (array $encounter) {
                        $primaryDiagnosis = collect(data_get($encounter, 'diagnoses', []))
                            ->first(fn (array $diagnosis) => data_get($diagnosis, 'role.coding.0.code') === 'primary');

                        return [
                            'uuid' => data_get($encounter, 'uuid'),
                            'ehealthInsertedAt' => convertToAppDateFormat(data_get($encounter, 'ehealth_inserted_at')),
                            'code' => data_get($primaryDiagnosis, 'code.coding.0.code'),
                            'type' => 'encounter'
                        ];
                    })
                    ->values()
                    ->all(),
                'procedure' => collect(EHealth::procedure()->getBySearchParams($this->patientUuid, $params)->validate())
                    ->map(fn (array $procedure) => [
                        'uuid' => data_get($procedure, 'uuid'),
                        'ehealthInsertedAt' => convertToAppDateFormat(data_get($procedure, 'ehealth_inserted_at')),
                        'code' => data_get($procedure, 'code.identifier.value'),
                        'type' => 'procedure'
                    ])
                    ->values()
                    ->all(),
                'diagnosticReport' => collect(
                    EHealth::diagnosticReport()->getBySearchParams($this->patientUuid, $params)->validate()
                )
                    ->map(fn (array $report) => [
                        'uuid' => data_get($report, 'uuid'),
                        'ehealthInsertedAt' => convertToAppDateFormat(data_get($report, 'ehealth_inserted_at')),
                        'code' => data_get($report, 'code.identifier.value'),
                        'type' => 'diagnostic_report'
                    ])
                    ->values()
                    ->all(),
                'condition' => collect(EHealth::condition()->getBySearchParams($this->patientUuid, $params)->validate())
                    ->map(static fn (array $condition) => [
                        'uuid' => data_get($condition, 'uuid'),
                        'ehealthInsertedAt' => convertToAppDateFormat(data_get($condition, 'ehealth_inserted_at')),
                        'code' => data_get($condition, 'code.coding.0.code'),
                        'type' => 'condition'
                    ])
                    ->values()
                    ->all(),
                'observation' => collect(EHealth::observation()->getBySearchParams($this->patientUuid, $params)->validate())
                    ->filter(static fn (array $observation) => data_get($observation, 'status') !== ObservationStatus::ENTERED_IN_ERROR->value)
                    ->map(static fn (array $observation) => [
                        'uuid' => data_get($observation, 'uuid'),
                        'ehealthInsertedAt' => convertToAppDateFormat(data_get($observation, 'ehealth_inserted_at')),
                        'code' => data_get($observation, 'code.coding.0.code'),
                        'type' => 'observation'
                    ])
                    ->values()
                    ->all(),
                default => []
            };
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle("Error while searching for $type in Encounter Component");
        }
    }

    protected function setPatientData(): void
    {
        $patient = Person::select(['uuid', 'first_name', 'last_name', 'second_name'])
            ->whereId($this->personId)
            ->firstOrFail();

        $this->patientUuid = $patient->uuid;
        $this->patientFullName = $patient->fullName;
    }

    /**
     * Adjust episode types according to legal entity type and employee type.
     *
     * @return void
     */
    protected function adjustEpisodeTypes(): void
    {
        $keys = $this->getFilteredKeysFromConfig(
            "legal_entity_episode_types.$this->legalEntityType",
            "employee_episode_types.$this->role"
        );

        $this->adjustDictionary('eHealth/episode_types', $keys);
    }

    /**
     * Show encounter classes based on legal entity and employee type.
     *
     * @return void
     */
    protected function adjustEncounterClasses(): void
    {
        $keys = $this->getFilteredKeysFromConfig(
            "legal_entity_encounter_classes.$this->legalEntityType",
            "performer_employee_encounter_classes.$this->role"
        );

        $this->adjustDictionary('eHealth/encounter_classes', $keys);

        // set default encounter class, if there is only one
        if (count($this->dictionaries['eHealth/encounter_classes']) === 1) {
            $this->form->encounter['classCode'] = array_key_first($this->dictionaries['eHealth/encounter_classes']);
        }
    }

    /**
     * Show encounter types based on encounter class.
     *
     * @return void
     */
    protected function adjustEncounterTypes(): void
    {
        $selectedClass = key($this->dictionaries['eHealth/encounter_classes']);
        $keys = $this->getFilteredKeysFromConfig("encounter_class_encounter_types.$selectedClass");

        $this->adjustDictionary('eHealth/encounter_types', $keys);
    }

    /**
     * Get active episodes for current patient.
     *
     * @return void
     */
    protected function getEpisodes(): void
    {
        try {
            $this->episodes = EHealth::episode()
                ->getBySearchParams(
                    $this->patientUuid,
                    ['managing_organization_id' => legalEntity()->uuid, 'status' => EpisodeStatus::ACTIVE->value]
                )
                ->validate();
            $this->episodes = Arr::toCamelCase($this->episodes);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when getting episodes');

            return;
        }
    }

    /**
     * Load dictionaries that are not part of the standard eHealth basic dictionary list.
     *
     * @return void
     */
    protected function loadCustomDictionaries(): void
    {
        $basics = dictionary()->basics();

        $this->dictionaries['eHealth/ICF/classifiers'] = $basics->byName('eHealth/ICF/classifiers')
            ->flattenedChildValues()
            ->toArray();
        $this->dictionaries['eHealth/assistive_products'] = $basics->byName('eHealth/assistive_products')
            ->flattenedChildValues(true, true)
            ->toArray();
        $this->dictionaries['custom/services'] = dictionary()->services()->flattened()->toArray();

        $ruleEngineRules = dictionary()->ruleEngineRules();
        $this->dictionaries['custom/rule_engine_rule_list'] = $ruleEngineRules->ruleList();
        $this->dictionaries['custom/rule_engine_details'] = $ruleEngineRules->details();
    }

    /**
     * Log the current user's roles and the eHealth scopes required to create/manage encounters.
     * Helps diagnose "Помилка eHealth API" 403 responses caused by missing OAuth scopes
     * (e.g. encounter:write, episode:write, episode:read, patient_summary:read) without
     * having to inspect the database or the e_health_errors log directly.
     *
     * @param  User  $authUser
     * @return void
     */
    private function logEncounterScopeDebugInfo(User $authUser): void
    {
        $requiredPermissions = ['encounter:write', 'episode:write', 'episode:read', 'patient_summary:read', 'person:read'];

        logger()->debug('[Encounter] Opening encounter form - permission snapshot', [
            'user_id' => $authUser->id,
            'legal_entity_id' => legalEntity()->id,
            'legal_entity_type' => $this->legalEntityType,
            'assigned_roles' => $authUser->roles->pluck('name')->all(),
            'permissions' => collect($requiredPermissions)
                ->mapWithKeys(fn (string $permission) => [$permission => $authUser->can($permission)])
                ->all(),
        ]);
    }

    /**
     * Compute allowed condition codes per code system for the current user.
     * Key absent means no restriction; empty array means the system is forbidden; non-empty array lists the allowed codes.
     * Combines employee-type restrictions with officio-speciality restrictions, intersecting ICD-10 AM when both apply.
     *
     * @param  Employee  $employee
     * @return array
     */
    private function computeAllowedConditionCodesBySystem(Employee $employee): array
    {
        $employeeTypeRestrictions = config("ehealth.employee_type_conditions_allowed.$employee->employeeType");

        $speciality = $employee->loadMissing('specialities')
            ->specialities
            ->firstWhere('speciality_officio', true)
            ?->speciality;
        $specialityIcd10Codes = $speciality
            ? config("ehealth.icd10am_speciality_conditions_allowed.$speciality")
            : null;

        $result = [];
        $icd10Key = 'eHealth/ICD10_AM/condition_codes';
        $icpc2Key = 'eHealth/ICPC2/condition_codes';

        $employeeIcd10Codes = $employeeTypeRestrictions !== null
            ? ($employeeTypeRestrictions[$icd10Key] ?? [])
            : null;

        if ($employeeIcd10Codes !== null && $specialityIcd10Codes !== null) {
            $result[$icd10Key] = array_values(array_intersect($employeeIcd10Codes, $specialityIcd10Codes));
        } elseif ($employeeIcd10Codes !== null) {
            $result[$icd10Key] = $employeeIcd10Codes;
        } elseif ($specialityIcd10Codes !== null) {
            $result[$icd10Key] = $specialityIcd10Codes;
        }

        if ($employeeTypeRestrictions !== null) {
            $result[$icpc2Key] = $employeeTypeRestrictions[$icpc2Key] ?? [];
        }

        return $result;
    }

    /**
     * Adjust dictionaries by provided key and values.
     */
    private function adjustDictionary(string $dictionaryKey, array $allowedValues): void
    {
        $this->dictionaries[$dictionaryKey] = Arr::only($this->dictionaries[$dictionaryKey], $allowedValues);
    }
}
