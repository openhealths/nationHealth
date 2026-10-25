<?php

declare(strict_types=1);

namespace App\Livewire\Procedure;

use App\Classes\eHealth\EHealth;
use App\Classes\Cipher\Api\CipherRequest;
use App\Core\Arr;
use App\Enums\Person\ProcedureStatus;
use App\Enums\Status;
use App\Enums\User\Role;
use App\Jobs\RemoteEHealthLinksProcessing;
use App\Enums\Equipment\AvailabilityStatus;
use App\Livewire\Procedure\Forms\ProcedureForm as Form;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\Person\Person;
use App\Models\Equipment;
use App\Models\Preperson;
use App\Models\MedicalEvents\Sql\Procedure;
use App\Traits\SearchesElectronicReferrals;
use App\Dto\Procedure\Ehealth as ProcedureEhealth;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use App\Traits\FormTrait;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Exceptions\Cipher\CipherConnectionException;
use App\Exceptions\Cipher\CipherException;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

class ProcedureComponent extends Component
{
    use FormTrait;
    use SearchesElectronicReferrals;
    use WithFileUploads;

    public Form $form;

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
     * Full name of employee.
     *
     * @var string
     */
    public string $employeeFullName;

    /**
     * List of employees available as procedure performers.
     *
     * @var array
     */
    public array $procedureEmployees = [];

    public array $availableReferrals = [];

    public bool $showSignatureModal = false;

    #[Locked]
    public ?string $procedureUuid = null;

    public array $equipmentOptions = [];

    public array $equipmentOptionsByDivision = [];

    /**
     * Whether the procedure can't be changed: it is viewed, accepted by eHealth or waiting for it.
     *
     * @var bool
     */
    #[Locked]
    public bool $isReadonly = false;

    protected array $dictionaryNames = [
        'eHealth/procedure_categories',
        'eHealth/procedure_outcomes',
        'eHealth/report_origins',
        'eHealth/LOINC/observation_codes',
        'eHealth/ICF/classifiers',
        'eHealth/ICPC2/condition_codes',
        'eHealth/assistive_products',
        'procedure_focal_device_actions',
        'POSITION'
    ];

    public function boot(): void
    {
        $icd10Cache = $this->dictionaries['eHealth/ICD10_AM/condition_codes'] ?? [];

        $this->getDictionary();

        $this->dictionaries['eHealth/ICD10_AM/condition_codes'] = $icd10Cache;

        $this->dictionaries['custom/services'] = dictionary()->services()->flattened()->toArray();

        $this->dictionaries['eHealth/assistive_products'] = dictionary()->basics()
            ->byName('eHealth/assistive_products')
            ->flattenedChildValues(true)
            ->toArray();
    }

    public function mount(LegalEntity $legalEntity, ?Person $person = null, ?Preperson $preperson = null): void
    {
        if ($preperson !== null) {
            $this->prepersonId = $preperson->id;
        } else {
            $this->personId = $person->id;
        }

        $this->employeeFullName = Auth::user()->getProcedureWriterEmployee()->fullName;

        $this->setPatientData();

        // Get all active divisions of current legal entity
        $this->divisions = $legalEntity->divisions()
            ->whereStatus(Status::ACTIVE)
            ->whereIsActive(true)
            ->select(['uuid', 'name'])
            ->get()
            ->toArray();

        $this->equipmentOptions = Equipment::query()
            ->whereLegalEntityId($legalEntity->id)
            ->active()
            ->where('availability_status', AvailabilityStatus::AVAILABLE)
            ->with(['names', 'division:id,uuid,name'])
            ->get()
            ->map(static function (Equipment $equipment) {
                $name = $equipment->names->first()?->name ?? $equipment->uuid;

                return [
                    'uuid' => $equipment->uuid,
                    'name' => $name,
                    'divisionUuid' => $equipment->division?->uuid,
                ];
            })
            ->values()
            ->toArray();

        $this->equipmentOptionsByDivision = collect($this->equipmentOptions)
            ->filter(static fn (array $equipment) => !empty($equipment['divisionUuid']))
            ->groupBy('divisionUuid')
            ->map(static fn ($items) => $items->values()->toArray())
            ->toArray();

        $this->procedureEmployees = Employee::query()
            ->whereLegalEntityId($legalEntity->id)
            ->whereStatus(Status::APPROVED)
            ->whereIsActive(true)
            ->whereIn('employee_type', [
                Role::DOCTOR->value,
                Role::SPECIALIST->value,
                Role::ASSISTANT->value,
            ])
            ->select([
                'uuid',
                'party_id',
                'position',
                'division_uuid',
            ])
            ->with('party:id,last_name,first_name,second_name')
            ->get()
            ->map(static fn (Employee $employee): array => [
                'uuid' => $employee->uuid,
                'name' => $employee->fullName,
                'position' => $employee->position,
                'divisionUuid' => $employee->divisionUuid,
            ])
            ->values()
            ->toArray();
    }

    public function openSignatureModal(array $procedureData): void
    {
        $this->form->procedure = $procedureData;
        $this->showSignatureModal = true;
    }

    protected function prepareFormattedData(
        array $validatedData,
        ?string $procedureUuid = null
    ): array {
        $uuids = [
            'employee' => Auth::user()->getProcedureWriterEmployee()->uuid,
            'procedure' => $procedureUuid ?? Str::uuid()->toString(),
        ];

        $mappingForm = clone $this->form;
        $mappingForm->procedure = $validatedData['procedure'];

        return Arr::toCamelCase(app(ObjectMapperInterface::class)->map($mappingForm, new ProcedureEhealth(
            id: $uuids['procedure'],
            legalEntity: legalEntity()->uuid,
            employee: $uuids['employee'],
        ))->toArray());
    }

    /**
     * Validate and store the procedure locally as a draft, without sending it to eHealth.
     *
     * @param  array  $procedureData
     * @return void
     */
    public function save(array $procedureData): void
    {
        if ($this->isReadonly) {
            return;
        }

        $formattedData = $this->buildFormattedData($procedureData);

        if ($formattedData === null) {
            return;
        }

        $formattedData['status'] = ProcedureStatus::DRAFT->value;

        try {
            $procedureId = $this->persist($formattedData);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while saving procedure');

            return;
        }

        Session::flash('success', __('procedures.messages.saved'));

        $this->redirectToPage($procedureId, 'edit');
    }

    public function sign(): void
    {
        if ($this->isReadonly) {
            return;
        }

        try {
            $validatedCipher = $this->form->validate($this->form->signingRules());
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $formattedData = $this->buildFormattedData($this->form->procedure);

        if ($formattedData === null) {
            return;
        }

        $requestData = Arr::toSnakeCase($formattedData);

        try {
            $signedContent = new CipherRequest()->signData(
                $requestData,
                $validatedCipher['knedp'],
                $validatedCipher['keyContainerUpload'],
                $validatedCipher['password'],
                Auth::user()->party->taxId
            );
        } catch (CipherException|CipherConnectionException $exception) {
            $exception->handle('Error when signing procedure with Cipher');
            Session::flash('error-modal', $exception->getMessage());

            return;
        }

        // The procedure is kept as a draft until eHealth accepts it
        $formattedData['status'] = ProcedureStatus::DRAFT->value;

        try {
            $procedureId = $this->persist($formattedData);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while saving procedure');
            Session::flash('error-modal', $exception->getMessage());

            return;
        }

        try {
            $submission = RemoteEHealthLinksProcessing::startSubmission(Procedure::findOrFail($procedureId), $requestData);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while starting procedure submission');
            $this->redirectToPage($procedureId, 'view');

            return;
        }

        try {
            $response = EHealth::procedure()->create($this->patientUuid, ['signed_data' => $signedContent->getBase64Data()]);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when signing procedure');
            RemoteEHealthLinksProcessing::markSubmissionFailed($submission);
            $this->redirectToPage($procedureId, 'view');

            return;
        }

        // eHealth received the procedure already, so it stays submitted and locked even if following it fails
        try {
            RemoteEHealthLinksProcessing::track($submission, $response->validate());

            Session::flash('success', __('procedures.messages.create_request_sent'));
        } catch (Throwable $exception) {
            Log::channel('e_health_errors')->error('Submitted procedure is not tracked', [
                'procedureId' => $procedureId,
                'response' => $response->getData(),
                'exception' => $exception->getMessage()
            ]);
            Session::flash('error', __('errors.ehealth.messages.submission_not_tracked'));
        }

        $this->redirectToPage($procedureId, 'view');
    }

    /**
     * Open the given page, 'edit' or 'view', of the stored procedure.
     *
     * @param  int  $procedureId
     * @param  string  $page
     * @return void
     */
    protected function redirectToPage(int $procedureId, string $page): void
    {
        if ($this->prepersonId !== null) {
            $this->redirectRoute(
                "prepersons.procedure.$page",
                [legalEntity(), 'preperson' => $this->prepersonId, 'procedure' => $procedureId],
                navigate: true
            );

            return;
        }

        $this->redirectRoute(
            "procedure.$page",
            [legalEntity(), 'person' => $this->personId, 'procedure' => $procedureId],
            navigate: true
        );
    }

    protected function buildFormattedData(array $procedureData): ?array
    {
        $this->form->procedure = $procedureData;

        try {
            $validated = $this->form->validate();
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return null;
        }

        return $this->prepareFormattedData($validated, $this->procedureUuid);
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
            : Person::findOrFail($this->personId));
    }

    /**
     * Set patient data.
     *
     * @return void
     */
    protected function setPatientData(): void
    {
        $patient = $this->patient();

        $this->patientUuid = $patient->uuid;
        $this->patientFullName = $patient->fullName;
    }

    /**
     * Get all episodes for current patient.
     *
     * @return void
     */
    public function getEpisodes(): void
    {
        try {
            $response = EHealth::episode()->getBySearchParams(
                $this->patientUuid,
                ['managing_organization_id' => legalEntity()->uuid]
            );
            $this->episodes = collect($response->getData())
                ->map(static fn (array $item) => Arr::only($item, ['id', 'name', 'status', 'inserted_at']))
                ->toArray();
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when getting episodes');

            return;
        }
    }
}
