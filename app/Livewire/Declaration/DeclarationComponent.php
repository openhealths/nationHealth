<?php

declare(strict_types=1);

namespace App\Livewire\Declaration;

use App\Classes\Cipher\Api\CipherRequest;
use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\Declaration\RequestStatus;
use App\Enums\Declaration\Status;
use App\Enums\JobStatus;
use App\Enums\Person\AuthenticationMethod;
use App\Enums\User\Role;
use App\Exceptions\Cipher\CipherConnectionException;
use App\Exceptions\Cipher\CipherException;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Livewire\Declaration\Forms\DeclarationForm as Form;
use App\Models\Declaration;
use App\Models\DeclarationRequest;
use App\Models\Division;
use App\Models\Employee\Employee;
use App\Models\Person\Person;
use App\Notifications\DivisionUpdated;
use App\Notifications\LegalEntityUpdated;
use App\Repositories\Repository;
use App\Traits\FormTrait;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

abstract class DeclarationComponent extends Component
{
    use FormTrait;
    use WithFileUploads;

    private const int SMS_RESEND_LIMIT = 1;

    public bool $isNeedToResign = false;

    public Form $form;

    #[Locked]
    public $personId;

    public bool $showInformationMessageModal = false;
    public bool $showAuthModal = false;
    public bool $showSignModal = false;
    public bool $showSignatureModal = false;
    public bool $showUploadingDocumentsModal = false;
    public bool $showUpdatePersonDataModal = false;

    /**
     * Status of declaration request, that we use to determine which actions user can do with declaration request and which buttons show.
     *
     * @var RequestStatus
     */
    public RequestStatus $status = RequestStatus::DRAFT;

    public bool $isNeedToPersonUpdate = false;

    /**
     * Mark that the patient signed the printed declaration form, sent as 'patient_signed'.
     *
     * @var bool
     */
    public bool $isSigned = false;

    /**
     * Check is the request created and signed by the patient in their own cabinet.
     *
     * @var bool
     */
    public bool $isCreatedByPatient = false;

    /**
     * Content that formatted by eHealth that we propose to print.
     *
     * @var string
     */
    public string $printableContent;

    /**
     * List of documents that must be uploaded.
     *
     * @var array
     */
    public array $uploadedDocuments = [];

    /**
     * Data that we sign with Cipher and then send to EHealth
     *
     * @var array
     */
    public array $dataToBeSigned;

    /**
     * Patient full name.
     *
     * @var string
     */
    public string $patientFullName;

    /**
     * List of patient authentication methods.
     *
     * @var array
     */
    public array $authMethods;

    public array $employeesInfo;

    /**
     * Check is sms was resent.
     *
     * @var bool
     */
    public bool $smsResent = false;

    public array $dictionaryNames = ['POSITION'];

    /**
     * UUID of created declaration request.
     *
     * @var string
     */
    public string $declarationRequestUuid;

    /**
     * ID of created declaration request.
     *
     * @var null|int
     */
    public ?int $declarationRequestId = null;

    /**
     * Patient UUID, used for eHeath request.
     *
     * @var string
     */
    protected string $patientUuid;

    /**
     * Check is patient data still syncing.
     *
     * @var bool
     */
    public bool $isSyncing = false;

    public function boot(): void
    {
        $this->getDictionary();
    }

    protected function baseMount(int $personId): void
    {
        $patient = Person::select(['id', 'uuid', 'birth_date', 'is_syncing'])
            ->with('names')
            ->withExists('documents')
            ->whereId($personId)
            ->firstOrFail();

        $this->patientFullName = $patient->fullName;
        $this->personId = $personId;
        $this->patientUuid = $patient->uuid;

        $this->setEmployeesInfo();

        $this->form->personId = $this->patientUuid;
        $authMethods = collect($this->getPersonAuthMethods());
        $hasNoAuthMethod = $authMethods->contains('type', AuthenticationMethod::NA->value);

        // NA stands for the absence of a method, and an expired one cannot authenticate the declaration either,
        // so neither of them may be offered as an authentication option
        $this->authMethods = $authMethods
            ->reject(static fn (array $authMethod): bool => $authMethod['type'] === AuthenticationMethod::NA->value
                || (!empty($authMethod['ehealth_ended_at'])
                    && CarbonImmutable::parse($authMethod['ehealth_ended_at'])->isPast()))
            ->values()
            ->toArray();

        $this->form->authMethodIds = array_column($this->authMethods, 'uuid');

        $authMethodTypes = collect($this->authMethods)->pluck('type');
        $hasThirdPerson = $authMethodTypes->contains(AuthenticationMethod::THIRD_PERSON->value);

        // Use 'documents_exists' dynamic attribute (added by withExists) to determine if we need to update person data (for one haven't OTP authentication method).
        // A child below the self-authentication age can act only through a confidant person, whose presence is proven by the THIRD_PERSON method
        $this->isNeedToPersonUpdate = $hasNoAuthMethod
            || (!$patient->documents_exists
                && !$hasThirdPerson
                && !$authMethodTypes->contains(AuthenticationMethod::OTP->value))
            || ($patient->age < config('ehealth.no_self_auth_age') && !$hasThirdPerson);

        $this->isNeedToResign = Repository::declarationRequest()->checkIfNeedToResign($this->patientUuid);

        // The simplified flow signs the form built by the System, so there is no mark for the user to confirm
        $this->isSigned = $this->isNeedToResign;
        $this->isSyncing = $patient->isSyncing;
    }

    public function openSignatureModal(): void
    {
        $this->showSignModal = false;
        $this->showSignatureModal = true;
    }

    /**
     * Create a validated application(declaration request).
     *
     * @return void
     */
    public function create(): void
    {
        if (!$this->ensureAbility('create', __('declarations.policy.create'))) {
            return;
        }

        if ($this->isNeedToPersonUpdate) {
            $this->showUpdatePersonDataModal = true;

            return;
        }

        $this->setDivisionId();

        try {
            $validated = $this->form->validate($this->form->rulesForCreating());
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        try {
            $response = EHealth::declarationRequest()->create(removeEmptyKeys(Arr::toSnakeCase($validated)));
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when creating a declaration');

            return;
        }

        $responseUrgent = $response->getUrgent();

        try {
            $responseData = $response->validate();
            $responseData['sync_status'] = JobStatus::PARTIAL->value;

            // The user continues a locally saved draft, otherwise the request is stored from scratch
            if ($this->declarationRequestId) {
                $declarationRequest = DeclarationRequest::findOrFail($this->declarationRequestId);
            } else {
                $declarationRequest = Repository::declarationRequest()->store(Arr::toSnakeCase($validated));
                $this->declarationRequestId = $declarationRequest->id;
            }

            Repository::declarationRequest()->update($declarationRequest->id, $responseData);
        } catch (Exception $exception) {
            $this->handleDatabaseErrors($exception, 'Error saving declaration request after response');

            return;
        }

        $this->declarationRequestUuid = $responseData['id'];

        // Only the newly created request stays pending, the patient's earlier ones are cancelled
        Repository::declarationRequest()->cancelPendingRequests(
            $declarationRequest->personId,
            $declarationRequest->id
        );

        if ($responseUrgent['authentication_method_current']['type'] === AuthenticationMethod::OFFLINE->value) {
            $this->uploadedDocuments = $responseUrgent['documents'] ?? [];

            // The upload URLs come with this answer only, and the request is approved on the page opened next
            $declarationRequest->update(['documents' => $this->uploadedDocuments]);
        }

        Session::flash('success', __('declarations.request_created'));

        // Redirect to edit page after successfully creating new declaration request
        $this->redirectRoute(
            'declaration.edit',
            [legalEntity(), 'person' => $declarationRequest->person_id, 'declarationRequest' => $this->declarationRequestId],
            navigate: true
        );
    }

    /**
     * Show approve modal (for SMS or for uploading documents)
     *
     * @return void
     */
    public function openApproveModal(): void
    {
        $this->showInformationMessageModal = false;

        if (empty($this->uploadedDocuments)) {
            $this->showAuthModal = true;
        } else {
            $this->showUploadingDocumentsModal = true;
        }
    }

    /**
     * Send approving request with verified code.
     *
     * @return void
     */
    public function approve(): void
    {
        if (!$this->ensureAbility('approve', __('declarations.policy.approve'))) {
            return;
        }

        try {
            $validated = $this->form->validate($this->form->rulesForApproving());
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());

            return;
        }

        try {
            $response = EHealth::declarationRequest()->approve(
                $this->declarationRequestUuid,
                ['verification_code' => $validated['verificationCode']]
            );
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when approving a declaration');

            return;
        }

        try {
            $responseData = $response->validate();

            Repository::declarationRequest()->updateAfterApprove($responseData['id'], $responseData);

            $toBeSignedData = $responseData['data_to_be_signed'];
            DB::transaction(fn () => $this->syncDeclarationRelatedData($toBeSignedData));
        } catch (Exception|Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while approving declaration request');

            return;
        }

        $this->printableContent = $toBeSignedData['content'];
        $this->dataToBeSigned = $toBeSignedData;
        $this->showAuthModal = false;
        $this->showSignModal = true;

        $this->status = RequestStatus::APPROVED;

        Session::flash('success', __('declarations.request_approved'));
    }

    /**
     * Validate uploaded files.
     *
     * @param  string  $field
     * @return void
     */
    public function updated(string $field): void
    {
        if (str_starts_with($field, 'form.uploadedDocuments')) {
            $this->form->validate($this->form->rulesForUploadingDocuments());
        }
    }

    /**
     * Upload patient files to the appropriate URL.
     *
     * @return void
     * @throws ValidationException
     */
    public function sendFiles(): void
    {
        if (!$this->ensureAbility('approve', __('declarations.policy.approve'))) {
            return;
        }

        try {
            $this->form->validate($this->form->rulesForUploadingDocuments());
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());

            return;
        }

        $totalFiles = count($this->form->uploadedDocuments);
        // Check that all provided files were uploaded
        if ($totalFiles !== count($this->uploadedDocuments)) {
            Session::flash('error', __('declarations.upload_all_files'));

            return;
        }

        $successCount = 0;
        foreach ($this->form->uploadedDocuments as $key => $document) {
            try {
                $response = EHealth::declarationRequest()->uploadDocument(
                    $this->uploadedDocuments[$key]['url'],
                    $document
                );

                if ($response->getStatusCode() === 200) {
                    $successCount++;
                } else {
                    logger()?->error('Error while uploading document', ['body' => $response->getBody()]);
                    Session::flash('error', __('declarations.document_upload_failed'));
                }
            } catch (EHealthException|EHealthConnectionException $exception) {
                $exception->handle('Error while uploading document');

                return;
            }
        }

        // Approve if all files were uploaded successfully
        if ($successCount === $totalFiles) {
            try {
                $this->approveUploadedFiles();
            } catch (EHealthException|EHealthConnectionException $exception) {
                $exception->handle('Error when approving a declaration after sending files');

                return;
            }
        }
    }

    /**
     * Resend SMS to patient.
     *
     * @return void
     */
    public function resendSms(): void
    {
        $rateLimitKey = 'resend-sms-declaration-request:' . Auth::id() . ':' . $this->declarationRequestUuid;

        // Check if SMS has already been resent in this session (single resend rule)
        if (RateLimiter::tooManyAttempts($rateLimitKey, self::SMS_RESEND_LIMIT)) {
            $this->smsResent = true;
            Session::flash('error', __('validation.custom.person.sms_already_resent'));

            return;
        }

        try {
            $response = EHealth::declarationRequest()->resendAuthOtp($this->declarationRequestUuid);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when resending sms to person');

            return;
        }

        $response->validate();

        // Mark SMS as sent for this session (no expiration - persists until cache clear)
        RateLimiter::hit($rateLimitKey);

        $this->smsResent = true;
        Session::flash('success', __('patients.messages.sms_sent_successfully'));
    }

    /**
     * Approve a declaration request created from a reorganized declaration.
     *
     * Finds the pending NEW declaration request of the current legal entity for the current person that has a parent
     * declaration UUID (i.e. belongs to a reorganized legal entity), approves it via eHealth, opens the sign modal,
     * and refreshes the local declaration request instance so the computed status reflects the new state.
     *
     * @return void
     */
    public function approveSimplifiedDeclaration(): void
    {
        if (!$this->ensureAbility('approve', __('declarations.policy.approve'))) {
            return;
        }

        $resignedDeclarationRequest = DeclarationRequest::where('person_id', $this->personId)
            ->filterByLegalEntityId(legalEntity()->id)
            ->where('status', RequestStatus::NEW->value)
            ->whereNotNull('parent_declaration_uuid')
            ->firstOrFail();

        $this->declarationRequestUuid = $resignedDeclarationRequest->uuid;

        $this->approveUploadedFiles();

        $this->showSignModal = true;

        $this->status = RequestStatus::APPROVED;
    }

    /**
     * Send approve request if all files were uploaded successfully
     *
     * @return void
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    protected function approveUploadedFiles(): void
    {
        $response = EHealth::declarationRequest()->approve($this->declarationRequestUuid);

        try {
            $responseData = $response->validate();

            Repository::declarationRequest()->updateAfterApprove(
                $responseData['id'],
                $responseData
            );

            $toBeSignedData = $responseData['data_to_be_signed'];

            // Simplify resigned declaration don't need to sync person data
            if (!$this->isNeedToResign) {
                DB::transaction(fn () => $this->syncDeclarationRelatedData($toBeSignedData));
            }
        } catch (Exception|Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while approving uploaded declaration request');

            return;
        }

        $this->printableContent = $toBeSignedData['content'];
        $this->dataToBeSigned = $toBeSignedData;
        $this->showUploadingDocumentsModal = false;
        $this->showSignModal = true;

        $this->status = RequestStatus::APPROVED;

        Session::flash('success', __('declarations.request_approved'));
    }

    /**
     * Sign declaration request with Cipher and then send to EHealth.
     *
     * @return void
     */
    public function sign(): void
    {
        $declarationRequest = DeclarationRequest::findOrFail($this->declarationRequestId);

        if (Auth::user()->cannot('sign', $declarationRequest)) {
            Session::flash('error', __('declarations.policy.sign'));

            return;
        }

        try {
            $validated = $this->form->validate($this->form->signingRules());
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->getMessageBag());
            Session::flash('error', $exception->validator->errors()->first());

            return;
        }

        if (!$this->isNeedToResign) {
            $dataToSign = $this->dataToBeSigned;

            // The patient signs their own request in the cabinet, so that mark is kept as the System returned it
            if (!$this->isCreatedByPatient) {
                $dataToSign['person']['patient_signed'] = $this->isSigned;
            }
        } else {
            try {
                $dataToSign = EHealth::declarationRequest()
                    ->get($declarationRequest->uuid)
                    ->validate()['data_to_be_signed'];
            } catch (EHealthException|EHealthConnectionException $exception) {
                $exception->handle('Error when getting a declaration request to be signed');

                return;
            }
        }

        try {
            $signedContent = new CipherRequest()->signData(
                $dataToSign,
                $validated['knedp'],
                $validated['keyContainerUpload'],
                $validated['password'],
                Auth::user()->party->taxId
            );
        } catch (CipherException|CipherConnectionException $exception) {
            $exception->handle('Error when signing data with Cipher');

            return;
        }

        if (!$this->isNeedToResign) {
            $oldDeclaration = Declaration::where('person_id', $declarationRequest->person_id)
                ->where('division_id', $declarationRequest->division_id)
                ->where('employee_id', $declarationRequest->employee_id)
                ->where('status', Status::ACTIVE)
                ->filterByLegalEntityId(legalEntity()->id)
                ->first();
        }

        try {
            $response = EHealth::declarationRequest()->sign(
                $declarationRequest->uuid,
                ['signed_declaration_request' => $signedContent->getBase64Data()]
            );
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when signing declaration request');

            return;
        }

        try {
            $context = 'updating declaration request status';
            Repository::declarationRequest()->updateStatus($this->declarationRequestId, RequestStatus::SIGNED->value);

            $context = 'creating declaration';
            Repository::declaration()->store($response->validate());
        } catch (Exception $exception) {
            $this->handleDatabaseErrors($exception, "Error while $context");

            return;
        }

        if ($this->isNeedToResign) {
            $parentDeclaration = Declaration::whereUuid($declarationRequest->parent_declaration_uuid)->first();

            $parentDeclaration->status = Status::TERMINATED;
            $parentDeclaration->save();
        } elseif ($oldDeclaration) {
            $oldDeclaration->status = Status::TERMINATED;
            $oldDeclaration->save();
        }

        Session::flash('success', __('declarations.declaration_signed'));

        $this->redirectRoute('declaration.index', [legalEntity()], navigate: true);
    }

    /**
     * Build the list of doctors that can be chosen for the declaration request.
     *
     * A user with the DOCTOR role picks only their own employee record,
     * while a receptionist or an assistant picks any doctor of the legal entity.
     *
     * @return void
     */
    protected function setEmployeesInfo(): void
    {
        $user = Auth::user();

        $employees = Employee::query()
            ->doctor()
            ->filterByLegalEntityId(legalEntity()->id)
            ->active()
            ->whereNotNull('division_id')
            ->whereHas('specialities', static fn (Builder $query) => $query->where('speciality_officio', true))
            ->when(
                $user->hasAllowedRole(Role::DOCTOR),
                static fn (Builder $query) => $query->whereHas(
                    'users',
                    static fn (Builder $userQuery) => $userQuery->whereKey($user->id)
                )
            )
            ->with([
                'division:id,uuid,name',
                'party:id,first_name,last_name,second_name'
            ])
            ->get();
        $this->employeesInfo = $employees->map(static fn (Employee $employee) => [
            'employeeId' => $employee->uuid,
            'fullName' => $employee->fullName,
            'position' => $employee->position,
            'divisionId' => $employee->division->uuid,
            'divisionName' => $employee->division->name
        ])->toArray();

        if (count($this->employeesInfo) === 1) {
            $this->form->employeeId = $this->employeesInfo[0]['employeeId'];
            $this->form->divisionId = $this->employeesInfo[0]['divisionId'];
        }
    }

    /**
     * Get patient authentication methods.
     *
     * @return array
     */
    protected function getPersonAuthMethods(): array
    {
        try {
            return EHealth::person()->getAuthMethods($this->patientUuid)->validate();
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when getting auth methods');

            return [];
        }
    }

    /**
     * Ensure that the authenticated user has the given ability; if not, flash an error message.
     *
     * @param  string  $ability
     * @param  string  $errorMessage
     * @return bool
     */
    protected function ensureAbility(string $ability, string $errorMessage): bool
    {
        if (Auth::user()->cannot($ability, DeclarationRequest::class)) {
            Session::flash('error', $errorMessage);

            return false;
        }

        return true;
    }

    /**
     * Set related division ID based on chosen employee ID.
     *
     * @return void
     */
    protected function setDivisionId(): void
    {
        $this->form->divisionId = collect($this->employeesInfo)
            ->firstWhere('employeeId', $this->form->employeeId)['divisionId'] ?? '';
    }

    /**
     * Synchronize all incoming data from EHealth and send notifications.
     *
     * @param  array  $toBeSignedData
     * @return void
     */
    protected function syncDeclarationRelatedData(array $toBeSignedData): void
    {
        if (Repository::declarationRequest()->syncPersonData($toBeSignedData['person'])) {
            Session::flash('status', 'Персональні дані пацієнта було оновлено');
        }

        if (Repository::declarationRequest()->syncEmployeeData($toBeSignedData['employee'])
            || Repository::declarationRequest()->syncPartyData($toBeSignedData['employee']['party'])) {
            Session::flash('status', 'Ваші персональні дані було оновлено');
        }

        if (Repository::declarationRequest()->syncDivisionData($toBeSignedData['division'])) {
            $divisionId = Division::whereUuid($toBeSignedData['division']['id'])->value('id');
            $users = Repository::user()->getDivisionEditorsByLegalEntity($divisionId);
            Notification::send($users, new DivisionUpdated());
        }

        if (Repository::declarationRequest()->syncLegalEntityData($toBeSignedData['legal_entity'])) {
            $users = Repository::user()->getLegalEntityOwners();
            Notification::send($users, new LegalEntityUpdated());
        }
    }

    /**
     * Redirect to the patient data page for the current person.
     *
     * @param  int |null  $personId  Optional person ID; if not provided, uses the component's personId property.
     * @return void
     */
    public function goToPatientData(?int $personId = null): void
    {
        $personId ??= $this->personId;

        $this->redirectRoute('persons.patient-data', [legalEntity(), 'person' => $personId]);
    }
}
