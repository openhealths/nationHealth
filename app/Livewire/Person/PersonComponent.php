<?php

declare(strict_types=1);

namespace App\Livewire\Person;

use App\Classes\Cipher\Api\CipherRequest;
use App\Classes\Cipher\Exceptions\CipherApiException;
use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\Person\Status;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Livewire\Person\Forms\PersonForm as Form;
use App\Models\Person\Person;
use App\Models\Person\PersonRequest;
use App\Repositories\Repository;
use App\Traits\Addresses\AddressSearch;
use App\Traits\FormTrait;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

class PersonComponent extends Component
{
    use FormTrait;
    use WithFileUploads;
    use AddressSearch;

    private const int SMS_RESEND_LIMIT = 1;

    #[Locked]
    public int $personId;

    public string $mode = 'create';

    public Form $form;

    public int $formKey = 1;

    /**
     * List of founded confidant person.
     *
     * @var array
     */
    public array $confidantPerson = [];

    /**
     * List of uploaded documents.
     *
     * @var array
     */
    public array $uploadedDocuments = [];

    /**
     * Content that shows to the patient when signing the leaflet.
     *
     * @var string
     */
    public string $leafletContent;

    /**
     * ID selected confidant person.
     *
     * @var string|null
     */
    public ?string $selectedConfidantPersonId = null;

    /**
     * Show different frontend base on mode.
     *
     * @var string
     */
    public string $viewState = 'default';

    /**
     * Additional parameters for search.
     *
     * @var bool
     */
    public bool $showAdditionalParams;

    /**
     * Track uploaded files.
     *
     * @var array
     */
    public array $uploadedFiles = [];

    /**
     * Is patient incapable or child less than 14 y.o.
     *
     * @var bool
     */
    public bool $isIncapacitated = false;

    /**
     * UUID of a person who is younger than 18 y/o.
     *
     * @var string|null
     */
    public ?string $invalidPersonId = null;

    /**
     * Data about new confidant person.
     *
     * @var array
     */
    public array $newConfidantPerson;

    /**
     * KEP key.
     *
     * @var object|null
     */
    public ?object $file = null;

    public bool $showInformationMessageModal = false;

    public bool $showSignatureModal = false;

    public bool $showLeafletModal = false;

    public array $dictionaryNames = [
        'DOCUMENT_TYPE',
        'DOCUMENT_RELATIONSHIP_TYPE',
        'GENDER',
        'PHONE_TYPE'
    ];

    public function baseMount(): void
    {
        $this->getDictionary();

        // Show only documents that are used to register person in the system.
        $this->dictionaries['DOCUMENT_TYPE'] = array_intersect_key(
            $this->dictionaries['DOCUMENT_TYPE'],
            array_flip(config('ehealth.person_registration_document_types'))
        );
    }

    /**
     * Choose a confidant person from the provided list.
     *
     * @param  array  $personData
     * @return void
     */
    public function chooseConfidantPerson(array $personData): void
    {
        $birthDate = CarbonImmutable::parse($personData['birthDate']);

        if ($birthDate->age < 18) {
            $this->invalidPersonId = $personData['id'];

            return;
        }

        $this->invalidPersonId = null;

        $this->selectedConfidantPersonId = $personData['id'];

        if (!$this instanceof PersonUpdate) {
            $this->form->person['confidantPerson']['personId'] = $personData['id'];

            // Show data about person that will be confidant
            $person = Person::whereUuid($personData['id'])->with(['documents', 'phones'])->first();
            $personData['documents'] = $person?->documents->toArray() ?? [];
            $personData['phones'] = $person?->phones->toArray() ?? [];
            $this->newConfidantPerson = $personData;

            $this->form->person['authenticationMethods'][0]['value'] = $personData['id'];
        }
    }

    /**
     * Remove selected confidant person from the cache and form.
     *
     * @return void
     */
    public function removeConfidantPerson(): void
    {
        $this->form->person['authenticationMethods'][0]['value'] = null;

        $this->form->person['confidantPerson']['personId'] = '';
        $this->selectedConfidantPersonId = null;
    }

    /**
     * Search for person with provided filters.
     *
     * @return void
     */
    public function searchForPerson(): void
    {
        if (Auth::user()->cannot('viewAny', Person::class)) {
            Session::flash('error', __('patients.policy.view_any'));

            return;
        }

        try {
            $validated = $this->form->validate($this->form->rulesForSearch());
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        try {
            $this->confidantPerson = Arr::toCamelCase(
                EHealth::person()->searchForPersonByParams($validated)->getData()
            );
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when searching for person');

            return;
        }
    }

    /**
     * Send API request 'Create Person v2' and show the next page if data is validated.
     *
     * @return void
     */
    public function create(): void
    {
        if (Auth::user()->cannot('create', PersonRequest::class)) {
            Session::flash('error', __('patients.policy.create'));

            return;
        }

        $this->form->person['addresses'] = [$this->address]; // must be multiple

        try {
            $addressErrors = $this->addressValidation();
            if (!empty($addressErrors)) {
                throw ValidationException::withMessages($addressErrors);
            }

            $validated = $this->form->validate($this->form->rulesForCreate());
            $this->formKey++;
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());
            $this->formKey++;

            return;
        }

        try {
            $response = EHealth::personRequest()->create($validated);
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when creating a person request');

            return;
        }

        $selectedConfidantPersonData = null;
        if (!empty($this->selectedConfidantPersonId)) {
            $selectedConfidantPersonData = $this->getConfidantPersonData();
        }

        // Save in DB and show new frontend
        if ($response->successful()) {
            try {
                if ($this instanceof PersonRequestEdit) {
                    Repository::personRequest()->updateDraft(
                        $this->form->person['id'],
                        removeEmptyKeys($response->map($response->validate())),
                        $selectedConfidantPersonData
                    );
                } else {
                    Repository::personRequest()->create(
                        removeEmptyKeys($response->map($response->validate())),
                        $selectedConfidantPersonData
                    );
                }
            } catch (Throwable $exception) {
                $this->logDatabaseErrors($exception, 'Failed to store person request');
                Session::flash('error', __('validation.custom.database_error'));

                return;
            }

            $this->form->person['id'] = $response->getData()['id'];
            $this->uploadedDocuments = $response->getUrgent()['documents'];
            $this->showInformationMessageModal = true;
        }
    }

    public function openNewState(): void
    {
        $this->showInformationMessageModal = false;
        $this->viewState = 'new';
    }

    /**
     * Create data about person request in DB.
     *
     * @return void
     */
    public function createLocally(): void
    {
        if (Auth::user()->cannot('create', PersonRequest::class)) {
            Session::flash('error', __('patients.policy.create'));

            return;
        }

        $this->form->person['addresses'] = [$this->address]; // must be multiple

        try {
            $validated = $this->form->validate($this->form->rulesForCreate());
            $this->formKey++;
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());
            $this->formKey++;

            return;
        }

        $selectedConfidantPersonData = null;
        if (!empty($this->selectedConfidantPersonId)) {
            $selectedConfidantPersonData = $this->getConfidantPersonData();
        }

        try {
            $validated['person']['status'] = Status::DRAFT;
            if ($this instanceof PersonRequestEdit) {
                Repository::personRequest()->updateDraft(
                    $this->form->person['id'],
                    removeEmptyKeys(Arr::toSnakeCase($validated)),
                    $selectedConfidantPersonData
                );
                $successMessage = __('patients.messages.person_request_updated');
            } else {
                Repository::personRequest()->create(
                    removeEmptyKeys(Arr::toSnakeCase($validated)),
                    $selectedConfidantPersonData
                );
                $successMessage = __('patients.messages.person_request_created');
            }
        } catch (Throwable $exception) {
            $this->logDatabaseErrors($exception, 'Failed to store person request');
            Session::flash('error', __('messages.database_error'));

            return;
        }

        Session::flash('success', $successMessage);
        $this->redirectRoute('persons.index', [legalEntity()], navigate: true);
    }

    /**
     * Validate uploaded files and save.
     *
     * @param  string  $field
     * @return void
     */
    public function updated(string $field): void
    {
        if (str_starts_with($field, 'form.uploadedDocuments')) {
            try {
                $this->form->validate($this->form->rulesForFiles());
            } catch (ValidationException $exception) {
                Session::flash('error', $exception->validator->errors()->first());
                $this->setErrorBag($exception->validator->getMessageBag());

                return;
            }
        }
    }

    /**
     * Delete uploaded file.
     *
     * @param  int  $key
     * @return void
     */
    public function deleteDocument(int $key): void
    {
        unset($this->form->uploadedDocuments[$key]);
    }

    /**
     * Upload patient files to the appropriate URL.
     *
     * @return void
     */
    public function sendFiles(): void
    {
        if (Auth::user()->cannot('create', PersonRequest::class)) {
            Session::flash('error', __('patients.policy.send_files'));

            return;
        }

        try {
            $this->form->validate($this->form->rulesForFiles());
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        if ($this->selectedConfidantPersonId || !empty($this->form->uploadedDocuments)) {
            $this->uploadDocuments();
        }

        try {
            $this->approvePersonRequest();
            $this->showLeafletModal = true;
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when approving person request');

            return;
        }
    }

    /**
     * Show translated documents name.
     *
     * @param  array  $document
     * @return string
     */
    public function getDocumentLabel(array $document): string
    {
        return __('patients.documents.' . Str::afterLast($document['type'], '.'));
    }

    /**
     * Resend SMS with confirmation code.
     *
     * @return void
     */
    public function resendSms(): void
    {
        if (Auth::user()->cannot('create', PersonRequest::class)) {
            Session::flash('error', __('patients.policy.resend_sms'));

            return;
        }

        $rateLimitKey = 'resend-sms-session:' . Auth::id() . ':' . $this->form->person['id'];

        // Check if SMS has already been resent in this session (single resend rule)
        if (RateLimiter::tooManyAttempts($rateLimitKey, self::SMS_RESEND_LIMIT)) {
            Session::flash('error', __('validation.custom.person.sms_already_resent'));

            return;
        }

        try {
            $response = EHealth::personRequest()->resendAuthOtp($this->form->person['id']);
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when resending sms to person');

            return;
        }

        if ($response->getData()['status'] === 'new') {
            // Mark SMS as sent for this session (no expiration - persists until cache clear)
            RateLimiter::hit($rateLimitKey);

            Session::flash('success', __('patients.messages.sms_sent_successfully'));
        }
    }

    /**
     * Build and send API request 'Approve Person v2' and show the next page if data is validated.
     *
     * @return void
     */
    public function approve(): void
    {
        if (Auth::user()->cannot('create', PersonRequest::class)) {
            Session::flash('error', __('patients.policy.approve'));

            return;
        }

        try {
            $validated = $this->form->validate($this->form->rulesForApprove());
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        if ($this->selectedConfidantPersonId && $this->form->uploadedDocuments) {
            $this->uploadDocuments();
        }

        try {
            $this->approvePersonRequest(['verification_code' => $validated['verificationCode']]);
            Session::flash('success', __('patients.messages.person_request_approved'));
            $this->showLeafletModal = true;
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when approving person request');

            return;
        }
    }

    /**
     * Inform the patient about processing his data and show signature modal.
     *
     * @return void
     */
    public function openSignatureModal(): void
    {
        $this->showLeafletModal = false;
        $this->form->patientSigned = true;
        $this->showSignatureModal = true;
    }

    /**
     * Reject previously created request.
     *
     * @return void
     */
    public function reject(): void
    {
        $personRequest = PersonRequest::whereUuid($this->form->person['id'])->get()->firstOrFail();

        if (Auth::user()->cannot('reject', [PersonRequest::class, $personRequest])) {
            Session::flash('error', __('patients.policy.reject'));

            return;
        }

        try {
            $response = EHealth::personRequest()->reject($personRequest->uuid);
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when rejecting person request');

            return;
        }

        if ($response->successful()) {
            try {
                Repository::personRequest()->updateStatusByUuid($response->getData());
            } catch (Exception|Throwable $exception) {
                $this->logDatabaseErrors($exception, $exception->getMessage());
                Session::flash('error', __('messages.database_error'));

                return;
            }

            Session::flash('success', __('patients.messages.person_request_rejected'));
            $this->redirectRoute('persons.index', [legalEntity()], navigate: true);
        }
    }

    /**
     * Build and send API request 'Sign Person v2' and redirect to page if data is validated.
     *
     * @return void
     */
    public function sign(): void
    {
        if (Auth::user()->cannot('create', PersonRequest::class)) {
            Session::flash('error', __('patients.policy.sign'));

            return;
        }

        try {
            $validated = $this->form->validate($this->form->signingRules());
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        try {
            $approvedPersonRequest = EHealth::personRequest()->getById($this->form->person['id']);
            $personRequestData = $approvedPersonRequest->getData();
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when getting person request by ID');

            return;
        }

        $personRequestData['patient_signed'] = $this->form->patientSigned;

        try {
            $signedContent = new CipherRequest()->signData(
                $personRequestData,
                $validated['knedp'],
                $validated['keyContainerUpload'],
                $validated['password'],
                Auth::user()->party->taxId
            );
        } catch (ConnectionException $exception) {
            $this->logConnectionError($exception, 'Error connecting to Cipher when signing data');
            Session::flash('error', __('messages.connection_exception'));

            return;
        } catch (CipherApiException $exception) {
            $this->logCipherError($exception, 'Cipher API error when signing data');
            Session::flash('error', $exception->getMessage());

            return;
        } catch (JsonException $exception) {
            $this->logDatabaseErrors($exception, 'JSON encoding error when signing data');
            Session::flash('error', __('patients.messages.data_processing_error'));

            return;
        }

        try {
            $signResponse = EHealth::personRequest()
                ->withHeaders(['msp_drfo' => Auth::user()->party->taxId])
                ->signed($this->form->person['id'], ['signed_content' => $signedContent->getBase64Data()]);
            $responseData = $signResponse->getData();
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when sign person request');

            return;
        }

        // Create/update person, update request status
        if ($signResponse->successful()) {
            try {
                DB::transaction(function () use ($responseData, $approvedPersonRequest, &$successMessage) {
                    Repository::personRequest()->updateStatusByUuid($responseData);

                    if ($this instanceof PersonUpdate) {
                        Repository::person()->update(
                            $approvedPersonRequest->map($approvedPersonRequest->validate()),
                            $responseData['person_id']
                        );
                        $successMessage = __('patients.messages.person_updated');
                    } else {
                        Repository::person()->create(
                            $approvedPersonRequest->map($approvedPersonRequest->validate()),
                            $responseData['person_id']
                        );
                        $successMessage = __('patients.messages.person_created');
                    }
                });
            } catch (Exception|Throwable $exception) {
                $this->logDatabaseErrors($exception, $exception->getMessage());
                Session::flash('error', __('messages.database_error'));

                return;
            }

            Session::flash('success', $successMessage);
            $this->redirectRoute('persons.index', [legalEntity()], navigate: true);
        }
    }

    /**
     * Get selected confidant person data.
     *
     * @return array
     */
    private function getConfidantPersonData(): array
    {
        return collect($this->confidantPerson)
            ->where('id', $this->selectedConfidantPersonId)
            // change id key to uuid
            ->map(static fn (array $person) => array_merge(
                Arr::except($person, 'id'),
                ['uuid' => $person['id']]
            ))
            ->first();
    }

    /**
     * Upload documents to URLs that EHealth provide.
     *
     * @return void
     */
    protected function uploadDocuments(): void
    {
        $totalFiles = count($this->form->uploadedDocuments);
        // Check that all provided files were uploaded
        if ($totalFiles !== count($this->uploadedDocuments)) {
            Session::flash('error', __('patients.messages.upload_all_files'));

            return;
        }

        $successCount = 0;
        foreach ($this->form->uploadedDocuments as $key => $document) {
            try {
                $filePath = $document->getRealPath();
                $fileMime = $document->getMimeType();
                $fileContents = file_get_contents($filePath);
                $uploadUrl = trim($this->uploadedDocuments[$key]['url']);

                $uploadResponse = Http::withHeaders([
                    'Content-Type' => $fileMime,
                ])->withBody($fileContents, $fileMime)->put($uploadUrl);

                if ($uploadResponse->successful()) {
                    $successCount++;

                    $this->uploadedFiles[$key] = true;
                } else {
                    Session::flash('error', __('messages.database_error'));

                    $this->uploadedFiles[$key] = false;
                }
            } catch (Exception) {
                Session::flash('error', __('messages.database_error'));

                $this->uploadedFiles[$key] = false;
            }
        }

        // Show final status message
        if ($successCount === $totalFiles) {
            Session::flash('success', __('patients.messages.files_uploaded_successfully'));
        }
    }

    /**
     * Approve person request.
     *
     * @param  array  $requestData
     * @return void
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     */
    private function approvePersonRequest(array $requestData = []): void
    {
        $response = EHealth::personRequest()->approve($this->form->person['id'], $requestData);
        $responseData = $response->getData();

        if ($response->successful()) {
            try {
                Repository::personRequest()->updateStatusByUuid($responseData);
            } catch (Exception $exception) {
                $this->logDatabaseErrors($exception, 'Failed to update person request status');
                Session::flash('error', __('messages.database_error'));

                return;
            }
        }

        $this->leafletContent = $responseData['content'];
    }
}
