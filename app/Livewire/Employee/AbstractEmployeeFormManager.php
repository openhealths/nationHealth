<?php

declare(strict_types=1);

namespace App\Livewire\Employee;

use AllowDynamicProperties;
use App\Classes\eHealth\Api\EmployeeRequest as EHealthEmployeeRequest;
use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\Employee\RequestStatus;
use App\Enums\Employee\RevisionStatus;
use App\Enums\JobStatus;
use App\Enums\User\Role;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\Employee\BaseEmployee;
use App\Models\Employee\Employee;
use App\Models\Employee\EmployeeRequest;
use App\Models\LegalEntity;
use App\Models\Revision;
use App\Models\User;
use Auth;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;
use App\Mail\UserCredentialsMail;
use RuntimeException;
use Throwable;

#[AllowDynamicProperties]
abstract class AbstractEmployeeFormManager extends EmployeeComponent
{
    use WithFileUploads;

    #[Locked]
    public ?int $employeeRequestId = null;
    protected ?BaseEmployee $employeeRequest;
    protected ?BaseEmployee $employee = null;

    /**
     * blocking only first_name, last_name, date_of_birth, tax_id
     */
    public bool $isPartyDataPartiallyLocked = false;

    /**
     * users collection for selecting on position add email field
     */
    public ?Collection $partyUsers = null;

    /**
     * Email selected in the drop-down list in 'position_add'.
     * We CANNOT use 'form.party.email' because it is already occupied.
     */
    public ?string $formEmail = null;

    /**
     * collection of already existing employees for edit personal data
     */
    public ?Collection $partyExistingPositions = null;

    public string $pageTitle = '';

    // === PUBLIC ACTIONS ===
    // These methods define the shared algorithm. They call the abstract method.
    public function save(): void
    {
        try {
            $this->applyEmployeeTypeBusinessRules();
            // The validation call is now dynamic
            $this->form->validate($this->form->rulesForSave($this));
            $this->validatePartyDataConsistency();

            $this->employeeRequest = $this->handleDraftPersistence();
            $this->employeeRequestId = $this->employeeRequest->id;

            $this->flashSuccess(__('forms.employee_request_saved_successfully'));
        } catch (ValidationException $e) {
            $this->handleValidationException($e);
        } catch (Exception $e) {
            $this->handleGeneralException($e);
        }
    }

    // Used by resources/views/livewire/employee/party.blade.php
    public function prepareForSigning(): void
    {
        try {
            $this->applyEmployeeTypeBusinessRules();
            $this->form->validate($this->form->rulesForSave($this));
            $this->validatePartyDataConsistency();
            $this->employeeRequest = $this->handleDraftPersistence();
            $this->employeeRequestId = $this->employeeRequest->id;

            $this->flashSuccess(__('forms.employee_request_saved_successfully'));
            // 3.23.1.4 — review submitted fields before KEP
            $this->dispatch('open-request-preview-modal');
        } catch (ValidationException $e) {
            $this->handleValidationException($e);
        } catch (Exception $e) {
            $this->handleGeneralException($e);
        }
    }

    /**
     * Continue from pre-KEP preview to the signature modal.
     */
    public function proceedToSigning(): void
    {
        $this->dispatch('close-request-preview-modal');
        $this->dispatch('open-signature-modal');
    }

    public function sign()
    {
        Log::info('Attempting to sign.');

        try {
            // 1. Validate the form
            $this->applyEmployeeTypeBusinessRules();
            $this->form->validate($this->form->rulesForSave($this));
            $this->validatePartyDataConsistency();
            // 2. Persist the draft using the component's specific logic
            $this->employeeRequest = $this->handleDraftPersistence();
            $this->employeeRequestId = $this->employeeRequest->id;

            $requestToSign = $this->validateAndGetDraft();
            $signedContent = $this->signDataWithCipher($requestToSign);

            $eHealthResponseAsArray = new EHealthEmployeeRequest()->create($signedContent);

            if (isset($eHealthResponseAsArray['error'])) {
                throw new EHealthValidationException($eHealthResponseAsArray);
            }

            $validatedData = $eHealthResponseAsArray;

            $this->updateLocalRecords($requestToSign, $validatedData);

            $this->createLocalUserForEmployeeRequest($requestToSign);

            session()?->flash('success', __('employees.sign_success'));
            $this->resetSignatureFields();
            Log::info('Successfully signed and will redirect.');

            return redirect()->route('employee.index', ['legalEntity' => legalEntity()->id]);

        } catch (Exception $e) {
            $this->handleGeneralException($e);

        } catch (Throwable $e) {
            Log::critical('A critical throwable was caught during the signing process.', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            $this->flashError(__('errors.unexpected_error'));
            $this->dispatch('close-signature-modal');
        }
    }

    protected function createLocalUserForEmployeeRequest(EmployeeRequest $employeeRequest): void
    {
        $email = $employeeRequest->email;

        if (empty($email)) {
            Log::warning('Employee request user was not created because email is empty.', [
                'employee_request_id' => $employeeRequest->id,
            ]);

            return;
        }

        $existingUser = User::where('email', $email)->first();

        if ($existingUser) {
            $employeeRequest->update([
                'user_id' => $existingUser->id,
            ]);

            Log::info('Employee request linked to existing user. Credentials email was not sent.', [
                'employee_request_id' => $employeeRequest->id,
                'user_id' => $existingUser->id,
                'email' => $email,
            ]);

            return;
        }

        $password = Str::random(8);

        $user = User::forceCreate([
            'email' => $email,
            'password' => Hash::make($password),
            'email_verified_at' => now(),
            'must_change_password' => true,
        ]);

        $employeeRequest->update([
            'user_id' => $user->id,
        ]);

        try {
            Mail::to($user->email)->send(new UserCredentialsMail($user->email, $password));

            Log::info('Employee request user credentials email sent.', [
                'employee_request_id' => $employeeRequest->id,
                'user_id' => $user->id,
                'email' => $user->email,
            ]);
        } catch (Exception $e) {
            Log::error('Failed to send credentials email to user.', [
                'employee_request_id' => $employeeRequest->id,
                'user_id' => $user->id,
                'user_email' => $user->email,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // === SHARED HELPERS (Moved from Trait) ===
    // They are shared across all form components.

    /**
     * Updates local records with the response from the eHealth API.
     */
    protected function updateLocalRecords(EmployeeRequest $request, array $eHealthResponse, ?LegalEntity $legalEntity = null): void
    {
        $legalEntity ??= legalEntity();

        $uuid = $eHealthResponse['id'];
        $insertedAt = Arr::get($eHealthResponse, 'ehealth_response.data.inserted_at', null);

        $request->update(
            [
                'uuid' => $uuid,
                'legal_entity_uuid' => $legalEntity->uuid,
                'inserted_at' => Carbon::parse($insertedAt)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'),
                'status' => RequestStatus::NEW,
                'sync_status' => JobStatus::PARTIAL,
                'division_id' => $request->division_id,
                'division_uuid' => Arr::get($eHealthResponse, 'ehealth_response.data.division_id', null),
            ]
        );

        $request->revision->update(
            [
                'ehealth_response' => $eHealthResponse['ehealth_response'],
                'status' => RevisionStatus::SENT,
            ]
        );
    }

    /**
     * Prepares the nested data structure for a Revision from flat form data.
     */
    protected function mapRevisionData(array $flatData): array
    {
        $employeeChunk = Arr::only($flatData, ['position', 'employee_type', 'start_date', 'end_date', 'division_id']);
        $partyChunk = Arr::only($flatData, ['last_name', 'first_name', 'second_name', 'gender', 'birth_date', 'tax_id', 'no_tax_id', 'email', 'working_experience', 'about_myself']);

        // Backend enforcement: if party data is partially locked, always restore the original tax_id
        // to prevent accidental or malicious RNOKPP changes via form manipulation.
        if ($this->isPartyDataPartiallyLocked) {
            $originalTaxId = $this->employeeRequest?->party?->tax_id
                ?? $this->employee?->party?->tax_id
                ?? null;

            if ($originalTaxId !== null) {
                $partyChunk['tax_id'] = $originalTaxId;
                $partyChunk['no_tax_id'] = false;
            }
        }

        $documentsChunk = $flatData['documents'] ?? [];
        $phonesChunk = $flatData['phones'] ?? [];

        // 1. Get raw data (UI usually writes to 'doctor' variable)
        $rawProfessionalData = $flatData['doctor'] ?? [];

        // 2. Determine the correct key for eHealth (doctor vs med_admin)
        $employeeType = $flatData['employee_type'] ?? '';

        $professionalKey = match ($employeeType) {
            'MED_ADMIN' => 'med_admin',
            'PHARMACIST' => 'pharmacist',
            default => 'doctor', // Includes SPECIALIST
        };

        // 3. Fix structure (ensure lists are arrays, not objects with keys)
        // Only do this if we have data
        $professionalChunk = [];
        if (!empty($rawProfessionalData)) {
            // eHealth needs 'educations' (plural), UI might give 'education' or 'educations'
            $edu = $rawProfessionalData['educations'] ?? $rawProfessionalData['education'] ?? [];

            $professionalChunk = [
                'educations' => array_values($edu), // Ensure JSON array [...]
                'specialities' => array_values($rawProfessionalData['specialities'] ?? []),
                'qualifications' => array_values($rawProfessionalData['qualifications'] ?? []),
                'science_degree' => $rawProfessionalData['science_degree'] ?? null,
            ];
        }

        // 4. Build result
        $result = [
            'employee_request_data' => $employeeChunk,
            'party' => $partyChunk,
            'documents' => $documentsChunk,
            'phones' => $phonesChunk,
        ];

        // Only add the block if there is data or if it's required type
        if (!empty($professionalChunk)) {
            $result[$professionalKey] = $professionalChunk;
        }

        return $result;
    }

    /**
     * Handles specific EHealth API response exceptions and maps them to localized messages.
     *
     * Maps the following specific API error messages:
     * - 'Forbidden to create OWNER': It is forbidden to create a user with the type Owner.
     * Such a user already exists or the action is not available.
     * - 'employee have more than one speciality with active speciality_officio':
     * An employee cannot have more than one specialty marked 'Main'.
     * - 422 with 'tax_id': The provided Tax ID already exists in the system.
     *
     * @param  EHealthResponseException  $e
     * @return void
     */
    protected function handleEHealthResponseException(EHealthResponseException $e): void
    {
        $errorCode = $e->getCode();
        $errorMessage = $e->getMessage();

        $translatedMessage = match (true) {
            str_contains($errorMessage, 'Forbidden to create OWNER')
            => __('errors.ehealth.messages.forbidden_create_owner'),

            str_contains($errorMessage, 'employee have more than one speciality with active speciality_officio')
            => __('errors.ehealth.messages.multiple_primary_specialities'),

            $errorCode === 422 && str_contains($errorMessage, 'tax_id')
            => __('errors.ehealth.messages.tax_id_exists'),

            default => $errorMessage
        };

        $this->flashError($translatedMessage);

        Log::error('EHealth Error Handled: ' . $errorMessage);
    }

    /**
     * Encapsulates the logic for creating and saving a new revision for a request.
     */
    protected function saveRevisionForRequest(BaseEmployee $request, array $nestedData): void
    {
        $revision = new Revision();
        $revision->data = $nestedData;
        $revision->status = RevisionStatus::PENDING;
        $request->revision()->save($revision);
    }

    /**
     * This is the abstract method that concrete components must implement.
     * It contains the unique logic for creating or updating a draft based on the component's context.
     *
     * @return EmployeeRequest
     */
    abstract protected function handleDraftPersistence(): EmployeeRequest;

    /**
     * Gets the draft and validates it, including KEP-specific validation.
     */
    protected function validateAndGetDraft(): EmployeeRequest
    {
        // We use the property on the class, which was set by handleDraftPersistence()
        $requestToSign = $this->employeeRequest;

        if (is_null($requestToSign) || !is_null($requestToSign->uuid)) {
            throw new RuntimeException(__('forms.draft_not_found_or_already_signed'), 400);
        }

        $this->form->validate($this->form->rulesForKepOnly());

        return $requestToSign;
    }

    /**
     * Applies locks to fields that cannot be changed in eHealth for an existing employee.
     * Call this in the mount() method of child components.
     */
    protected function applyImmutableFieldLocks(): void
    {
        // Check if we are editing a draft linked to an existing employee OR editing the employee directly
        $isExistingEmployee =
            ($this->employee && $this->employee->id) ||
            ($this->employeeRequest && $this->employeeRequest->employee_id);

        if ($isExistingEmployee) {
            // 1. Lock Immutable Party Data
            // Blocks: first_name, last_name, birth_date, tax_id
            // Allows: second_name, gender, phones, email, documents (if needed), about_myself, working_experience
            $this->isPartyDataPartiallyLocked = true;

            // 2. Lock Immutable Position Data
            // Blocks: position, employee_type, start_date
            // Allows: division_id
            $this->isCorePositionDataLocked = true;
        }
    }

    /**
     * party-user data consistency check
     * Finds an existing Party associated with the user's email, if one exists.
     * This method sets the $this->matchedParty property if found.
     */
    protected function validatePartyDataConsistency(): void
    {
        $this->matchedParty = null;
        $partyData = $this->form->party;

        // 1. Get the email from the form
        $email = $this->formEmail ?? $partyData['email'] ?? null;

        if (!$email) {
            // If there is no email, there's nothing to search for
            return;
        }

        // 2. FIND THE USER BY EMAIL
        //    This is where we "find users by email"
        //    ->with('party') tells Eloquent: "when you find the User,
        //    please also eager-load their related Party model,
        //    using the 'party()' relationship from the User model"
        $userByEmail = User::where('email', $email)->with('party')->first();

        // 3. CHECK IF THE USER EXISTS AND HAS AN ASSOCIATED PARTY
        //    This is your condition: "if a user already exists and has a party associated with them"
        //
        //    - `$userByEmail`            -> checks that the user was found (is not null)
        //    - `$userByEmail->party`     -> checks that this user has
        //                                 an associated 'party' (i.e., party_id
        //                                 in the users table is not null and the
        //                                 Party model was loaded)
        //
        if ($userByEmail && $userByEmail->party) {

            // 4. If both conditions are met – we found the Party
            //    through the relationship with User.
            //    We take this 'party' from the 'user' and
            //    assign it to $this->matchedParty.
            $this->matchedParty = $userByEmail->party;
        }
    }

    /**
     * Helper to retrieve the current Party ID regardless of the child component context.
     */
    protected function getRelevantPartyId(): ?int
    {
        return
            $this->employee?->party_id
            ?? $this->employeeRequest?->party_id
            ?? data_get($this, 'partyId')
            ?? data_get($this, 'matchedParty.id')
            ?? $this->form->existingPartyId;
    }

    /**
     * Applies strict business rules for specific employee types before persistence.
     */
    protected function applyEmployeeTypeBusinessRules(): void
    {
        $isOwnerContext = false;

        // 1. If the OWNER type is selected right now
        if ($this->form->employeeType === Role::OWNER->value) {
            $isOwnerContext = true;
        }
        // 2. If not, check if there is already an active owner record in the database
        else {
            $partyId = $this->getRelevantPartyId();

            if ($partyId) {
                // We use the Scope activeOwners, which we added to the Employee model
                $isOwnerContext = Employee::query()
                    ->forParty($partyId)
                    ->activeOwners(legalEntity()->id)
                    ->exists();
            }
        }

        // If it is the Owner (new or existing) and the length of service is empty/zero -> put 1.
        if ($isOwnerContext && empty($this->form->party['workingExperience'])) {
            $this->form->party['workingExperience'] = 1;
        }
    }

    /**
     * Signs the data using SignatureService.
     */
    private function signDataWithCipher(EmployeeRequest $requestToSign): string
    {
        $requestToSign->loadMissing('revision');
        $nestedDataForRevision = $requestToSign->revision->data;
        $payloadToSign = EHealth::employeeRequest()->schemaCreate($nestedDataForRevision);

        return signatureService()->signData(
            $payloadToSign,
            $this->form->password,
            $this->form->knedp,
            $this->form->keyContainerUpload,
            Auth::user()->party->tax_id
        );
    }

    // === SHARED HELPERS & UI LOGIC (Moved from Trait) ===

    /**
     * Resets only the fields related to the digital signature form inputs.
     */
    public function resetSignatureFields(): void
    {
        $this->form->reset('keyContainerUpload', 'password', 'knedp');
    }

    /**
     * A computed property that determines if the "no tax ID" mode can be enabled.
     */
    #[Computed]
    public function canEnableNoTaxId(): bool
    {
        return array_any(
            $this->form->documents,
            fn ($document) => !empty($document['number']) && in_array(
                $document['type'],
                ['PASSPORT', 'NATIONAL_ID', 'REFUGEE_CERTIFICATE', 'PERMANENT_RESIDENCE_PERMIT']
            )
        );

    }

    /**
     * Handles the click event on the "no tax ID" checkbox.
     */
    public function toggleNoTaxId(): void
    {
        if ($this->canEnableNoTaxId) {
            $this->form->party['noTaxId'] = !$this->form->party['noTaxId'];
            $this->syncTaxIdFromDocument();
        } else {
            $this->flashError(__('forms.no_tax_id_document_required'));
            $this->dispatch('scroll-to-element', selector: '#section-documents');
            $this->dispatch('highlight-section', selector: '#section-documents');
        }
    }

    /**
     * Syncs the Tax ID field with the number from a suitable document.
     */
    public function syncTaxIdFromDocument(): void
    {
        if ($this->form->party['noTaxId'] === false) {
            return;
        }

        foreach ($this->form->documents as $document) {
            if (!empty($document['number']) && in_array($document['type'], ['PASSPORT', 'NATIONAL_ID', 'REFUGEE_CERTIFICATE', 'PERMANENT_RESIDENCE_PERMIT'])) {
                $this->form->party['taxId'] = $document['number'];

                return;
            }
        }
    }

    /**
     * Handles ValidationException by dispatching events for user feedback and scrolling.
     */
    private function handleValidationException(ValidationException $e): void
    {
        $validator = $e->validator;
        $specificEmailError = __('validation.email_already_exists');
        $allMessages = $validator->errors()->all();

        if (in_array($specificEmailError, $allMessages, true)) {
            $this->flashError($specificEmailError);
            $this->setErrorBag($validator->getMessageBag());
            $this->dispatch('validation-failed-scroll', firstErrorKey: 'form.party.email');

            return;
        }

        $flashMessage = $this->buildValidationFlashMessage($validator);

        $this->flashError($flashMessage);
        $this->setErrorBag($validator->getMessageBag());

        if (!empty($validator->errors()->keys())) {
            $this->dispatch('validation-failed-scroll', firstErrorKey: $validator->errors()->keys()[0]);
        }
    }

    /**
     * Builds a user-facing flash message from validation errors with document context.
     */
    private function buildValidationFlashMessage(\Illuminate\Contracts\Validation\Validator $validator): string
    {
        $messages = collect($validator->errors()->messages())
            ->flatMap(function (array $errors, string $key) {
                return collect($errors)->map(function (string $error) use ($key) {
                    if ($this->isDetailedValidationMessage($error)) {
                        return $error;
                    }

                    $context = $this->resolveValidationErrorContext($key);

                    return $context !== null ? "{$context}: {$error}" : $error;
                });
            })
            ->filter()
            ->unique()
            ->values();

        if ($messages->isEmpty()) {
            return __('forms.validation_error_unknown');
        }

        return $messages->implode(' ');
    }

    private function isDetailedValidationMessage(string $error): bool
    {
        return str_contains($error, '«')
            || str_starts_with($error, 'У розділі')
            || str_starts_with($error, 'Не можна одночасно')
            || str_contains($error, 'eSOZ');
    }

    private function resolveValidationErrorContext(string $key): ?string
    {
        if (preg_match('/^form\.documents\.(\d+)\.(\w+)$/', $key, $matches)) {
            $fieldLabel = match ($matches[2]) {
                'number' => __('forms.document_number'),
                'type' => __('forms.document_type'),
                'issuedAt', 'issued_at' => __('forms.issued_at'),
                'issuedBy', 'issued_by' => __('forms.issued_by'),
                default => null,
            };

            if ($fieldLabel === null) {
                return null;
            }

            $documentIndex = (int) $matches[1];
            $documents = $this->form?->documents ?? [];
            $documentType = $documents[$documentIndex]['type'] ?? null;

            if ($documentType === null) {
                return $fieldLabel;
            }

            $documentLabel = __('patients.documents.' . $documentType);
            if ($documentLabel === 'patients.documents.' . $documentType) {
                $documentLabel = $documentType;
            }

            return "{$fieldLabel} ({$documentLabel})";
        }

        if ($key === 'form.documents') {
            return __('forms.documents');
        }

        return null;
    }

    protected function flashSuccess(string $message): void
    {
        session()->flash('success', $message);
        $this->dispatch('flashMessage', ['message' => $message, 'type' => 'success']);
    }

    protected function flashError(string $message): void
    {
        session()->flash('error', $message);
        $this->dispatch('flashMessage', ['message' => $message, 'type' => 'error']);
    }

    private function handleConnectionException(EHealthConnectionException $e): void
    {
        $this->flashError(__('errors.ehealth_connection_error'));
        Log::error('EHealth connection error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
    }

    /**
     * A centralized exception handler for generic, non-validation errors.
     */
    protected function handleException(Exception $e): void
    {
        Log::error('Process failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
        $this->flashError($e->getMessage());
    }

    /**
     * Handles a detailed validation error from the eHealth API.
     */
    protected function handleEHealthValidationError(EHealthValidationException $e): void
    {
        $fullMessage = $e->getTranslatedMessage();
        $this->flashError($fullMessage);

        Log::error(
            'EHealth Validation Error: ' . $fullMessage,
            [
                'details' => $e->getDetails(),
                'trace' => $e->getTraceAsString(),
            ]
        );
    }

    private function removeEmptyValuesRecursively(array $array): array
    {
        foreach ($array as $key => &$value) {
            if (is_array($value)) {
                $value = $this->removeEmptyValuesRecursively($value);
            }
        }

        return array_filter($array, function ($value) {
            return !is_null($value) && $value !== '' && $value !== [];
        });
    }

    /**
     * A new centralized exception handler for various specific exceptions.
     */
    private function handleGeneralException(Exception $e): void
    {
        match (true) {
            $e instanceof ValidationException => $this->handleValidationException($e),
            $e instanceof EHealthValidationException => $this->handleEHealthValidationError($e),
            $e instanceof EHealthResponseException => $this->handleEHealthResponseException($e),
            $e instanceof EHealthConnectionException => $this->handleConnectionException($e),
            default => $this->handleException($e),
        };
        $this->dispatch('close-signature-modal');
    }
}
