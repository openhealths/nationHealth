<?php

declare(strict_types=1);

namespace App\Livewire\Encounter;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\Episode\Status as EpisodeStatus;
use App\Enums\Equipment\AvailabilityStatus;
use App\Enums\ClinicalImpression\Status as ClinicalImpressionStatus;
use App\Enums\Person\ImmunizationStatus;
use App\Enums\Person\ServiceRequestStatus;
use App\Enums\Status;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Livewire\Encounter\Forms\ClinicalImpressionForm;
use App\Livewire\Encounter\Forms\DetectedIssueForm;
use App\Livewire\Encounter\Forms\DeviceAssociationForm;
use App\Livewire\Encounter\Forms\DeviceForm;
use App\Livewire\Encounter\Forms\DeviceDispenseForm;
use App\Livewire\Encounter\Forms\ConditionForm;
use App\Livewire\Encounter\Forms\DiagnosticReportForm;
use App\Livewire\Encounter\Forms\ImmunizationForm;
use App\Livewire\Encounter\Forms\ObservationForm;
use App\Livewire\Encounter\Forms\ProcedureForm;
use App\Livewire\Encounter\Forms\SpecimenForm;
use App\Livewire\Encounter\Forms\EncounterForm as Form;
use App\Models\Employee\Employee;
use App\Models\Equipment;
use App\Models\Icd10;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Device;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Immunization;
use App\Models\MedicalEvents\Sql\Specimen;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\MedicalEvents\Sql\EpisodeCurrentDiagnosis;
use App\Repositories\Repository;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use App\Services\MedicalEvents\Fhir;
use App\Services\Dictionary\Mappers\ImmunizationDictionaryMapper;
use App\Services\MedData\MedData;
use App\Traits\FormTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

class EncounterComponent extends Component
{
    use FormTrait;
    use WithFileUploads;

    public Form $form;

    public DeviceAssociationForm $deviceAssociationForm;

    public DeviceForm $deviceForm;

    public DeviceDispenseForm $deviceDispenseForm;

    public SpecimenForm $specimenForm;

    public ClinicalImpressionForm $clinicalImpressionForm;

    public ProcedureForm $procedureForm;

    public ObservationForm $observationForm;

    public DiagnosticReportForm $diagnosticReportForm;

    public ImmunizationForm $immunizationForm;

    public ConditionForm $conditionForm;

    public DetectedIssueForm $detectedIssueForm;

    public bool $showSignatureModal = false;

    public ?string $actionType = null;

    /**
     * Person ID (set when the patient is a person).
     *
     * @var int|null
     */
    #[Locked]
    public ?int $personId = null;

    /**
     * Preperson ID (set when the patient is a preperson).
     *
     * @var int|null
     */
    #[Locked]
    public ?int $prepersonId = null;

    /**
     * Whether the interaction is a patient discharge, which fixes its class and type.
     *
     * @var bool
     */
    #[Locked]
    public bool $isDischarge = false;

    /**
     * Whether the interaction is a hospitalization refusal, which fixes its class and type and opens a new episode.
     *
     * @var bool
     */
    #[Locked]
    public bool $isHospitalizationRefusal = false;

    /**
     * Request-scoped memoized patient model.
     *
     * @var Person|Preperson|null
     */
    private Person|Preperson|null $patientModel = null;

    /**
     * Patient full name.
     *
     * @var string
     */
    public string $patientFullName = '';

    /**
     * List of authorized user's divisions.
     *
     * @var array
     */
    public array $divisions = [];

    /**
     * Names of the active legal entities a hospitalized patient can be sent to after discharge, keyed by UUID.
     *
     * @var array
     */
    public array $destinationLegalEntities = [];

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
     * Episode type, new or existing.
     *
     * @var string
     */
    public string $episodeType = 'new';

    /**
     * Interaction types allowed for each available interaction class, used to filter the type list on the client.
     *
     * @var array
     */
    #[Locked]
    public array $encounterClassTypes = [];

    /**
     * Full name of employee.
     *
     * @var string
     */
    public string $employeeFullName = '';

    /**
     * Patient UUID for API requests. Null for a preperson that is not yet registered in eHealth.
     *
     * @var string|null
     */
    #[Locked]
    public ?string $patientUuid = null;

    /**
     * Processed referrals of the patient the encounter can be based on.
     *
     * @var array
     */
    public array $availableReferrals = [];

    /**
     * Whether the patient's referrals have already been loaded.
     *
     * @var bool
     */
    public bool $referralsLoaded = false;

    /**
     * UUID of the referral picked for the encounter.
     *
     * @var string|null
     */
    public ?string $selectedReferralUuid = null;

    /**
     * Legal entity type of auth user.
     *
     * @var string
     */
    protected string $legalEntityType;

    /**
     * Employee the auth user writes the encounter as.
     *
     * @var Employee|null
     */
    protected ?Employee $encounterWriterEmployee = null;

    /**
     * Employee type of the employee the auth user writes the encounter as.
     *
     * @var string|null
     */
    protected ?string $employeeType = null;

    /**
     * Found the ICD-10 code and description.
     *
     * @var array
     */
    public array $results = [];

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
     * Allowed condition codes per code system for the current user, based on employee type.
     * Key absent = no restriction; key present with empty array = system forbidden; key present with codes = allowed codes.
     *
     * @var array
     */
    #[Locked]
    public array $allowedConditionCodesBySystem = [];

    /**
     * ICD-10 AM condition codes reserved for specialities the current user does not hold.
     *
     * @var array
     */
    #[Locked]
    public array $forbiddenIcd10ConditionCodes = [];

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
    public array $employees = [];

    /**
     * Employees of the auth user the encounter can be performed as.
     *
     * @var array
     */
    public array $performerEmployees = [];

    /**
     * List of founded conditions and observations.
     *
     * @var array
     */
    public array $conditionsAndObservations = [];

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
     * Devices already registered for the patient, offered for association alongside the ones this package adds.
     *
     * @var array
     */
    public array $patientDevices = [];

    /**
     * Specimens already registered for the patient, offered for reference alongside the ones this package adds.
     *
     * @var array
     */
    public array $patientSpecimens = [];

    /**
     * Device requests of the patient a device dispense can be based on.
     *
     * @var array
     */
    public array $deviceRequests = [];

    /**
     * Authenticated user's employee used as device dispense performer.
     *
     * @var array
     */
    public array $deviceDispenseEmployee = [];

    /**
     * Previous detected issues available for selection.
     *
     * @var array
     */
    public array $previousDetectedIssues = [];

    /**
     * List of employees available as diagnostic report performers.
     *
     * @var array
     */
    public array $diagnosticReportEmployees = [];

    /**
     * List of employees available as procedure performers.
     *
     * @var array
     */
    public array $procedureEmployees = [];

    /**
     * Code of the primary diagnosis the selected episode carries at the moment,
     * so that the form can tell the user that a different one is about to replace it.
     *
     * @var string
     */
    #[Locked]
    public string $episodePrimaryDiagnosisCode = '';

    /**
     * eHealth IDs of the package records picked to be marked as entered in error, keyed by package section.
     * Only an encounter that has been signed has records to pick, so on creation these stay empty.
     *
     * @var array
     */
    public array $selectedRecords = self::NO_RECORDS_SELECTED;

    /**
     * eHealth IDs of the package records already marked as entered in error, keyed by package section.
     *
     * @var array
     */
    #[Locked]
    public array $cancelledRecords = self::NO_RECORDS_SELECTED;

    /**
     * Package sections whose records may be marked as entered in error on their own.
     */
    protected const array NO_RECORDS_SELECTED = [
        'observations' => [],
        'immunizations' => [],
        'diagnosticReports' => [],
        'procedures' => [],
        'deviceDispenses' => [],
        'clinicalImpressions' => [],
        'devices' => [],
        'deviceAssociations' => [],
        'detectedIssues' => [],
        'specimens' => []
    ];

    /**
     * Vaccine options prepared for search by code, name and target disease.
     *
     * @var array<int, array{
     *     code: string,
     *     name: string,
     *     targetDiseases: array<int, array{code: string, name: string}>
     * }>
     */
    public array $vaccineOptions = [];

    /**
     * Vaccine lots from MedData prepared for prefilling the immunization form.
     *
     * @var array
     */
    public array $vaccineLots = [];

    /**
     * Completed immunizations of the patient an observation can reference as the one it is a reaction on.
     *
     * @var array
     */
    public array $reactionImmunizations = [];

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
        'eHealth/encounter_admit_source',
        'eHealth/encounter_re_admission',
        'eHealth/encounter_discharge_disposition',
        'eHealth/encounter_discharge_department',
        'eHealth/episode_types',
        'eHealth/ICPC2/condition_codes',
        'eHealth/ICPC2/reasons',
        'eHealth/ICPC2/actions',
        'eHealth/diagnosis_roles',
        'eHealth/condition_clinical_statuses',
        'eHealth/condition_verification_statuses',
        'eHealth/condition_severities',
        'eHealth/condition_stages',
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
        'eHealth/cancellation_reasons',
        'external_system',
        'device_definition_classification_type',
        'device_name_type',
        'device_properties',
        'device_association_statuses',
        'device_dispense_statuses',
        'eHealth/body_structures',
        'detected_issue_statuses',
        'detected_issue_codes',
        'specimen_types',
        'specimen_conditions',
        'specimen_invalidate_reasons',
        'specimen_collection_methods',
        'specimen_container_types',
        'specimen_container_additives',
        'fasting_statuses',
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

        $this->loadVaccineOptions();

        $this->vaccineLots = MedData::vaccineLot()->getLots();

        $this->dictionaries['eHealth/ICD10_AM/condition_codes'] = $icd10Cache;

        $this->observationLoincCodeMap = $observationConfigRepository->loincCodeMap();
        $this->observationCustomCodeMap = $observationConfigRepository->customCodeMap();
        $this->observationValueMap = $observationConfigRepository->valueMap();

        $this->loadCustomDictionaries();

        $this->codeableConceptValues = collect($this->observationValueMap)
            ->filter(static fn (array $value): bool => $value[1] === 'valueCodeableConcept')
            ->mapWithKeys(fn (array $value): array => [
                $value[0] => $this->dictionaries[$value[0]] ?? [],
            ])
            ->toArray();

        $this->legalEntityType = legalEntity()->type->name;
        $this->encounterWriterEmployee = Auth::user()->getEncounterWriterEmployee();
        $this->employeeType = $this->encounterWriterEmployee?->employeeType;

        $this->adjustEpisodeTypes();
        $this->adjustEncounterClasses();
        $this->adjustEncounterTypes();
    }

    /**
     * Load available patient referrals from the local database.
     */
    protected function loadAvailableReferrals(): void
    {
        if ($this->referralsLoaded) {
            return;
        }

        if ($this->personId === null) {
            $this->referralsLoaded = true;

            return;
        }

        $services = collect($this->dictionaries['custom/services'] ?? []);
        $procedureCategories = array_keys($this->dictionaries['eHealth/procedure_categories'] ?? []);
        $diagnosticReportCategories = array_keys($this->dictionaries['eHealth/diagnostic_report_categories'] ?? []);

        // category is a CodeableConcept relation (category_id), not a string column
        $this->availableReferrals = MedicalEventsRepository::serviceRequest()
            ->getByPersonIdAndStatus($this->personId, ServiceRequestStatus::PROCESSED->value, ['uuid', 'request_number', 'service_id', 'category_id'])
            ->loadMissing('category')
            ->map(static function (ServiceRequestRequest $referral) use ($services, $procedureCategories, $diagnosticReportCategories): array {
                $service = $services->firstWhere('id', $referral->serviceId);
                $category = strtolower((string) ($referral->category?->text ?? ''));

                return [
                    'id' => $referral->uuid,
                    'requisition' => $referral->requestNumber ?: $referral->uuid,
                    'category' => $category !== ''
                        ? __('care-plan.referral_category.'.$category)
                        : __('encounters.electronic_referral'),
                    'service' => $service,
                    'isProcedureAllowed' => $service !== null && in_array($service['category'] ?? null, $procedureCategories, true),
                    'isDiagnosticReportAllowed' => $service !== null && in_array($service['category'] ?? null, $diagnosticReportCategories, true),
                ];
            })
            ->values()
            ->toArray();

        $this->referralsLoaded = true;
    }

    /**
     * Load previous detected issues for the selected device.
     *
     * @param  string|null  $deviceUuid
     * @return void
     */
    public function loadPreviousDetectedIssues(?string $deviceUuid): void
    {
        $this->previousDetectedIssues = [];

        if (empty($deviceUuid)) {
            return;
        }

        $this->previousDetectedIssues = MedicalEventsRepository::detectedIssue()->getByDevice($this->patient(), $deviceUuid);
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
        $query = Icd10::search($value)->active()->limit(50);

        $allowedCodes = $this->allowedConditionCodesBySystem['eHealth/ICD10_AM/condition_codes'] ?? null;
        if ($allowedCodes !== null) {
            $query->whereIn('code', $allowedCodes);
        }

        if ($this->forbiddenIcd10ConditionCodes !== []) {
            $query->whereNotIn('code', $this->forbiddenIcd10ConditionCodes);
        }

        $this->results = $query->get(['code', 'description'])->toArray();
    }

    /**
     * Resolve the patient model (person or preperson) for the current context.
     *
     * @return Person|Preperson
     */
    protected function patient(): Person|Preperson
    {
        return $this->patientModel ??= ($this->prepersonId !== null
            ? Preperson::findOrFail($this->prepersonId)
            : Person::with('names')->findOrFail($this->personId));
    }

    /**
     * Initialize the component data for the current patient.
     *
     * @return void
     */
    protected function initializeComponent(): void
    {
        $encounterWriterEmployee = $this->encounterWriterEmployee;

        // Used by Alpine co-author rows (diagnosis performer); empty name falls back to raw UUID in UI
        $this->employeeFullName = $encounterWriterEmployee?->fullName ?? '';

        $this->deviceDispenseEmployee = [
            'uuid' => $encounterWriterEmployee->uuid,
            'name' => $encounterWriterEmployee->fullName,
            'position' => $encounterWriterEmployee->position
        ];

        $this->form->encounter['performerId'] = $encounterWriterEmployee->uuid;
        $this->employeeFullName = $encounterWriterEmployee->fullName;

        $participantEmployeeTypes = config('ehealth.encounter_package_allowed_encounter_participant_employee_types');
        $diagnosticReportEmployeeTypes = config('ehealth.encounter_package_allowed_diagnostic_report_performer_employee_types', []);
        $performerEmployeeTypes = array_keys(config('ehealth.performer_employee_encounter_classes', []));

        $employees = Employee::whereLegalEntityId(legalEntity()->id)
            ->active()
            ->whereIn(
                'employee_type',
                array_unique([...$participantEmployeeTypes, ...$diagnosticReportEmployeeTypes, ...$performerEmployeeTypes])
            )
            ->select(['uuid', 'party_id', 'position', 'employee_type', 'division_uuid'])
            ->with('party:id,last_name,first_name,second_name')
            ->get();

        $this->employees = $employees->whereIn('employeeType', $participantEmployeeTypes)
            ->map(static fn (Employee $employee): array => [
                'uuid' => $employee->uuid,
                'name' => $employee->fullName,
                'position' => $employee->position
            ])
            ->values()
            ->toArray();

        // The performer has to be the user themselves, so only their own employees are offered
        $this->performerEmployees = $employees->where('partyId', Auth::user()->partyId)
            ->whereIn('employeeType', $performerEmployeeTypes)
            ->map(static fn (Employee $employee): array => [
                'uuid' => $employee->uuid,
                'name' => $employee->fullName,
                'position' => $employee->position,
                'encounterClasses' => config("ehealth.performer_employee_encounter_classes.$employee->employeeType", []),
                'encounterTypes' => config("ehealth.performer_employee_encounter_types.$employee->employeeType", [])
            ])
            ->values()
            ->toArray();

        $this->diagnosticReportEmployees = $employees->whereIn('employeeType', $diagnosticReportEmployeeTypes)
            ->map(static fn (Employee $employee): array => [
                'uuid' => $employee->uuid,
                'name' => $employee->fullName,
                'position' => $employee->position,
                'employeeType' => $employee->employeeType,
                'divisionUuid' => $employee->divisionUuid
            ])
            ->values()
            ->toArray();

        $this->procedureEmployees = collect($this->diagnosticReportEmployees)
            ->whereIn('employeeType', config('ehealth.encounter_package_allowed_procedure_performer_employee_types', []))
            ->values()
            ->toArray();

        $this->divisions = legalEntity()->divisions()->whereStatus(Status::ACTIVE)->get()->toArray();

        $this->destinationLegalEntities = LegalEntity::active()
            ->get(['uuid', 'edr', 'beneficiary'])
            ->pluck('name', 'uuid')
            ->all();

        $this->allowedConditionCodesBySystem = $encounterWriterEmployee->allowedConditionCodesBySystem();
        $this->forbiddenIcd10ConditionCodes = $encounterWriterEmployee->forbiddenIcd10ConditionCodes();

        $this->equipmentOptions = Equipment::whereLegalEntityId(legalEntity()->id)
            ->where('availability_status', AvailabilityStatus::AVAILABLE)
            ->active()
            ->with(['names', 'division:id,uuid'])
            ->get()
            ->map(static fn (Equipment $equipment): array => [
                'uuid' => $equipment->uuid,
                'name' => $equipment->names->first()?->name ?? $equipment->uuid,
                'divisionUuid' => $equipment->division?->uuid
            ])
            ->values()
            ->toArray();

        $this->equipmentOptionsByDivision = collect($this->equipmentOptions)
            ->filter(static fn (array $equipment): bool => !empty($equipment['divisionUuid']))
            ->groupBy('divisionUuid')
            ->map(static fn (Collection $items): array => $items->values()->toArray())
            ->toArray();

        $this->patientDevices = Device::forPatient($this->patient())
            ->notEnteredInError()
            ->with('names')
            ->get(['id', 'uuid'])
            ->map(static fn (Device $device): array => [
                'uuid' => $device->uuid,
                'name' => $device->names->first()?->value ?? $device->uuid
            ])
            ->values()
            ->toArray();

        $this->patientSpecimens = Specimen::forPatient($this->patient())
            ->notEnteredInError()
            ->with('type.coding')
            ->get(['id', 'uuid', 'status', 'type_id'])
            ->map(static fn (Specimen $specimen): array => [
                'uuid' => $specimen->uuid,
                'status' => $specimen->status->value,
                'typeCode' => $specimen->type->coding->first()?->code ?? ''
            ])
            ->values()
            ->toArray();

        $this->setPatientData();

        if ($this->personId !== null) {
            $this->deviceRequests = MedicalEventsRepository::deviceRequest()->searchByPersonId($this->personId);
        }

        // set division ID if only one exist
        if (count($this->divisions) === 1) {
            $this->form->encounter['divisionId'] = $this->divisions[0]['uuid'];
        }

        $this->getEpisodes();
    }

    /**
     * Load the primary diagnosis from the selected episode.
     * Diagnoses added by hand stay, only the one taken from the previously selected episode is replaced.
     *
     * @param  string|null  $episodeId  Episode UUID.
     * @return void
     */
    public function updatedFormEpisodeId(?string $episodeId): void
    {
        $keptIndexes = array_flip(array_keys(array_filter(
            $this->conditionForm->conditions,
            static fn (array $condition): bool => !($condition['fromEpisode'] ?? false)
        )));

        $this->conditionForm->conditions = array_values(array_intersect_key($this->conditionForm->conditions, $keptIndexes));
        $this->form->encounter['diagnoses'] = array_values(array_intersect_key($this->form->encounter['diagnoses'], $keptIndexes));
        $this->episodePrimaryDiagnosisCode = '';

        if (empty($episodeId)) {
            return;
        }

        $episode = Episode::forPatient($this->patient())
            ->whereUuid($episodeId)
            ->with(['currentDiagnoses.condition', 'currentDiagnoses.role.coding'])
            ->first();

        $diagnosis = $episode?->currentDiagnoses->first(
            static fn (EpisodeCurrentDiagnosis $diagnosis): bool => $diagnosis->role?->coding->first()?->code === 'primary'
        );

        if ($diagnosis?->condition === null) {
            return;
        }

        $condition = MedicalEventsRepository::condition()->getByUuids([$diagnosis->condition->value])[0] ?? null;

        if ($condition === null) {
            return;
        }

        $detailsMap = MedicalEventsRepository::condition()->getDetailsMapForEvidences([$condition]);

        // The encounter references the condition the episode already holds instead of registering a copy of it
        $episodeCondition = [
            ...Fhir::condition()->fromFhir($condition, $detailsMap),
            'isRegistered' => true,
            'fromEpisode' => true,
            'episodeName' => $episode->name
        ];

        $this->episodePrimaryDiagnosisCode = $episodeCondition['codeCode'] ?? '';

        // A primary diagnosis added by hand is kept instead of the episode's one
        $hasPrimaryDiagnosis = collect($this->form->encounter['diagnoses'])
            ->contains(static fn (array $diagnosis): bool => ($diagnosis['roleCode'] ?? '') === 'primary');

        if ($hasPrimaryDiagnosis) {
            return;
        }

        array_unshift($this->conditionForm->conditions, $episodeCondition);
        array_unshift($this->form->encounter['diagnoses'], [
            'roleCode' => $diagnosis->role->coding->first()?->code,
            'rank' => $diagnosis->rank ?? ''
        ]);
    }

    /**
     * Load patient immunizations that may be referenced from observation.reaction_on.
     *
     * @param  string|null  $episodeId  Episode UUID to narrow the immunizations to its encounters
     * @return void
     */
    public function searchReactionImmunizations(?string $episodeId = null): void
    {
        $patient = $this->patient();

        $query = Immunization::forPatient($patient)
            ->with('vaccineCode.coding')
            ->whereStatus(ImmunizationStatus::COMPLETED->value)
            ->whereNotGiven(false);

        if ($episodeId) {
            // The identifier value is a string column, so the encounter UUIDs are matched as strings
            $encounterUuids = Encounter::forPatient($patient)->forEpisode($episodeId)->pluck('uuid');

            $query->whereHas(
                'context',
                static fn (Builder $context): Builder => $context->whereIn('value', $encounterUuids)
            );
        }

        $this->reactionImmunizations = $query->get()
            ->map(static fn (Immunization $immunization): array => [
                'uuid' => $immunization->uuid,
                'vaccineCode' => $immunization->vaccineCode?->coding?->first()?->code,
                'date' => convertToAppDateFormat($immunization->date),
                'episodeId' => $episodeId,
                'notGiven' => false,
                'status' => ImmunizationStatus::COMPLETED->value
            ])
            ->values()
            ->all();
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
            )->map(static function (array $item): array {
                $item = Arr::toCamelCase($item);
                $item['ehealthInsertedAt'] = convertToAppDateFormat($item['ehealthInsertedAt'] ?? null);

                return $item;
            })->all();
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while getting clinical impressions');
        }
    }

    /**
     * Store locally the previously registered conditions the diagnoses reference and the patient's records lack,
     * since a draft is reopened from the local records only.
     *
     * @param  array  $conditions  Validated conditions of the package
     * @return void
     * @throws EHealthConnectionException|EHealthException|Throwable
     */
    protected function syncRegisteredConditions(array $conditions): void
    {
        $registeredIds = collect($conditions)
            ->filter(static fn (array $condition): bool => ($condition['isRegistered'] ?? false) === true)
            ->pluck('uuid');

        if ($registeredIds->isEmpty() || $this->patientUuid === null) {
            return;
        }

        $storedIds = collect(MedicalEventsRepository::condition()->getByUuids($registeredIds->all()))->pluck('uuid');

        $missingConditions = $registeredIds->diff($storedIds)
            ->map(fn (string $conditionId): array => EHealth::condition()->getById($this->patientUuid, $conditionId)->validate())
            ->values()
            ->all();

        if ($missingConditions !== []) {
            MedicalEventsRepository::condition()->sync($this->patient(), $missingConditions);
        }
    }

    /**
     * Refresh the encounter participants required by the package and name the locked ones.
     *
     * @return void
     */
    public function syncEncounterParticipants(): void
    {
        $this->form->syncParticipants();

        $encounterWriterEmployee = Auth::user()->getEncounterWriterEmployee(
            data_get($this->form->encounter, 'classCode')
        );

        $employeeNames = collect($this->diagnosticReportEmployees)
            ->concat($this->employees)
            ->when(
                $encounterWriterEmployee !== null,
                static fn (Collection $employees): Collection => $employees->push([
                    'uuid' => $encounterWriterEmployee->uuid,
                    'name' => $encounterWriterEmployee->fullName
                ])
            )
            ->filter(static fn (array $employee): bool => !empty($employee['uuid']))
            ->unique('uuid')
            ->pluck('name', 'uuid');

        $this->form->encounter['participant'] = collect($this->form->encounter['participant'] ?? [])
            ->map(
                static function (array $participant) use ($employeeNames): array {
                    if (($participant['locked'] ?? false) !== true) {
                        return $participant;
                    }

                    $participant['name'] = $employeeNames->get($participant['uuid'], $participant['uuid']);

                    return $participant;
                }
            )
            ->values()
            ->toArray();
    }

    protected function setPatientData(): void
    {
        $patient = $this->patient();

        $this->patientUuid = $patient->uuid;
        $this->patientFullName = $patient->fullName;
    }

    /**
     * Adjust episode types to the ones allowed for the legal entity type and for the employee type at once,
     * the same way EncounterForm validates the chosen type.
     *
     * @return void
     */
    protected function adjustEpisodeTypes(): void
    {
        $keys = array_intersect(
            config("ehealth.legal_entity_episode_types.$this->legalEntityType", []),
            config("ehealth.employee_episode_types.$this->employeeType", [])
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
            "performer_employee_encounter_classes.$this->employeeType"
        );

        $this->adjustDictionary('eHealth/encounter_classes', $keys);

        // set default encounter class, if there is only one
        if (count($this->dictionaries['eHealth/encounter_classes']) === 1) {
            $this->form->encounter['classCode'] = array_key_first($this->dictionaries['eHealth/encounter_classes']);
        }
    }

    /**
     * Keep the encounter types of all available classes; the list is narrowed to the selected class on the client.
     *
     * @return void
     */
    protected function adjustEncounterTypes(): void
    {
        $this->encounterClassTypes = collect($this->dictionaries['eHealth/encounter_classes'])
            ->keys()
            ->mapWithKeys(static fn (string $classCode): array => [
                $classCode => config("ehealth.encounter_class_encounter_types.$classCode", [])
            ])
            ->toArray();

        $this->adjustDictionary('eHealth/encounter_types', array_merge(...array_values($this->encounterClassTypes)));

        $selectedClassTypes = $this->encounterClassTypes[$this->form->encounter['classCode'] ?? ''] ?? [];

        if (count($selectedClassTypes) === 1) {
            $this->form->encounter['typeCode'] = $selectedClassTypes[0];
        }
    }

    /**
     * Get active episodes for current patient.
     *
     * @return void
     */
    protected function getEpisodes(): void
    {
        if ($this->patientUuid === null) {
            return;
        }

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
        }
    }

    /**
     * Prepare vaccine options for searching by vaccine code, name and target disease.
     *
     * @return void
     */
    private function loadVaccineOptions(): void
    {
        $this->vaccineOptions = app(ImmunizationDictionaryMapper::class)->map(
            $this->dictionaries['eHealth/vaccine_codes'] ?? [],
            $this->dictionaries['eHealth/vaccination_target_diseases'] ?? []
        );
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
            ->flattenedChildValues(true)
            ->toArray();
        $this->dictionaries['custom/services'] = dictionary()->services()->flattened()->toArray();

        $ruleEngineRules = dictionary()->ruleEngineRules();
        $this->dictionaries['custom/rule_engine_rule_list'] = $ruleEngineRules->ruleList();
        $this->dictionaries['custom/rule_engine_details'] = $ruleEngineRules->details();

        $this->dictionaries['custom/device_definitions'] = dictionary()->deviceDefinitions()
            ->map(static fn (array $deviceDefinition): array => [
                'id' => $deviceDefinition['id'],
                'name' => $deviceDefinition['device_names'][0]['name'],
                'typeCodes' => collect($deviceDefinition['classification_types'])
                    ->where('system', 'device_definition_classification_type')
                    ->pluck('code')
                    ->map(static fn (mixed $code): string => (string) $code)
                    ->values()
                    ->all()
            ])
            ->values()
            ->toArray();
    }

    /**
     * Adjust dictionaries by provided key and values.
     */
    private function adjustDictionary(string $dictionaryKey, array $allowedValues): void
    {
        $this->dictionaries[$dictionaryKey] = Arr::only($this->dictionaries[$dictionaryKey], $allowedValues);
    }
}
