<?php

declare(strict_types=1);

namespace App\Livewire\Encounter;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\Episode\Status as EpisodeStatus;
use App\Enums\Equipment\AvailabilityStatus;
use App\Enums\ClinicalImpression\Status as ClinicalImpressionStatus;
use App\Enums\Person\ImmunizationStatus;
use App\Enums\Person\ObservationStatus;
use App\Enums\Person\ServiceRequestStatus;
use App\Enums\Status;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
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
use App\Traits\SearchesElectronicReferrals;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Carbon\CarbonImmutable;
use Throwable;

class EncounterComponent extends Component
{
    use FormTrait;
    use SearchesElectronicReferrals;
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
    public string $patientFullName;

    /**
     * List of authorized user's divisions.
     *
     * @var array
     */
    public array $divisions;

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
     * Patient UUID for API requests. Null for a preperson that is not yet registered in eHealth.
     *
     * @var string|null
     */
    public ?string $patientUuid = null;
    public array $availableReferrals = [];
    public bool $referralsLoaded = false;
    public ?string $selectedReferralUuid = null;

    /**
     * Legal entity type of auth user.
     *
     * @var string
     */
    protected string $legalEntityType;

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
     * Allowed condition codes per code system for the current user, based on employee type.
     * Key absent = no restriction; key present with empty array = system forbidden; key present with codes = allowed codes.
     *
     * @var array
     */
    public array $allowedConditionCodesBySystem = [];

    /**
     * ICD-10 AM condition codes reserved for specialities the current user does not hold.
     *
     * @var array
     */
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
     *
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
     *
     *
     * @var array<int, array{
     *      uuid: string,
     *      vaccineCode: string,
     *      date: string,
     *      notGiven: bool,
     *      status: string
     * }>
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
            ->filter(static fn (array $value) => $value[1] === 'valueCodeableConcept')
            ->mapWithKeys(fn (array $value) => [
                $value[0] => $this->dictionaries[$value[0]] ?? [],
            ])
            ->toArray();

        $this->legalEntityType = legalEntity()->type->name;
        $this->employeeType = Auth::user()->getEncounterWriterEmployee()?->employeeType;

        $this->adjustEpisodeTypes();
        $this->adjustEncounterClasses();
        $this->adjustEncounterTypes();
    }

    protected function storePackageElectronicReferralsIfMissing(): void
    {
        if ($this->personId === null) {
            return;
        }

        $this->storeElectronicReferralsFromRecords(
            $this->procedureForm->procedures,
            Auth::user()->getProcedureWriterEmployee()
        );

        $this->storeElectronicReferralsFromRecords(
            $this->diagnosticReportForm->diagnosticReports,
            Auth::user()->getDiagnosticReportWriterEmployee()
        );
    }

    private function storeElectronicReferralsFromRecords(array $records, ?Employee $employee): void {
        if ($employee === null) {
            return;
        }

        foreach ($records as $record) {
            if (($record['referralType'] ?? null) !== 'electronic') {
                continue;
            }

            $uuid = data_get($record, 'basedOnIdentifier');
            $requestNumber = data_get($record, 'referralNumber');
            $serviceId = data_get($record, 'codeValue');

            if (blank($uuid) || blank($requestNumber) || blank($serviceId)) {
                continue;
            }

            MedicalEventsRepository::serviceRequest()->storeExternalIfMissing([
                'uuid' => (string) $uuid,
                'request_number' => (string) $requestNumber,
                'service_id' => (string) $serviceId,
                'employee_id' => $employee->id,
                'division_id' => $employee->divisionId,
            ], $this->personId);
        }
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
            ->getByPersonIdAndStatus($this->personId, ServiceRequestStatus::ACTIVE->value, ['uuid', 'request_number', 'service_id', 'category_id'])
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
    protected function initializeComponent(bool $loadEpisodes = true): void
    {
        $authUser = Auth::user();
        $encounterWriterEmployee = $authUser->getEncounterWriterEmployee();

        // Used by Alpine co-author rows (diagnosis performer); empty name falls back to raw UUID in UI
        $this->employeeFullName = $encounterWriterEmployee?->fullName ?? '';

        $this->deviceDispenseEmployee = [
            'uuid' => $encounterWriterEmployee->uuid,
            'name' => $encounterWriterEmployee->fullName,
            'position' => $encounterWriterEmployee->position
        ];

        $employees = Employee::whereLegalEntityId(legalEntity()->id)
            ->active()
            ->whereIn('employee_type', config('ehealth.encounter_package_allowed_encounter_participant_employee_types'))
            ->select([
                'uuid',
                'position',
                'party_id',
                'employee_type',
            ])
            ->with('party:id,last_name,first_name,second_name')
            ->get();
        $this->employees = $employees->map(function (Employee $employee) {
            return [
                'uuid' => $employee->uuid,
                'name' => $employee->fullName,
                'position' => $employee->position
            ];
        })->toArray();

        $this->diagnosticReportEmployees = Employee::whereLegalEntityId(legalEntity()->id)
            ->active()
            ->whereIn('employee_type', config('ehealth.encounter_package_allowed_diagnostic_report_performer_employee_types', []))
            ->select(['uuid', 'party_id', 'position', 'employee_type', 'division_uuid'])
            ->with('party:id,last_name,first_name,second_name')
            ->get()
            ->map(function (Employee $employee): array {
                return [
                    'uuid' => $employee->uuid,
                    'name' => $employee->fullName,
                    'position' => $employee->position,
                    'employeeType' => $employee->employeeType,
                    'divisionUuid' => $employee->divisionUuid,
                ];
            })
            ->values()
            ->toArray();

        $this->procedureEmployees = collect($this->diagnosticReportEmployees)
            ->whereIn('employeeType', config('ehealth.encounter_package_allowed_procedure_performer_employee_types', []))
            ->values()
            ->toArray();

        $this->legalEntityType = legalEntity()->type->name;
        $this->divisions = legalEntity()->divisions()->whereStatus(Status::ACTIVE)->get()->toArray();

        $this->destinationLegalEntities = LegalEntity::active()
            ->get(['uuid', 'edr', 'beneficiary'])
            ->pluck('name', 'uuid')
            ->all();

        $encounterWriterEmployee = $authUser->getEncounterWriterEmployee();
        $this->allowedConditionCodesBySystem = $this->computeAllowedConditionCodesBySystem($encounterWriterEmployee);
        $this->forbiddenIcd10ConditionCodes = $this->computeForbiddenIcd10ConditionCodes($encounterWriterEmployee);

        $this->equipmentOptions = Equipment::whereLegalEntityId(legalEntity()->id)
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

        if ($loadEpisodes) {
            $this->getEpisodes();
        }
    }

    /**
     * Load the primary diagnosis from the selected episode.
     *
     * @param  string|null  $episodeId  Episode UUID.
     * @return void
     */
    public function updatedFormEpisodeId(?string $episodeId): void
    {
        $this->conditionForm->conditions = [];
        $this->form->encounter['diagnoses'] = [];
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

        $episodeCondition = Arr::except(
            Fhir::condition()->fromFhir($condition, $detailsMap),
            ['uuid', 'assertedDate', 'assertedTime']
        );

        $this->episodePrimaryDiagnosisCode = $episodeCondition['codeCode'] ?? '';
        $this->conditionForm->conditions = [$episodeCondition];

        $this->form->encounter['diagnoses'] = [[
            'roleCode' => $diagnosis->role->coding->first()?->code,
            'rank' => $diagnosis->rank ?? ''
        ]];
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
     * Load patient immunizations that may be referenced from observation.reaction_on.
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
     * @param  string  $type  One of: episodes, encounter, procedure, diagnosticReport, condition, observation.
     * @param  string|null  $episodeId
     * @return void
     */
    public function searchSupportingInfo(string $type, ?string $episodeId = null): void
    {
        try {
            $params = ['managing_organization_id' => legalEntity()->uuid];

            if ($episodeId) {
                if ($type === 'diagnosticReport') {
                    $params['context_episode_id'] = $episodeId;
                } elseif ($type !== 'episodes') {
                    $params['episode_id'] = $episodeId;
                }
            }

            $this->supportingInfoResults = match ($type) {
                'episodes' => collect($this->episodes)
                    ->when($episodeId, static fn ($episodes) => $episodes->where('uuid', $episodeId))
                    ->map(fn (array $episode) => [
                        'uuid' => data_get($episode, 'uuid'),
                        'ehealthInsertedAt' => convertToAppDateFormat(data_get($episode, 'ehealthInsertedAt')),
                        'code' => data_get($episode, 'name'),
                        'type' => 'episode'
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

    public function syncEncounterParticipants(): void
    {
        $this->form->syncParticipants();

        $encounterWriterEmployee = Auth::user()->getEncounterWriterEmployee(
            data_get($this->form->encounter, 'classCode')
        );

        $employeeNames = collect($this->diagnosticReportEmployees)
            ->when(
                $encounterWriterEmployee !== null,
                static fn ($employees) => $employees->push([
                    'uuid' => $encounterWriterEmployee->uuid,
                    'name' => $encounterWriterEmployee->fullName,
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
     * Show encounter types based on encounter class.
     *
     * @return void
     */
    protected function adjustEncounterTypes(): void
    {
        $selectedClass = $this->form->encounter['classCode'] ?: key($this->dictionaries['eHealth/encounter_classes']);
        $classEncounterTypes = config("ehealth.encounter_class_encounter_types.$selectedClass", []);

        $roleEncounterTypes = Auth::user()->allowedRoles
            ->flatMap(static fn (string $role): array => config("ehealth.performer_employee_encounter_types.$role", []))
            ->unique()
            ->values()
            ->all();

        $keys = array_values(array_intersect($classEncounterTypes, $roleEncounterTypes));

        $this->adjustDictionary('eHealth/encounter_types', $keys);

        if (count($this->dictionaries['eHealth/encounter_types']) === 1) {
            $this->form->encounter['typeCode'] = array_key_first($this->dictionaries['eHealth/encounter_types']);
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

            return;
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
     * Compute allowed condition codes per code system for the current user from employee-type restrictions.
     * Key absent means no restriction; empty array means the system is forbidden; non-empty array lists the allowed codes.
     *
     * @param  Employee  $employee
     * @return array
     */
    private function computeAllowedConditionCodesBySystem(Employee $employee): array
    {
        $employeeTypeRestrictions = config("ehealth.employee_type_conditions_allowed.$employee->employeeType");

        if ($employeeTypeRestrictions === null) {
            return [];
        }

        return [
            'eHealth/ICD10_AM/condition_codes' => $employeeTypeRestrictions['eHealth/ICD10_AM/condition_codes'] ?? [],
            'eHealth/ICPC2/condition_codes' => $employeeTypeRestrictions['eHealth/ICPC2/condition_codes'] ?? []
        ];
    }

    /**
     * Compute ICD-10 AM condition codes reserved for specialities the employee does not hold as officio.
     * A code listed for several specialities stays allowed when the employee holds any of them.
     *
     * @param  Employee  $employee
     * @return array
     */
    private function computeForbiddenIcd10ConditionCodes(Employee $employee): array
    {
        $specialityCodes = config('ehealth.icd10am_speciality_conditions_allowed', []);

        $heldSpecialities = $employee->loadMissing('specialities')
            ->specialities
            ->where('specialityOfficio', true)
            ->pluck('speciality')
            ->all();

        $allowedCodes = collect($specialityCodes)->only($heldSpecialities)->flatten();

        return collect($specialityCodes)
            ->flatten()
            ->unique()
            ->diff($allowedCodes)
            ->values()
            ->all();
    }

    /**
     * Validate encounter performer according to encounter date and package primary sources.
     *
     * @param  array  $package
     * @return void
     * @throws ValidationException
     */
    protected function validateEncounterPerformer(array $package): void
    {
        $periodEnd = CarbonImmutable::parse(data_get($package, 'encounter.period.end'));

        $periodEndDate = $periodEnd->startOfDay();
        $currentDate = CarbonImmutable::now($periodEnd->getTimezone())->startOfDay();

        $hasPrimarySource = collect([
            'conditions',
            'immunizations',
            'diagnostic_reports',
            'observations',
            'procedures',
        ])->contains(
            static fn (string $section): bool => collect($package[$section] ?? [])
                ->contains(static fn (array $entity): bool => ($entity['primary_source'] ?? false) === true)
        );

        $performerUuid = data_get($package, 'encounter.performer.identifier.value');

        $performer = Employee::query()
            ->whereUuid($performerUuid)
            ->first([
                'uuid',
                'party_id',
                'legal_entity_id',
            ]);

        if ($performer === null || $performer->legalEntityId !== legalEntity()->id) {
            throw ValidationException::withMessages([
                'encounter.performer' => __('validation.custom.encounter.performer_wrong_legal_entity'),
            ]);
        }

        $performerMustBeCurrentUser = $periodEndDate->equalTo($currentDate) || ($periodEndDate->lessThan($currentDate) && $hasPrimarySource);

        if ($performerMustBeCurrentUser && $performer->partyId !== Auth::user()->partyId) {
            throw ValidationException::withMessages([
                'encounter.performer' => __('validation.custom.encounter.performer_not_current_user'),
            ]);
        }
    }

    /**
     * Adjust dictionaries by provided key and values.
     */
    private function adjustDictionary(string $dictionaryKey, array $allowedValues): void
    {
        $this->dictionaries[$dictionaryKey] = Arr::only($this->dictionaries[$dictionaryKey], $allowedValues);
    }
}
