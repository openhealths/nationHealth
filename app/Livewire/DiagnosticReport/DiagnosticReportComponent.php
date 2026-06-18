<?php

declare(strict_types=1);

namespace App\Livewire\DiagnosticReport;

use App\Classes\Cipher\Api\CipherRequest;
use App\Classes\Cipher\Traits\Cipher;
use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\Person\DiagnosticReportStatus;
use App\Exceptions\Cipher\CipherConnectionException;
use App\Exceptions\Cipher\CipherException;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Services\MedicalEvents\Fhir;
use App\Livewire\DiagnosticReport\Forms\DiagnosticReportForm as Form;
use App\Models\Employee\Employee;
use App\Models\Equipment;
use App\Models\Icd10;
use App\Models\LegalEntity;
use App\Models\Person\Person;
use App\Repositories\ObservationConfigRepository;
use App\Repositories\Repository;
use App\Traits\FormTrait;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use RuntimeException;
use Throwable;

abstract class DiagnosticReportComponent extends Component
{
    use FormTrait;
    use Cipher;
    use WithFileUploads;

    public Form $form;

    public bool $showSignatureModal = false;

    /**
     * UUID of an existing report to reuse, or null to generate a new one.
     *
     * @var string|null
     */
    #[Locked]
    public ?string $diagnosticReportUuid = null;

    /**
     * ID of the patient for which create an encounter.
     *
     * @var int
     */
    #[Locked]
    public int $personId;

    /**
     * Patient UUID for API requests.
     *
     * @var string
     */
    public string $patientUuid;

    /**
     * Patient full name.
     *
     * @var string
     */
    public string $patientFullName;

    /**
     * List of employees of current legal entity.
     *
     * @var array
     */
    public array $employees;

    /**
     * List of authorized user's divisions.
     *
     * @var array
     */
    public array $divisions;

    /**
     * Full name of employee.
     *
     * @var string
     */
    public string $employeeFullName;

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
     * List of values for codeable concept.
     *
     * @var array
     */
    public array $codeableConceptValues;

    /**
     * Found the ICD-10 code and description.
     *
     * @var array
     */
    public array $results;

    /**
     * List of equipment options for combobox.
     *
     * @var array
     */
    public array $equipmentOptions = [];

    protected array $dictionaryNames = [
        'eHealth/diagnostic_report_categories',
        'eHealth/report_origins',
        'eHealth/observation_categories',
        'eHealth/ICF/observation_categories',
        'eHealth/LOINC/observation_codes',
        'eHealth/ICF/classifiers',
        'eHealth/ucum/units',
        'eHealth/ICF/qualifiers/extent_or_magnitude_of_impairment',
        'eHealth/observation_interpretations',
        'eHealth/ICF/qualifiers/nature_of_change_in_body_structure',
        'eHealth/ICF/qualifiers/anatomical_localization',
        'eHealth/ICF/qualifiers/performance',
        'eHealth/ICF/qualifiers/capacity',
        'eHealth/ICF/qualifiers/barrier_or_facilitator',
        'eHealth/observation_methods',
        'eHealth/body_sites',
        'GENDER',
        'eHealth/vaccination_covid_groups',
        'eHealth/custom/observation_codes',
        'POSITION'
    ];

    public function mount(LegalEntity $legalEntity, int $personId): void
    {
        $authUser = Auth::user();

        if (!$authUser) {
            throw new RuntimeException('Authenticated user not found');
        }

        $observationConfigRepository = Repository::observationConfig();

        $this->dictionaryNames = [
            ...$this->dictionaryNames,
            ...$observationConfigRepository->codeableConceptBindings()
        ];

        $this->getDictionary();

        try {
            $this->dictionaries['custom/services'] = dictionary()->services()->flattened()->toArray();
            $this->loadObservationDictionaries($observationConfigRepository);
        } catch (RuntimeException) {
            Log::channel('e_health_errors')
                ->error('Error while loading observation dictionary in DiagnosticReportComponent');
        }

        $this->personId = $personId;
        $this->employeeFullName = $authUser->getDiagnosticReportWriterEmployee()->fullName;

        $employees = $authUser->party->employees()
            ->select(['uuid', 'party_id', 'position'])
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

        $this->setPatientData();
        $this->divisions = $legalEntity->divisions()->select(['uuid', 'name'])->get()->toArray();

        $this->equipmentOptions = Equipment::query()
            ->whereLegalEntityId($legalEntity->id)
            ->active()
            ->with('names')
            ->get()
            ->map(static fn (Equipment $equipment) => [
                'uuid' => $equipment->uuid,
                'name' => $equipment->names->first()->name
            ])
            ->values()
            ->toArray();
    }

    /**
     * Store the current report data and open the signature modal.
     *
     * @param  array  $diagnosticReportData
     * @return void
     */
    public function openSignatureModal(array $diagnosticReportData): void
    {
        $this->form->diagnosticReport = $diagnosticReportData;
        $this->showSignatureModal = true;
    }

    /**
     * Search for ICD-10 in DB by the provided value.
     *
     * @param  string  $value
     * @return void
     */
    public function searchICD10(string $value): void
    {
        $this->results = Icd10::search($value)->limit(50)
            ->get(['code', 'description'])
            ->toArray();
    }

    /**
     * Set patient data.
     *
     * @return void
     */
    protected function setPatientData(): void
    {
        $patient = Person::select(['uuid', 'first_name', 'last_name', 'second_name'])
            ->whereId($this->personId)
            ->firstOrFail();

        $this->patientUuid = $patient->uuid;
        $this->patientFullName = $patient->fullName;
    }

    /**
     * Loads dictionaries and related mappings for observations.
     *
     * @param  ObservationConfigRepository  $observationConfigRepository
     * @return void
     */
    protected function loadObservationDictionaries(ObservationConfigRepository $observationConfigRepository): void
    {
        $this->dictionaries['eHealth/ICF/classifiers'] = dictionary()->basics()
            ->byName('eHealth/ICF/classifiers')
            ->flattenedChildValues()
            ->toArray();

        $this->observationLoincCodeMap = $observationConfigRepository->loincCodeMap();
        $this->observationCustomCodeMap = $observationConfigRepository->customCodeMap();
        $this->observationValueMap = $observationConfigRepository->valueMap();

        $this->codeableConceptValues = collect($this->observationValueMap)
            ->filter(static fn (array $value) => $value[1] === 'valueCodeableConcept')
            ->mapWithKeys(fn (array $value) => [
                $value[0] => $this->dictionaries[$value[0]] ?? []
            ])
            ->toArray();
    }

    /**
     * Prepare formatted data.
     *
     * @param  array  $validatedData
     * @param  DiagnosticReportStatus  $status
     * @param  string|null  $diagnosticReportUuid
     * @return array
     */
    protected function prepareFormattedData(
        array $validatedData,
        DiagnosticReportStatus $status,
        ?string $diagnosticReportUuid = null
    ): array {
        $uuids = [
            'employee' => Auth::user()->getDiagnosticReportWriterEmployee()->uuid,
            'diagnosticReport' => $diagnosticReportUuid ?? Str::uuid()->toString(),
        ];

        $diagnosticReport = Fhir::diagnosticReport()->toFhir(
            $validatedData['diagnosticReport'],
            $uuids,
            $status
        );

        $observations = collect($validatedData['observations'] ?? [])
            ->map(fn (array $observation) => Fhir::observation()->toFhir($observation, $uuids))
            ->values()
            ->toArray();

        return [
            'diagnosticReport' => $diagnosticReport,
            'observations' => $observations,
        ];
    }

    /**
     * Validate and persist the report as a draft.
     *
     * @param  array  $diagnosticReportData
     * @return void
     */
    public function save(array $diagnosticReportData): void
    {
        $formattedData = $this->buildFormattedData($diagnosticReportData, DiagnosticReportStatus::DRAFT);

        if ($formattedData === null) {
            return;
        }

        try {
            $diagnosticReportId = $this->persist($formattedData);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while saving diagnostic report');

            return;
        }

        Session::flash('success', __('patients.messages.diagnostic_report_draft_saved'));
        $this->redirectRoute(
            'diagnostic-report.edit',
            [legalEntity(), 'personId' => $this->personId, 'diagnosticReportId' => $diagnosticReportId],
            navigate: true
        );
    }

    /**
     * Validate, sign with Cipher, submit to eHealth and persist the report.
     *
     * @return void
     */
    public function sign(): void
    {
        try {
            $validatedCipher = $this->form->validate($this->form->signingRules());
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $formattedData = $this->buildFormattedData($this->form->diagnosticReport, DiagnosticReportStatus::FINAL);

        if ($formattedData === null) {
            return;
        }

        try {
            $signedContent = new CipherRequest()->signData(
                Arr::toSnakeCase($formattedData),
                $validatedCipher['knedp'],
                $validatedCipher['keyContainerUpload'],
                $validatedCipher['password'],
                Auth::user()->party->taxId
            );
        } catch (CipherException|CipherConnectionException $exception) {
            $exception->handle('Error when signing diagnostic report with Cipher');

            return;
        }

        try {
            EHealth::diagnosticReport()->create($this->patientUuid, ['signed_data' => $signedContent->getBase64Data()]);

            $diagnosticReportId = $this->persist($formattedData);

            Session::flash('success', __('patients.messages.diagnostic_report_create_request_sent'));
            $this->redirectRoute(
                'diagnostic-report.edit',
                [legalEntity(), 'personId' => $this->personId, 'diagnosticReportId' => $diagnosticReportId],
                navigate: true
            );
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when signing diagnostic report');

            return;
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while saving diagnostic report');

            return;
        }
    }

    /**
     * Assign report data to the form, validate it and build formatted FHIR data.
     *
     * @param  array  $diagnosticReportData
     * @param  DiagnosticReportStatus  $status
     * @return array|null
     */
    protected function buildFormattedData(array $diagnosticReportData, DiagnosticReportStatus $status): ?array
    {
        $this->form->diagnosticReport = $diagnosticReportData;

        try {
            $validated = $this->form->validate();
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return null;
        }

        return $this->prepareFormattedData($validated, $status, $this->diagnosticReportUuid);
    }

    /**
     * Persist the formatted report and return its identifier for redirect.
     *
     * @param  array  $formattedData
     * @return int|string
     * @throws Throwable
     */
    abstract protected function persist(array $formattedData): int|string;
}
