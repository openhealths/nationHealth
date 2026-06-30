<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\Person\AuthenticationMethod;
use App\Enums\Person\AuthStep;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Livewire\Person\Forms\PersonForm as Form;
use App\Livewire\Person\Traits\InteractsWithAuthenticationMethods;
use App\Livewire\Person\Traits\ManagesConfidantPersonRelationships;
use App\Models\Person\Person;
use App\Models\Relations\AuthenticationMethod as AuthenticationMethodModel;
use App\Repositories\Repository;
use App\Traits\FormTrait;
use Exception;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Livewire\WithFileUploads;
use Throwable;

class PatientData extends BasePatientComponent
{
    use FormTrait;
    use InteractsWithAuthenticationMethods;
    use ManagesConfidantPersonRelationships;
    use WithFileUploads;

    public Form $form;

    public bool $isUnidentified = false;

    public string $firstName;

    public string $lastName;

    public array $phones = [];

    public array $confidantPersonRelationships = [];

    public bool $canManageConfidantRelationships = true;

    public bool $isIncapacitated = false;

    public array $confidantPerson = [];

    public ?string $selectedConfidantPersonId = null;

    public ?string $invalidPersonId = null;

    public array $newConfidantPerson = ['documentsRelationship' => []];

    /**
     * List of patient authentication methods.
     *
     * @var array
     */
    public array $authenticationMethods = [];

    public bool $showAuthMethodModal = false;

    public AuthStep $authStep = AuthStep::INITIAL;

    public ?string $phoneNumber = null;

    public int $code;

    public string $newPhoneNumber;

    public int $verificationCode;

    public string $requestId;

    public string $selectedAuthMethodUuid;

    public string $selectedAuthMethodType = '';

    public string $alias;

    public string $confidantPersonId;

    public string $confidantPersonRelationshipRequestId;

    public array $documentsRelationship = [];

    public bool $showSignatureDrawer = false;

    public bool $showAuthDrawer = false;

    public bool $showConfidantPersonDrawer = false;

    public bool $showDeactivateConfidantPersonDrawer = false;

    public array $confidantPersonRelationshipRequests = [];

    public array $approvedData;

    public bool $showTerminateModal = false;

    public ?string $authDrawerMode = null;

    public bool $showSignatureModal = false;

    public bool $showAdditionalParams = false;

    public array $uploadedDocuments = [];

    public array $uploadedFiles = [];

    public array $dictionaryNames = [
        'DOCUMENT_TYPE',
        'DOCUMENT_RELATIONSHIP_TYPE',
        'GENDER',
        'PHONE_TYPE'
    ];

    protected function initializeComponent(): void
    {
        $this->getDictionary();

        $patient = Person::with([
            'phones',
            'authenticationMethods',
            'confidantPersons.person.documents',
            'confidantPersons.person.phones',
            'confidantPersons.documentsRelationship'
        ])->whereId($this->personId)->firstOrFail();

        $this->firstName = $patient->firstName;
        $this->lastName = $patient->lastName;
        $this->phones = $patient->phones->toArray();
        $this->form->person = Arr::toCamelCase($patient->toArray());
        $this->confidantPersonRelationshipRequests = $this->loadConfidantPersonRelationshipRequests($patient);
        $this->form->uploadedDocuments = [];
        $this->refreshAuthenticationMethods($patient);
    }

    /**
     * Get patient verification status.
     *
     * @return void
     */
    public function getVerificationStatus(): void
    {
        try {
            $response = EHealth::person()->getPersonVerificationDetails($this->uuid);
            $validated = $response->validate();

            try {
                Repository::person()->updateVerificationStatusById($this->uuid, $validated['verification_status']);

                $this->verificationStatus = $validated['verification_status'];
            } catch (Exception $exception) {
                $this->handleDatabaseErrors($exception, 'Error when updating person verification status');

                return;
            }
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when getting person verification details');

            return;
        }
    }

    /**
     * Get patient confidant persons.
     *
     * @return void
     */
    public function getConfidantPersons(): void
    {
        try {
            $response = EHealth::person()->getConfidantPersonRelationships($this->uuid);

            $this->confidantPersonRelationships = Arr::toCamelCase($response->validate());
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when getting confidant person relationships');

            return;
        }
    }

    protected function uploadDocuments(): void
    {
        if (count($this->form->uploadedDocuments) !== count($this->uploadedDocuments)) {
            Session::flash('error', __('patients.messages.upload_all_files'));

            return;
        }

        foreach ($this->form->uploadedDocuments as $key => $document) {
            try {
                $mimeType = $document->getMimeType();
                $response = Http::withHeaders(['Content-Type' => $mimeType])
                    ->withBody(file_get_contents($document->getRealPath()), $mimeType)
                    ->put(trim($this->uploadedDocuments[$key]['url']));

                $this->uploadedFiles[$key] = $response->successful();
            } catch (Throwable) {
                $this->uploadedFiles[$key] = false;
            }
        }

        if (in_array(false, $this->uploadedFiles, true)) {
            Session::flash('error', __('messages.database_error'));

            return;
        }

        Session::flash('success', __('patients.messages.files_uploaded_successfully'));
    }

    private function refreshAuthenticationMethods(Person $patient): void
    {
        $confidantPersons = $patient->confidantPersons->keyBy(
            fn ($confidantPerson) => $confidantPerson->person->uuid
        );

        $this->authenticationMethods = $patient->authenticationMethods
            ->map(function (AuthenticationMethodModel $authenticationMethod) use ($confidantPersons): array {
                $method = Arr::toCamelCase($authenticationMethod->toArray());

                if ($method['type'] !== AuthenticationMethod::THIRD_PERSON->value) {
                    return $method;
                }

                $relationship = $confidantPersons->get($method['value']);

                if ($relationship === null) {
                    return $method;
                }

                $person = $relationship->person;
                $method['confidantPerson'] = [
                    'name' => $person->fullName,
                    'taxId' => $person->taxId,
                    'unzr' => $person->unzr,
                    'documentsPerson' => $person->documents->toArray(),
                    'phones' => $person->phones->first() === null
                        ? null
                        : ['number' => $person->phones->first()->number]
                ];

                return $method;
            })
            ->values()
            ->toArray();

        $this->phoneNumber = collect($this->authenticationMethods)
            ->firstWhere('type', AuthenticationMethod::OTP->value)['phoneNumber'] ?? null;
    }

    public function render(): View
    {
        return view('livewire.person.records.patient-data');
    }
}
