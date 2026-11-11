<?php

declare(strict_types=1);

namespace App\Livewire\EncounterPackage;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\ClinicalImpression\Status as ClinicalImpressionStatus;
use App\Enums\Episode\Status as EpisodeStatus;
use App\Enums\Equipment\AvailabilityStatus;
use App\Enums\Person\DeviceRequestStatus;
use App\Enums\Person\ImmunizationStatus;
use App\Enums\Status;
use App\Enums\User\Role;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Jobs\RemoteEHealthLinksProcessing;
use App\Livewire\EncounterPackage\Forms\ClinicalImpressionForm;
use App\Livewire\EncounterPackage\Forms\ConditionForm;
use App\Livewire\EncounterPackage\Forms\DetectedIssueForm;
use App\Livewire\EncounterPackage\Forms\DeviceAssociationForm;
use App\Livewire\EncounterPackage\Forms\DeviceDispenseForm;
use App\Livewire\EncounterPackage\Forms\DeviceForm;
use App\Livewire\EncounterPackage\Forms\DiagnosticReportForm;
use App\Livewire\EncounterPackage\Forms\EncounterForm as Form;
use App\Livewire\EncounterPackage\Forms\ImmunizationForm;
use App\Livewire\EncounterPackage\Forms\ObservationForm;
use App\Livewire\EncounterPackage\Forms\ProcedureForm;
use App\Livewire\EncounterPackage\Forms\SpecimenForm;
use App\Models\Employee\Employee;
use App\Models\Equipment;
use App\Models\MedicalEvents\Sql\Condition;
use App\Models\MedicalEvents\Sql\Device;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\MedicalEvents\Sql\EpisodeCurrentDiagnosis;
use App\Models\MedicalEvents\Sql\Immunization;
use App\Models\MedicalEvents\Sql\Observation;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\MedicalEvents\Sql\Specimen;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use App\Repositories\Repository;
use App\Services\Dictionary\Mappers\ImmunizationDictionaryMapper;
use App\Services\MedData\MedData;
use App\Services\MedicalEvents\Fhir;
use App\Traits\FormTrait;
use App\Traits\SearchesElectronicReferrals;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

class EncounterPackageComponent extends Component
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
     * Interaction classes allowed for each episode type, used to filter the episode lists on the client.
     *
     * @var array
     */
    #[Locked]
    public array $episodeTypeClasses = [];

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
    #[Locked]
    public ?string $employeeType = null;

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
     * Components config per observation code whose configuration defines components.
     *
     * @var array
     */
    public array $observationComponentMap;

    /**
     * Allowed condition codes per code system for the current user, based on employee type.
     * Key absent = no restriction; key present with empty array = system forbidden; key present with codes = allowed codes.
     *
     * @var array
     */
    #[Locked]
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
     * Active eHealth Device Requests offered as based_on for a device dispense.
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
     * List of employees available as clinical impression assessors.
     *
     * @var array
     */
    public array $assessorEmployees = [];

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
     * Immunizations the observations of the package already refer to as the one they are a reaction on, so that a
     * reopened draft names them before any search runs.
     *
     * @var array
     */
    public array $referencedReactionImmunizations = [];

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
        'procedure_focal_device_actions',
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
        'eHealth/custom/observation_components',
        'POSITION'
    ];

    public function boot(): void
    {
        $this->legalEntityType = legalEntity()->type->name;
        $this->encounterWriterEmployee = Auth::user()->getEncounterWriterEmployee();
        $this->employeeType = $this->encounterWriterEmployee?->employeeType;
    }

    protected function initializeEncounterDictionaries(): void
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
        $this->observationComponentMap = $observationConfigRepository->componentMap();

        $this->loadCustomDictionaries();

        $this->codeableConceptValues = collect($this->observationValueMap)
            ->filter(static fn (array $value): bool => $value[1] === 'valueCodeableConcept')
            ->mapWithKeys(fn (array $value): array => [
                $value[0] => $this->dictionaries[$value[0]] ?? [],
            ])
            ->toArray();

        $this->adjustEpisodeTypes();
        $this->adjustEncounterClasses();
        $this->adjustEncounterTypes();
    }

    /**
     * Load patient-scoped referral choices with human numbers and catalog permissions.
     * The repository owns this mapping; the shared component only exposes the resulting UI state.
     */
    public function loadAvailableReferrals(): void
    {
        if ($this->referralsLoaded) {
            return;
        }

        if (Auth::user()->cannot('search', ServiceRequestRequest::class)) {
            Session::flash('error', __('service-requests.policy.search'));

            return;
        }

        if ($this->personId === null && $this->prepersonId === null) {
            $this->referralsLoaded = true;

            return;
        }

        $procedureCategories = array_keys($this->dictionaries['eHealth/procedure_categories'] ?? []);
        $diagnosticReportCategories = array_keys($this->dictionaries['eHealth/diagnostic_report_categories'] ?? []);

        $this->availableReferrals = MedicalEventsRepository::serviceRequest()
            ->encounterOptions(
                $this->patient(),
                $procedureCategories,
                $diagnosticReportCategories
            );

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
     * Find the eHealth ID of the electronic referral the form names by its number.
     *
     * @param  string  $referralNumber
     * @return string|null
     */
    protected function resolveReferralUuid(string $referralNumber): ?string
    {
        if (empty($referralNumber) || Str::isUuid($referralNumber)) {
            return $referralNumber;
        }

        if ($this->selectedReferralUuid !== null) {
            $selectedReferral = collect($this->availableReferrals)->firstWhere('id', $this->selectedReferralUuid);

            if (($selectedReferral['requisition'] ?? null) === $referralNumber) {
                return $this->selectedReferralUuid;
            }
        }

        $referral = collect($this->availableReferrals)->firstWhere('requisition', $referralNumber);

        if ($referral !== null || $this->personId === null) {
            return data_get($referral, 'id');
        }

        // A referral taken into work is no longer offered among the active ones, so it is found among the stored ones
        return ServiceRequestRequest::wherePersonId($this->personId)->whereRequestNumber($referralNumber)->value('uuid');
    }

    /**
     * Replace the referral numbers of the encounter and its procedures with the referral IDs eHealth expects.
     *
     * @param  array  $validated
     * @return void
     * @throws ValidationException
     */
    protected function resolveAllReferrals(array &$validated): void
    {
        if (($validated['encounter']['referralType'] ?? '') === 'electronic' && !empty($validated['encounter']['referralNumber'])) {
            $originalNumber = $validated['encounter']['referralNumber'];
            $uuid = $this->resolveReferralUuid($originalNumber);

            if ($uuid === null) {
                throw ValidationException::withMessages([
                    'form.encounter.referralNumber' => __('encounters.messages.referral_not_found')
                ]);
            }

            $validated['encounter']['referralNumber'] = $uuid;
            $validated['encounter']['referralDisplayValue'] = $originalNumber;
        }

        foreach ($validated['procedures'] ?? [] as $index => $procedure) {
            if (($procedure['referralType'] ?? '') !== 'electronic' || empty($procedure['basedOnIdentifier'])) {
                continue;
            }

            $uuid = $this->resolveReferralUuid($procedure['basedOnIdentifier']);

            if ($uuid === null) {
                throw ValidationException::withMessages([
                    "form.procedures.{$index}.basedOnIdentifier" => __('encounters.messages.referral_not_found')
                ]);
            }

            $validated['procedures'][$index]['basedOnIdentifier'] = $uuid;
        }
    }

    /**
     * Submit the signed encounter package and hand its eHealth job over to the queue. The encounter stays a draft
     * until the job is processed, so a rejected package can be fixed and signed again.
     *
     * @param  Encounter  $encounter
     * @param  array  $formattedData
     * @param  string  $signedData
     * @return void
     * @throws EHealthException|EHealthConnectionException|Throwable
     */
    protected function submitPackage(Encounter $encounter, array $formattedData, string $signedData): void
    {
        $submission = RemoteEHealthLinksProcessing::startSubmission($encounter, $formattedData);

        try {
            $response = EHealth::encounter()->submit($this->patientUuid, [
                'visit' => [
                    'id' => data_get($formattedData, 'encounter.visit.identifier.value'),
                    'period' => data_get($formattedData, 'encounter.period')
                ],
                'signed_data' => $signedData
            ]);
        } catch (EHealthException|EHealthConnectionException $exception) {
            RemoteEHealthLinksProcessing::markSubmissionFailed($submission);

            throw $exception;
        }

        // eHealth received the package already, so it stays submitted and locked even if following it fails
        try {
            RemoteEHealthLinksProcessing::track($submission, $response->validate());

            Session::flash('success', __('encounters.messages.package_sent'));
        } catch (Throwable $exception) {
            Log::channel('e_health_errors')->error('Submitted encounter package is not tracked', [
                'encounterId' => $encounter->id,
                'response' => $response->getData(),
                'exception' => $exception->getMessage()
            ]);
            Session::flash('error', __('errors.ehealth.messages.submission_not_tracked'));
        }
    }

    /**
     * Initialize the component data for the current patient.
     *
     * @param  bool  $loadEpisodes
     * @return void
     */
    protected function initializeComponent(bool $loadEpisodes = true): void
    {
        $this->initializeEncounterDictionaries();

        $encounterWriterEmployee = $this->encounterWriterEmployee;

        // Used by Alpine co-author rows (diagnosis performer); empty name falls back to raw UUID in UI
        $this->employeeFullName = $encounterWriterEmployee?->fullName ?? '';

        $this->deviceDispenseEmployee = [
            'uuid' => $encounterWriterEmployee->uuid,
            'name' => $encounterWriterEmployee->fullName,
            'position' => $encounterWriterEmployee->position
        ];

        $this->form->encounter['performerId'] = $encounterWriterEmployee->uuid;

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

        $this->assessorEmployees = $employees->whereIn('employeeType', [Role::DOCTOR->value, Role::SPECIALIST->value])
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

        $this->destinationLegalEntities = collect(Repository::legalEntity()->getLegalEntitiesList(activeOnly: true))
            ->pluck('name', 'uuid')
            ->all();

        $this->allowedConditionCodesBySystem = $encounterWriterEmployee->allowedConditionCodesBySystem();

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
            ->notInDraftEncounter($this->patient())
            ->with('names')
            ->get(['id', 'uuid', 'status', 'serial_number'])
            ->map(static fn (Device $device): array => [
                'uuid' => $device->uuid,
                'name' => $device->names->first()?->value ?? $device->uuid,
                'status' => $device->status->value,
                'serialNumber' => $device->serialNumber
            ])
            ->values()
            ->toArray();

        // A stored specimen keeps the type it was registered with, which the dictionary may have retired since
        $specimenTypeNames = dictionary()->basics()->getMultipleFormatted(['specimen_types'], onlyActive: false)
            ->toArray()['specimen_types'] ?? [];

        $this->patientSpecimens = Specimen::forPatient($this->patient())
            ->notEnteredInError()
            ->notDraft()
            ->with(['type.coding', 'collection.collectedPeriod'])
            ->get(['id', 'uuid', 'accession_identifier', 'status', 'type_id'])
            ->map(static function (Specimen $specimen) use ($specimenTypeNames): array {
                $typeCode = $specimen->type->coding->first()?->code ?? '';
                $typeName = $specimenTypeNames[$typeCode] ?? '';
                $collectedAt = $specimen->collection?->collectedDateTime ?? $specimen->collection?->collectedPeriod?->start;

                return [
                    'uuid' => $specimen->uuid,
                    'accessionIdentifier' => $specimen->accessionIdentifier,
                    'status' => $specimen->status->value,
                    'typeCode' => $typeCode,
                    // The accession number tells apart the specimens of one type
                    'name' => implode(', ', array_filter([
                        $specimen->accessionIdentifier,
                        $typeName,
                        $collectedAt ? __('specimens.collection') . ': ' . $collectedAt : ''
                    ]))
                ];
            })
            ->values()
            ->toArray();

        $this->setPatientData();

        // Active Device Requests come from eHealth (FHIR status=active), not local job rows.
        $this->loadActiveDeviceRequests();

        // set division ID if only one exist
        if (count($this->divisions) === 1) {
            $this->form->encounter['divisionId'] = $this->divisions[0]['uuid'];
        }

        if ($loadEpisodes) {
            $this->getEpisodes();
        } else {
            $this->getSelectedEpisode();
        }
    }

    /**
     * Fetch active Device Requests available as based_on for Device Dispense.
     *
     * Local device_request_requests often store async job status (`processed`), which is not
     * the FHIR DeviceRequest status required by eHealth for Encounter Package dispenses.
     */
    public function loadActiveDeviceRequests(): void
    {
        $this->deviceRequests = [];

        if ($this->personId === null || $this->patientUuid === null) {
            return;
        }

        try {
            $items = [];
            $page = 1;

            do {
                $response = EHealth::deviceRequest()->getBySearchParams($this->patientUuid, [
                    // ABAC: search is allowed only for requests of the current legal entity.
                    'requester_legal_entity' => legalEntity()->uuid,
                    'status' => DeviceRequestStatus::ACTIVE->value,
                    'page' => $page,
                ]);

                $items = [...$items, ...$response->validate()];
                $page++;
            } while ($response->isNotLast());

            $this->deviceRequests = collect($items)
                ->map(function (array $request): ?array {
                    $deviceCode = data_get($request, 'code.coding.0.code');
                    $deviceDefinitionId = data_get($request, 'code_reference.identifier.value');
                    $deviceSelectionType = $deviceDefinitionId ? 'model' : 'type';
                    $deviceId = $deviceDefinitionId ?: $deviceCode;

                    if (!$deviceId) {
                        return null;
                    }

                    $itemName = data_get($request, 'code_reference.display_value');

                    if (!$itemName && $deviceDefinitionId) {
                        $itemName = data_get(
                            collect($this->dictionaries['custom/device_definitions'])->firstWhere('id', $deviceDefinitionId),
                            'name'
                        );
                    }

                    if (!$itemName && $deviceCode) {
                        $itemName = $this->dictionaries['device_definition_classification_type'][$deviceCode] ?? $deviceCode;
                    }

                    $quantity = data_get($request, 'quantity.value');

                    return [
                        'uuid' => data_get($request, 'uuid'),
                        'requestNumber' => data_get($request, 'requisition') ?: data_get($request, 'uuid'),
                        'status' => data_get($request, 'status'),
                        'intent' => data_get($request, 'intent'),
                        'itemName' => $itemName ?: __('device-dispenses.medical_device'),
                        'deviceId' => $deviceId,
                        'deviceSelectionType' => $deviceSelectionType,
                        'quantityValue' => $quantity !== null ? (int) $quantity : null,
                        'quantityCode' => data_get($request, 'quantity.code'),
                        'programId' => data_get($request, 'program.identifier.value'),
                        'carePlanUuid' => data_get($request, 'based_on.identifier.value'),
                        'dispenseValidTo' => data_get($request, 'dispense_valid_to'),
                    ];
                })
                ->filter()
                ->values()
                ->toArray();
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while loading active Device Requests');
        }
    }

    /**
     * Load remaining quantity (and unit) for a selected Device Request.
     *
     * @return array{remainingQuantity: mixed, quantityCode: mixed}
     */
    public function loadDeviceRequestDetails(string $deviceRequestId): array
    {
        if ($this->patientUuid === null || !collect($this->deviceRequests)->contains('uuid', $deviceRequestId)) {
            return [];
        }

        try {
            $request = EHealth::deviceRequest()
                ->getById($this->patientUuid, $deviceRequestId)
                ->getData();

            $details = [
                'remainingQuantity' => data_get($request, 'remaining_quantity.value'),
                'quantityCode' => data_get($request, 'quantity.code'),
            ];

            $index = collect($this->deviceRequests)->search(
                static fn (array $item): bool => $item['uuid'] === $deviceRequestId
            );

            if ($index !== false) {
                $this->deviceRequests[$index] = array_merge($this->deviceRequests[$index], $details);
            }

            return $details;
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while loading Device Request details');

            return [];
        }
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

        $this->loadIcd10Descriptions($episodeCondition['evidenceDetails']);

        array_unshift($this->conditionForm->conditions, $episodeCondition);
        array_unshift($this->form->encounter['diagnoses'], [
            'roleCode' => $diagnosis->role->coding->first()?->code,
            'rank' => $diagnosis->rank ?? ''
        ]);
    }

    /**
     * Search eHealth for the patient's immunizations of the episode that an observation may refer to as the one it is
     * a reaction on. Only an immunization eHealth holds can be referred to, so the local records are not offered.
     *
     * @param  string|null  $episodeId  Episode UUID the immunizations are searched in
     * @return void
     */
    public function searchReactionImmunizations(?string $episodeId = null): void
    {
        $this->reactionImmunizations = [];

        if ($this->patientUuid === null || empty($episodeId)) {
            return;
        }

        try {
            $immunizations = EHealth::immunization()
                ->getBySearchParams($this->patientUuid, ['episode_id' => $episodeId])
                ->validate();
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while searching for immunizations');

            return;
        }

        $this->reactionImmunizations = collect($immunizations)
            ->filter(
                static fn (array $immunization): bool => data_get($immunization, 'status') === ImmunizationStatus::COMPLETED->value
                    && data_get($immunization, 'not_given') === false
            )
            ->map(static fn (array $immunization): array => [
                'uuid' => data_get($immunization, 'uuid'),
                'vaccineCode' => data_get($immunization, 'vaccine_code.coding.0.code'),
                'date' => convertToAppDateFormat(data_get($immunization, 'date')),
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
     * Load the immunizations the observations of the package refer to as the one they are a reaction on.
     *
     * @param  array  $observations  Observations of the package
     * @return void
     */
    protected function loadReferencedReactionImmunizations(array $observations): void
    {
        $immunizationIds = collect($observations)->pluck('reactionOn')->filter()->unique()->values();

        if ($immunizationIds->isEmpty()) {
            return;
        }

        $this->referencedReactionImmunizations = Immunization::forPatient($this->patient())
            ->with('vaccineCode.coding')
            ->whereIn('uuid', $immunizationIds)
            ->get()
            ->map(static fn (Immunization $immunization): array => [
                'uuid' => $immunization->uuid,
                'vaccineCode' => $immunization->vaccineCode?->coding?->first()?->code,
                'date' => convertToAppDateFormat($immunization->date),
                'notGiven' => (bool) $immunization->notGiven,
                'status' => $immunization->status->value
            ])
            ->values()
            ->all();
    }

    /**
     * Store locally the records the package refers to that are missing or stored only partially,
     * since a draft is reopened from the local records only.
     *
     * @return void
     * @throws EHealthConnectionException|EHealthException|Throwable
     */
    protected function syncReferencedRecords(): void
    {
        if ($this->patientUuid === null) {
            return;
        }

        $references = collect([
            ...$this->conditionForm->referencedRecords(),
            ...$this->clinicalImpressionForm->referencedRecords(),
            ...$this->procedureForm->referencedRecords()
        ]);

        $encounterIds = $references->where('type', 'encounter')->pluck('id')->unique()->values()->all();
        $this->syncReferencedEncounters($encounterIds);

        // The package's own new records are not in eHealth yet, and the package stores them itself
        $conditionIds = $references->where('type', 'condition')
            ->pluck('id')
            // The code of an encounter's primary diagnosis is kept in its condition
            ->concat(MedicalEventsRepository::encounter()->primaryConditionUuids($encounterIds))
            ->diff($this->conditionForm->newConditionIds());
        $observationIds = $references->where('type', 'observation')
            ->pluck('id')
            ->diff(array_column($this->observationForm->observations, 'uuid'));

        $this->syncReferencedConditionsAndObservations(
            $conditionIds->filter()->unique()->values()->all(),
            $observationIds->filter()->unique()->values()->all()
        );
        $this->syncReactionImmunizations();
    }

    /**
     * Store the encounters picked from eHealth, unless they are stored with their diagnoses already. An encounter
     * stored from the patient summary lacks them, and the primary one names the encounter.
     *
     * @param  array  $encounterIds
     * @return void
     * @throws EHealthConnectionException|EHealthException|Throwable
     */
    protected function syncReferencedEncounters(array $encounterIds): void
    {
        $missingEncounters = collect($encounterIds)
            ->diff(Encounter::whereIn('uuid', $encounterIds)->has('diagnoses')->pluck('uuid'))
            ->map(fn (string $encounterId): array => EHealth::encounter()->getById($this->patientUuid, $encounterId)->validate())
            ->values()
            ->all();

        if ($missingEncounters !== []) {
            MedicalEventsRepository::encounter()->sync($this->patient(), $missingEncounters);
        }
    }

    /**
     * Store the conditions and observations picked from eHealth, unless they were fetched from eHealth already.
     * One stored from the data sent from here lacks the insertion date the package lists it with.
     *
     * @param  array  $conditionIds
     * @param  array  $observationIds
     * @return void
     * @throws EHealthConnectionException|EHealthException|Throwable
     */
    protected function syncReferencedConditionsAndObservations(array $conditionIds, array $observationIds): void
    {
        $missingConditions = collect($conditionIds)
            ->diff(Condition::whereIn('uuid', $conditionIds)->fetchedFromEHealth()->pluck('uuid'))
            ->map(fn (string $conditionId): array => EHealth::condition()->getById($this->patientUuid, $conditionId)->validate())
            ->values()
            ->all();

        $missingObservations = collect($observationIds)
            ->diff(Observation::whereIn('uuid', $observationIds)->fetchedFromEHealth()->pluck('uuid'))
            ->map(fn (string $observationId): array => EHealth::observation()->getById($this->patientUuid, $observationId)->validate())
            ->values()
            ->all();

        if ($missingConditions !== []) {
            MedicalEventsRepository::condition()->sync($this->patient(), $missingConditions);
        }

        if ($missingObservations !== []) {
            MedicalEventsRepository::observation()->sync($this->patient(), $missingObservations);
        }
    }

    /**
     * Store the immunizations picked from eHealth as the ones observations are a reaction on. An immunization of this
     * very package is not in eHealth yet, and the package stores it itself.
     *
     * @return void
     * @throws EHealthConnectionException|EHealthException|Throwable
     */
    protected function syncReactionImmunizations(): void
    {
        $immunizationIds = collect($this->observationForm->observations)
            ->pluck('reactionOn')
            ->filter()
            ->unique()
            ->diff(array_column($this->immunizationForm->immunizations, 'uuid'))
            ->values();

        $missingImmunizations = $immunizationIds->diff(Immunization::whereIn('uuid', $immunizationIds)->pluck('uuid'))
            ->flatMap(fn (string $immunizationId): array => EHealth::immunization()->getById($this->patientUuid, $immunizationId)->validate())
            ->values()
            ->all();

        if ($missingImmunizations !== []) {
            MedicalEventsRepository::immunization()->sync($this->patient(), $missingImmunizations);
        }
    }

    /**
     * Store locally the full existing episode the package is recorded in, unless it is stored already as managed by
     * the current legal entity. The access rules find the encounter by its episode stored this way, so without it the
     * author loses the encounter once it stops being a draft.
     *
     * @param  string|null  $episodeId  ID of the selected existing episode, empty for a new one
     * @return void
     * @throws EHealthConnectionException|EHealthException|Throwable
     */
    protected function syncSelectedEpisode(?string $episodeId): void
    {
        if ($this->patientUuid === null
            || empty($episodeId)
            || Episode::forPatient($this->patient())->whereUuid($episodeId)->managedByLegalEntity()->exists()) {
            return;
        }

        MedicalEventsRepository::episode()->syncFull(
            $this->patient(),
            [EHealth::episode()->getById($this->patientUuid, $episodeId)->validate()]
        );
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

    protected function storePackageElectronicReferralsIfMissing(): void
    {
        $referrals = [];

        $procedureEmployee = Auth::user()->getProcedureWriterEmployee();

        foreach ($this->procedureForm->procedures as $procedure) {
            $uuid = data_get($procedure, 'basedOnIdentifier');

            if (data_get($procedure, 'referralType') !== 'electronic' || blank($uuid)) {
                continue;
            }

            $referral = $this->getSearchedElectronicReferral($uuid);

            if ($referral !== null) {
                $referrals[] = [
                    'referral' => $referral,
                    'employee' => $procedureEmployee,
                ];
            }
        }

        $diagnosticReportEmployee = Auth::user()->getDiagnosticReportWriterEmployee();

        foreach ($this->diagnosticReportForm->diagnosticReports as $diagnosticReport) {
            $uuid = data_get($diagnosticReport, 'basedOnIdentifier');

            if (data_get($diagnosticReport, 'referralType') !== 'electronic' || blank($uuid)) {
                continue;
            }

            $referral = $this->getSearchedElectronicReferral($uuid);

            if ($referral !== null) {
                $referrals[] = [
                    'referral' => $referral,
                    'employee' => $diagnosticReportEmployee,
                ];
            }
        }

        $this->storeElectronicReferralsIfMissing($referrals);
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

        // The whole map is kept, as the existing episodes may be of a type the user cannot open themselves
        $this->episodeTypeClasses = config('ehealth.episode_type_encounter_classes', []);
    }

    /**
     * Show encounter classes based on legal entity and employee type.
     *
     * @return void
     */
    protected function adjustEncounterClasses(): void
    {
        $keys = array_intersect(
            config("ehealth.legal_entity_encounter_classes.$this->legalEntityType", []),
            config("ehealth.performer_employee_encounter_classes.$this->employeeType", [])
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

            return;
        }
    }

    protected function getSelectedEpisode(): void
    {
        $episodeId = data_get($this->form->episode, 'id');

        if (blank($episodeId)) {
            return;
        }

        $episode = MedicalEventsRepository::episode()->getByUuid($this->patient(), $episodeId);

        if ($episode !== null) {
            $this->episodes = [$episode];
        }
    }

    public function loadClinicalImpressionRuleDetails(): void
    {
        if (!empty($this->dictionaries['custom/rule_engine_rule_list'])) {
            return;
        }

        $ruleEngineRules = dictionary()->ruleEngineRules();
        $this->dictionaries['custom/rule_engine_rule_list'] = $ruleEngineRules->ruleList();
        $this->dictionaries['custom/rule_engine_details'] = $ruleEngineRules->details();
    }

    /**
     * Load dictionaries that are not part of the standard eHealth basic dictionary list.
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

        $this->dictionaries['custom/rule_engine_rule_list'] = [];
        $this->dictionaries['custom/rule_engine_details'] = [];

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
     * Adjust dictionaries by provided key and values.
     */
    private function adjustDictionary(string $dictionaryKey, array $allowedValues): void
    {
        $this->dictionaries[$dictionaryKey] = Arr::only($this->dictionaries[$dictionaryKey], $allowedValues);
    }
}
