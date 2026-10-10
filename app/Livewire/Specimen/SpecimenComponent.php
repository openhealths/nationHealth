<?php

declare(strict_types=1);

namespace App\Livewire\Specimen;

use App\Classes\Cipher\Api\CipherRequest;
use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Dto\Specimen\Ehealth as SpecimenEhealth;
use App\Enums\Specimen\Status;
use App\Exceptions\Cipher\CipherConnectionException;
use App\Exceptions\Cipher\CipherException;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Livewire\Specimen\Forms\SpecimenForm;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Specimen;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\Repository;
use App\Traits\FormTrait;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Throwable;

abstract class SpecimenComponent extends Component
{
    use FormTrait;
    use WithFileUploads;

    public SpecimenForm $form;

    #[Locked]
    public ?int $personId = null;

    #[Locked]
    public ?int $prepersonId = null;

    #[Locked]
    public ?string $patientUuid = null;

    /**
     * UUID of the stored draft the form is filled from, null while the specimen is not stored yet.
     *
     * @var string|null
     */
    #[Locked]
    public ?string $specimenId = null;

    public ?string $patientFullName = null;

    public array $specimens = [];

    public array $employees = [];

    /**
     * Employees of the user the specimen can be registered by.
     *
     * @var array
     */
    public array $registeredByEmployees = [];

    public bool $showSignatureModal = false;

    protected array $dictionaryNames = [
        'specimen_types',
        'specimen_conditions',
        'specimen_collection_methods',
        'specimen_container_types',
        'specimen_container_additives',
        'fasting_statuses',
        'eHealth/body_sites',
        'eHealth/ucum/units',
        'POSITION'
    ];

    /**
     * Request-scoped memoized patient model.
     *
     * @var Person|Preperson|null
     */
    private Person|Preperson|null $patientModel = null;

    /**
     * Component mount.
     *
     * @param  LegalEntity  $legalEntity
     * @param  Person|null  $person
     * @param  Preperson|null  $preperson
     * @return void
     */
    public function mount(LegalEntity $legalEntity, ?Person $person = null, ?Preperson $preperson = null): void
    {
        if ($preperson !== null) {
            $this->prepersonId = $preperson->id;
        } else {
            $this->personId = $person->id;
        }

        $this->getDictionary();

        $patient = $this->patient();
        $this->patientUuid = $patient->uuid;
        $this->patientFullName = $patient->fullName;

        $employees = Employee::whereLegalEntityId($legalEntity->id)
            ->active()
            ->select(['uuid', 'party_id', 'position'])
            ->with('party:id,last_name,first_name,second_name')
            ->get();

        $toOption = static fn (Employee $employee): array => [
            'uuid' => $employee->uuid,
            'name' => $employee->fullName,
            'position' => $employee->position
        ];

        $this->employees = $employees->map($toOption)->values()->toArray();

        // The specimen is registered by the user themselves, so only their own employees are offered
        $this->registeredByEmployees = $employees->where('partyId', Auth::user()->partyId)
            ->map($toOption)
            ->values()
            ->toArray();

        $registeredById = $this->registeredByEmployees[0]['uuid'] ?? '';
        $this->form->specimen = [
            'registeredById' => $registeredById,
            'collectorId' => $registeredById
        ];

        $this->specimens = Specimen::forPatient($patient)
            ->whereStatus(Status::AVAILABLE)
            ->with('type.coding')
            ->get(['id', 'uuid', 'accession_identifier', 'status', 'type_id'])
            ->map(static fn (Specimen $specimen): array => [
                'uuid' => $specimen->uuid,
                'accessionIdentifier' => $specimen->accessionIdentifier,
                'status' => $specimen->status->value,
                'typeCode' => $specimen->type->coding->first()?->code ?? ''
            ])
            ->values()
            ->toArray();
    }

    /**
     * Validate the specimen and store it as a draft.
     *
     * @return void
     */
    public function save(): void
    {
        if (Auth::user()->cannot('create', Specimen::class)) {
            Session::flash('error', __('specimens.policy.create'));

            return;
        }

        try {
            $specimen = $this->form->validate()['specimen'];
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $mappingForm = clone $this->form;
        $mappingForm->specimen = $specimen;
        $fhirSpecimen = app(ObjectMapperInterface::class)->map($mappingForm, new SpecimenEhealth(
            id: $this->specimenId ?? Str::uuid()->toString(),
            legalEntity: legalEntity()->uuid,
            employee: $specimen['registeredById'],
        ))->toArray();
        $fhirSpecimen['status'] = Status::DRAFT->value;

        try {
            Repository::specimen()->store([$fhirSpecimen], $this->patient());
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to store specimen draft');

            return;
        }

        Session::flash('success', __('specimens.messages.draft_saved'));

        $specimenId = Specimen::whereUuid($fhirSpecimen['id'])->value('id');

        if ($this->prepersonId !== null) {
            $this->redirectRoute(
                'prepersons.specimens.edit',
                [legalEntity(), 'preperson' => $this->prepersonId, 'specimenId' => $specimenId],
                navigate: true
            );

            return;
        }

        $this->redirectRoute(
            'persons.specimens.edit',
            [legalEntity(), 'person' => $this->personId, 'specimenId' => $specimenId],
            navigate: true
        );
    }

    /**
     * Validate the specimen, sign it, send it to create and store it locally.
     *
     * @return void
     */
    public function sign(): void
    {
        if (Auth::user()->cannot('create', Specimen::class)) {
            Session::flash('error', __('specimens.policy.create'));

            return;
        }

        try {
            $specimen = $this->form->validate()['specimen'];
            $validatedCipher = $this->form->validate($this->form->signingRules());
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $mappingForm = clone $this->form;
        $mappingForm->specimen = $specimen;
        $fhirSpecimen = app(ObjectMapperInterface::class)->map($mappingForm, new SpecimenEhealth(
            id: $this->specimenId ?? Str::uuid()->toString(),
            legalEntity: legalEntity()->uuid,
            employee: $specimen['registeredById'],
        ))->toArray();

        try {
            $signedContent = new CipherRequest()->signData(
                Arr::toSnakeCase($fhirSpecimen),
                $validatedCipher['knedp'],
                $validatedCipher['keyContainerUpload'],
                $validatedCipher['password'],
                Auth::user()->party->taxId
            );
        } catch (CipherException|CipherConnectionException $exception) {
            $exception->handle('Error while signing specimen');

            return;
        } finally {
            $this->form->resetSigningFields();
        }

        try {
            $response = EHealth::specimen()->create($this->patientUuid, [
                'signed_data' => $signedContent->getBase64Data(),
                'signed_data_encoding' => 'base64'
            ]);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while sending specimen');

            return;
        }

        logger()->debug('Job ID to further debug', $response->getData());

        // The stored draft is replaced by the signed specimen, so it can not be signed a second time
        try {
            Repository::specimen()->store([$fhirSpecimen], $this->patient());
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to store signed specimen');

            return;
        }

        Session::flash('success', __('specimens.messages.create_request_sent'));

        if ($this->prepersonId !== null) {
            $this->redirectRoute(
                'prepersons.specimens',
                [legalEntity(), 'preperson' => $this->prepersonId],
                navigate: true
            );

            return;
        }

        $this->redirectRoute('persons.specimens', [legalEntity(), 'person' => $this->personId], navigate: true);
    }

    /**
     * Resolve the patient model (person or preperson) the specimen is created for.
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
     * Render the component.
     *
     * @return View
     */
    public function render(): View
    {
        return view('livewire.specimen.specimen-create');
    }
}
