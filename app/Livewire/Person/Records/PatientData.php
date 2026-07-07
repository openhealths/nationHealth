<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use DB;
use Auth;
use Throwable;
use Exception;
use App\Core\Arr;
use App\Enums\Status;
use App\Traits\FormTrait;
use App\Traits\HasApproval;
use App\Models\Person\Person;
use Livewire\WithFileUploads;
use App\Enums\Person\AuthStep;
use App\Classes\eHealth\EHealth;
use App\Repositories\Repository;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Http;
use App\Traits\BatchLegalEntityQueries;
use Illuminate\Support\Facades\Session;
use App\Models\MedicalEvents\Sql\Approval;
use App\Enums\Person\AuthenticationMethod;
use App\Exceptions\EHealth\EHealthException;
use Illuminate\Validation\ValidationException;
use App\Livewire\Person\Forms\PersonForm as Form;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Repositories\MedicalEvents\Repository as MERepository;
use App\Livewire\Person\Traits\InteractsWithAuthenticationMethods;
use App\Livewire\Person\Traits\ManagesConfidantPersonRelationships;
use App\Models\Relations\AuthenticationMethod as AuthenticationMethodModel;

class PatientData extends BasePatientComponent
{
    use FormTrait;
    use InteractsWithAuthenticationMethods;
    use ManagesConfidantPersonRelationships;
    use WithFileUploads;
    use BatchLegalEntityQueries;
    use HasApproval;

    public Form $form;

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
        'PHONE_TYPE',
        'SETTLEMENT_TYPE'
    ];

    public bool $showConfirmationUpdateModal = false;

    /**
     * Status of sync person data with eHealth.
     * {true}: when we get person data from eHealth and update local person data,
     * {false}: when we didn't do it yet or sync isn't needed.
     *
     * @var bool
     */
    public bool $isSyncing = false;

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

        if (($this->isSyncing = $patient->isSyncing)) {
            $existingApproval = Approval::getByModel($this->personId, Person::class)
                ->whereStatus(Status::APPROVED->value)
                ->whereNotNull('uuid')
                ->first();

            if ($existingApproval && !$existingApproval->isAlive()->exists()) {
                $existingApproval->update(['status' => Status::EXPIRED->value]);

                $patient->isSyncing = false;
                $patient->save();

                $this->isSyncing = false;
            }
        }
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

    /**
     * Initiate or resume the person data synchronisation flow with eHealth.
     *
     * **When a sync is already in progress** ({@see $isSyncing} is `true` and an
     * {@see Status::APPROVED} approval exists), the method inspects the approval state:
     * - Expired approval → marks it {@see Status::EXPIRED} and flashes an error.
     * - Alive but unverified → re-sends the OTP if the SMS window has closed, then
     *   re-opens the confirmation modal.
     * - Alive and verified → proceeds immediately to {@see syncPersonDataAfterGetApproval()}.
     *
     * **When no sync is in progress**, a new approval is created via
     * {@see HasApproval::createApproval()} using the best available authentication method
     * (OTP → THIRD_PERSON → OFFLINE). On success, {@see $isSyncing} is set to `true`,
     * persisted, and the confirmation modal is opened so the user can enter the OTP.
     *
     * @return void
     */
    public function syncPersonDataFromEHealth(): void
    {
        // Check if an Approval already exists for the current person and is in the APPROVED state
        $existingApproval = Approval::getByModel($this->personId, Person::class)
                ->whereStatus(Status::APPROVED->value)
                ->whereNotNull('uuid')
                ->first();

        // If syncing is already in progress, one needs to check the status of the existing approval.
        if ($this->isSyncing && $existingApproval) {
            // If approval exists, one needs to check if it's still alive
            if (!$existingApproval->isAlive()->exists()) {
                $existingApproval->update(['status' => Status::EXPIRED->value]);

                Session::flash('error', __('patients.errors.approval_expired'));

                // If it is alive but not verified, one needs to resend the SMS code or/and show the confirmation modal.
            } else if (!$existingApproval->isVerified()->exists()) {
                // Check if the approval sms code still active (14 min from last Approvals's updated_at)
                if (!MERepository::approval()->isSmsCodeAlive($existingApproval)) {
                    $this->resendApprovalSms($existingApproval);
                }

                $this->showConfirmationUpdateModal = true;
            } else {
                // At least if the approval is alive and verified, one can proceed to sync the person data from eHealth.
                $this->syncPersonDataAfterGetApproval();
            }

            return;
        }

        $authorizeWith = collect($this->authenticationMethods)->firstWhere('type', AuthenticationMethod::OTP->value) ??
            collect($this->authenticationMethods)->firstWhere('type', AuthenticationMethod::THIRD_PERSON->value) ??
            collect($this->authenticationMethods)->firstWhere('type', AuthenticationMethod::OFFLINE->value) ?? null;

        $employee = Auth::user()->activeDoctorEmployee();
        $person = Person::find($this->personId);

        $payloadData = [
            'granted_to' => ['value' => $employee->uuid, 'type' => ['coding' => [['code' => 'employee']]]],
            'created_by' => ['value' => $employee->uuid, 'type' => ['coding' => [['code' => 'employee']]]],
            'person' => ['value' => $this->uuid, 'type' => ['coding' => [['code' => 'person']]]],
            'authorize_with' => $authorizeWith
        ];

        try {
            $this->createApproval($person, $payloadData);
        } catch (Throwable $error) {
            Session::flash('error', __('patients.errors.approval_creation_failed'));

            Log::error('Approval creation failed', [
                'person_uuid' => $person->uuid,
                'employee_uuid' => $employee->uuid,
                'payload_data' => $payloadData,
                'error' => $error->getMessage()
            ]);

            return;
        }

        $this->isSyncing = true;

        Repository::person()->updateSynchronizationStatusById($this->personId, $this->isSyncing);

        $this->showConfirmationUpdateModal = true;
    }

    /**
     * Resend the SMS verification code for the current person's data update approval.
     *
     * Resolves the {@see Person} model for the current component by {@see $personId} and
     * delegates to {@see HasApproval::resendApprovalSms()}, which looks up the most recent
     * {@see Status::APPROVED} approval and re-sends the OTP via eHealth.
     *
     * @return void
     */
    public function resendApprovalSmsCode(): void
    {
        $person = Person::find($this->personId);

        $this->resendApprovalSms($person);
    }

    /**
     * Validate the SMS verification code and approve the personal data update request.
     *
     * Closes the confirmation modal, validates the submitted verification code via
     * {@see Form::rulesForApprove()}, then calls {@see HasApproval::verifyApproval()} to
     * confirm the approval with eHealth.
     *
     * On success, proceeds to sync the person's data via {@see syncPersonDataAfterGetApproval()};
     * on failure, flashes an error message.
     *
     * @return void
     */
    public function approvePersonalDataUpdate(): void
    {
        $this->showConfirmationUpdateModal = false;

        try {
            $validated = $this->form->validate($this->form->rulesForApprove());
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());

            return;
        }

        $person = Person::find($this->personId);

        if ($this->verifyApproval($person, $validated['verificationCode'])) {
            $this->syncPersonDataAfterGetApproval();
        } else {
            Session::flash('error', __('patients.errors.approval_verification_failed'));
        }
    }

    /**
     * Sync person data from eHealth after a successful approval.
     *
     * Fetches the patient's personal data from eHealth using the current person UUID,
     * persists it locally via a database transaction, resets the syncing flag, and
     * re-initializes the component via {@see initializeComponent()} so that all public
     * properties (name, phones, authentication methods, etc.) reflect the updated data
     * and the component re-renders.
     *
     * Aborts early if the authenticated user lacks the {@see \App\Policies\PersonPolicy::syncPersonData()}
     * permission, if the eHealth request fails, or if the database transaction throws an exception.
     *
     * @return void
     */
    protected function syncPersonDataAfterGetApproval(): void
    {
        if (Auth::user()->cannot('syncPersonData', Person::class)) {
            Session::flash('error', __('patients.policy.personal_data_update'));

            return;
        }

        try {
            $response = EHealth::person()->getPersonalData($this->uuid);

            $validated = $response->validate();
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle(__('Error occurred while trying to get person data: ' . $this->uuid));

            return;
        }

        $confidantPersonData = Arr::pull($validated, 'confidant_person', []);

        $personalData = Arr::except($validated, ['preferred_way_communication']);

        try {
            DB::transaction(function () use ($personalData) {
                // Repository::declarationRequest()->syncPersonData($personalData);
                Repository::person()->sync($personalData, $this->uuid);

                // TODO: check how the confdiant person relationships are synced with eHealth
                // if (!empty($personalData['confidant_person'])) {
                //     Repository::confidantPerson()->sync($confidantPersonData, $this->uuid);
                // }
            });
        } catch (Exception $exception) {
            $this->handleDatabaseErrors($exception, __('Error occurred while trying to sync person data'));

            return;
        }

        $this->isSyncing = false;

        Repository::person()->updateSynchronizationStatusById($this->personId, $this->isSyncing);

        Session::flash('success', __('patients.sync_success'));

        $this->initializeComponent();
    }

    public function render(): View
    {
        return view('livewire.person.records.patient-data');
    }
}
