<?php

declare(strict_types=1);

namespace App\Livewire\Person;

use App\Classes\Cipher\Api\CipherRequest;
use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\Person\Status;
use App\Exceptions\Cipher\CipherConnectionException;
use App\Exceptions\Cipher\CipherException;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Livewire\Person\Forms\PersonForm as Form;
use App\Models\Person\Person;
use App\Models\Person\PersonRequest;
use App\Models\Relations\Address;
use App\Notifications\NhsVerificationNeededNotification;
use App\Repositories\Repository;
use App\Traits\Addresses\BaseAddress;
use App\Traits\FormTrait;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

class PersonComponent extends Component
{
    use FormTrait;
    use WithFileUploads;
    use BaseAddress;

    private const int SMS_RESEND_LIMIT = 1;

    /**
     * Addresses of the person, exactly one of which has to be the address of residence.
     *
     * @var array
     */
    public array $addresses = [
        ['country' => Address::DEFAULT_COUNTRY, 'type' => Address::DEFAULT_TYPE]
    ];

    /**
     * Suggestions of the address registry for the address being filled in.
     *
     * @var array
     */
    public array $districts = [];

    public array $settlements = [];

    public array $streets = [];

    #[Locked]
    public int $personId;

    public string $mode = 'create';

    /**
     * Selected patient type that toggles the form between an identified person and an unidentified preperson.
     * Bound to the "type" query parameter so the create page can be opened directly on a given type.
     *
     * @var string
     */
    #[Url(as: 'type')]
    public string $patientType = 'person';

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
     * Current authentication method returned in eHealth urgent data for approving the person request.
     *
     * @var array
     */
    public array $authenticationMethodCurrent = [];

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

    public bool $canManageConfidantRelationships = false;

    /**
     * UUID of a person who is younger than 18 y/o.
     *
     * @var string|null
     */
    public ?string $invalidPersonId = null;

    /**
     * Why the person found by the search may not be chosen as a legal representative.
     *
     * @var string|null
     */
    public ?string $invalidPersonReason = null;

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
        'PHONE_TYPE',
        'LANGUAGE',
        'ISSUING_COUNTRY',
        'COUNTRY',
        'ADDRESS_TYPE',
        'PREFERRED_WAY_COMMUNICATION'
    ];

    public function baseMount(): void
    {
        $this->getDictionary();
    }

    /**
     * Document types a person is registered with.
     *
     * @return array
     */
    #[Computed]
    public function documentTypes(): array
    {
        return array_intersect_key(
            $this->dictionaries['DOCUMENT_TYPE'],
            array_flip(config('ehealth.person_registration_document_types'))
        );
    }

    /**
     * Document types that prove legal capacity. They are offered only between the self-registration age and the
     * full legal capacity age, which the document modal decides on its own from the entered birth date.
     *
     * @return array
     */
    #[Computed]
    public function legalCapacityDocumentTypes(): array
    {
        return array_intersect_key(
            $this->dictionaries['DOCUMENT_TYPE'],
            array_flip(config('ehealth.person_legal_capacity_document_types'))
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
        // Drop whatever the previously inspected person left behind, so that a rejection never shows next
        // to the person the user is looking at now
        $this->invalidPersonId = null;
        $this->invalidPersonReason = null;
        $this->selectedConfidantPersonId = null;

        $birthDate = CarbonImmutable::parse($personData['birthDate']);

        // Below the full legal capacity age a person cannot be a confidant (the remaining eligibility
        // rules — legal capacity, verification statuses — are enforced by eHealth)
        if ($birthDate->age < config('ehealth.person_full_legal_capacity_age')) {
            $this->invalidPersonId = $personData['id'];
            $this->invalidPersonReason = __('patients.age_insufficient_for_confidant_person');

            return;
        }

        try {
            $relationships = EHealth::person()->getConfidantPersonRelationships($personData['id'])->validate();
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when getting confidant person relationships of the chosen person');

            return;
        }

        if (Person::isRepresentedByConfidant($relationships)) {
            $this->invalidPersonId = $personData['id'];
            $this->invalidPersonReason = __('patients.confidant_person_has_own_confidants');

            return;
        }

        try {
            $authenticationMethods = EHealth::person()->getAuthMethods($personData['id'])->validate();
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when getting authentication methods of the chosen person');

            return;
        }

        if (!Person::hasActiveOtpAuthenticationMethod($authenticationMethods)) {
            $this->invalidPersonId = $personData['id'];
            $this->invalidPersonReason = __('patients.confidant_person_has_no_otp_auth_method');

            return;
        }

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
     * Drop the legal representative once the patient is no longer marked as incapacitated, so that a confidant
     * person hidden behind the unchecked box does not keep constraining the authentication method.
     *
     * @param  bool  $value
     * @return void
     */
    public function updatedIsIncapacitated(bool $value): void
    {
        if ($value) {
            return;
        }

        $this->removeConfidantPerson();

        $this->form->person['confidantPerson']['documentsRelationship'] = [];
        $this->newConfidantPerson = [];
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
                EHealth::person()->searchForPersonByParams($validated)->validate()
            );
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when searching for person');

            return;
        }
    }

    /**
     * Validate the filled in form and show the leaflet the patient has to be informed about. The person request
     * is sent from that leaflet, once the user marks the information as communicated.
     *
     * @return void
     */
    public function openInformationMessageModal(): void
    {
        if (Auth::user()->cannot('create', PersonRequest::class)) {
            Session::flash('error', __('patients.policy.create'));

            return;
        }

        $this->form->person['addresses'] = $this->addresses;

        try {
            // The consent is the answer the leaflet collects, so it cannot be required to pass yet
            $this->form->validate(Arr::except($this->form->rulesForCreate(), 'processDisclosureDataConsent'));
            $this->formKey++;
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setAddressAwareErrorBag($exception);
            $this->formKey++;

            return;
        }

        $this->showInformationMessageModal = true;
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

        $this->form->person['addresses'] = $this->addresses;

        try {
            $validated = $this->form->validate($this->form->rulesForCreate());
            $this->formKey++;
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setAddressAwareErrorBag($exception);
            $this->formKey++;

            return;
        }

        try {
            $response = EHealth::personRequest()->create($validated);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when creating a person request');

            return;
        }

        $selectedConfidantPersonData = null;
        if (!empty($this->selectedConfidantPersonId)) {
            $selectedConfidantPersonData = $this->getConfidantPersonData();
        }

        // Save in DB and show new frontend
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
            $this->handleDatabaseErrors($exception, 'Failed to store person request');

            return;
        }

        $urgent = $response->getUrgent();
        $this->form->person['id'] = $response->getData()['id'];
        $this->uploadedDocuments = $urgent['documents'] ?? [];
        $this->authenticationMethodCurrent = $urgent['authentication_method_current'] ?? [];
        $this->showInformationMessageModal = false;
        $this->viewState = 'new';

        if ($this->form->needsNhsVerification()) {
            Auth::user()->notify(new NhsVerificationNeededNotification());
        }
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

        $this->form->person['addresses'] = $this->addresses;

        try {
            // A draft is not sent to eHealth yet, so the leaflet has not been shown for it either
            $validated = $this->form->validate(
                Arr::except($this->form->rulesForCreate(), 'processDisclosureDataConsent')
            );
            $this->formKey++;
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setAddressAwareErrorBag($exception);
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
            $this->handleDatabaseErrors($exception, 'Failed to store person request');

            return;
        }

        Session::flash('success', $successMessage);
        $this->redirectRoute('persons.index', [legalEntity()], navigate: true);
    }

    /**
     * Report the address errors of the form under the addresses.* keys as well, because that is where the
     * shared address component looks them up.
     *
     * @param  ValidationException  $exception
     * @return void
     */
    protected function setAddressAwareErrorBag(ValidationException $exception): void
    {
        $messages = $exception->validator->errors()->getMessages();

        foreach ($messages as $key => $keyMessages) {
            if (str_starts_with($key, 'form.person.addresses.')) {
                $messages[Str::replaceFirst('form.person.', '', $key)] = $keyMessages;
            }
        }

        $this->setErrorBag($messages);
    }

    /**
     * Give every address a slot of its own in the suggestion lists. The address component binds them by
     * position, and a slot that does not exist yet leaves the registry search of that address unbound.
     *
     * @return void
     */
    public function dehydrate(): void
    {
        foreach (array_keys($this->addresses) as $index) {
            $this->districts[$index] ??= [];
            $this->settlements[$index] ??= [];
            $this->streets[$index] ??= [];
        }
    }

    /**
     * Add an address the user fills in on top of the one the form starts with.
     *
     * @return void
     */
    public function addAddress(): void
    {
        $this->addresses[] = ['country' => Address::DEFAULT_COUNTRY, 'type' => ''];
    }

    /**
     * Remove the address at the given position, leaving at least one in the form.
     *
     * @param  int  $index
     * @return void
     */
    public function removeAddress(int $index): void
    {
        if (count($this->addresses) <= 1) {
            return;
        }

        unset($this->addresses[$index]);

        $this->addresses = array_values($this->addresses);

        $this->districts = $this->shiftSuggestions($this->districts, $index);
        $this->settlements = $this->shiftSuggestions($this->settlements, $index);
        $this->streets = $this->shiftSuggestions($this->streets, $index);
    }

    /**
     * Drop the suggestion slot of the removed address and pull the ones behind it down, so that every slot
     * keeps matching the position of its address.
     *
     * @param  array  $lists
     * @param  int  $index
     * @return array
     */
    private function shiftSuggestions(array $lists, int $index): array
    {
        unset($lists[$index]);

        $shifted = [];

        foreach ($lists as $position => $list) {
            $shifted[$position > $index ? $position - 1 : $position] = $list;
        }

        return $shifted;
    }

    /**
     * Drop the address being edited when it stops being a Ukrainian one or becomes it, because the two are
     * filled in a different alphabet and only the Ukrainian one takes its values from the address registry.
     * A switch between two countries abroad keeps everything that was typed in.
     *
     * @param  mixed  $value
     * @param  string|null  $key
     * @return void
     */
    public function updatingAddresses(mixed $value, ?string $key): void
    {
        [$index, $field] = array_pad(explode('.', (string)$key, 2), 2, null);

        if ($field !== 'country') {
            return;
        }

        $currentCountry = $this->addresses[$index]['country'] ?? Address::DEFAULT_COUNTRY;

        if ($value === $currentCountry) {
            return;
        }

        if ($value !== Address::DEFAULT_COUNTRY && $currentCountry !== Address::DEFAULT_COUNTRY) {
            return;
        }

        $this->addresses[$index] = ['type' => $this->addresses[$index]['type'] ?? Address::DEFAULT_TYPE];

        unset($this->districts[$index], $this->settlements[$index], $this->streets[$index]);
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
            if (!$this->uploadDocuments()) {
                return;
            }
        }

        try {
            if (!$this->approvePersonRequest()) {
                return;
            }

            $this->showLeafletModal = true;
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when approving person request');

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
            EHealth::personRequest()->resendAuthOtp($this->form->person['id']);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when resending sms to person');

            return;
        }

        // Mark SMS as sent for this session (no expiration - persists until cache clear)
        RateLimiter::hit($rateLimitKey);

        Session::flash('success', __('patients.messages.sms_sent_successfully'));
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

        if (!empty($this->uploadedDocuments)) {
            if (count($this->form->uploadedDocuments) !== count($this->uploadedDocuments)) {
                Session::flash('error', __('patients.messages.upload_all_files'));

                return;
            }

            if (!$this->uploadDocuments()) {
                return;
            }
        }

        try {
            if (!$this->approvePersonRequest(['verification_code' => $validated['verificationCode']])) {
                return;
            }

            $this->showLeafletModal = true;
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when approving person request');

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
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when rejecting person request');

            return;
        }

        try {
            Repository::personRequest()->updateStatusByUuid($response->getData());
        } catch (Exception|Throwable $exception) {
            $this->handleDatabaseErrors($exception, $exception->getMessage());

            return;
        }

        Session::flash('success', __('patients.messages.person_request_rejected'));
        $this->redirectRoute('persons.index', [legalEntity()], navigate: true);
    }

    /**
     * Build and send API request 'Sign Person v2' and redirect to page if data is validated.
     *
     * @return void
     */
    public function sign(): void
    {
        if (Auth::user()->cannot('sign', PersonRequest::class)) {
            Session::flash('error', __('patients.policy.sign'));

            return;
        }

        try {
            $validated = $this->form->validate($this->form->rulesForSignPersonRequest());
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        try {
            $approvedPersonRequest = EHealth::personRequest()->getById($this->form->person['id']);
            $personRequestData = $approvedPersonRequest->getData();
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when getting person request by ID');

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
        } catch (CipherException|CipherConnectionException $exception) {
            $exception->handle('Error when signing data with Cipher');

            return;
        }

        try {
            $signResponse = EHealth::personRequest()
                ->withHeaders(['msp_drfo' => Auth::user()->party->taxId])
                ->signed($this->form->person['id'], ['signed_content' => $signedContent->getBase64Data()]);
            $responseData = $signResponse->getData();
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when sign person request');

            return;
        }

        // Create/update person, update request status
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
            $this->handleDatabaseErrors($exception, $exception->getMessage());

            return;
        }

        // Sync authentication methods for the person after signing the request
        try {
            $authMethodsResponse = EHealth::person()->getAuthMethods($responseData['person_id']);

            $authMethodsData = $authMethodsResponse->validate();

            $person = Person::whereUuid($responseData['person_id'])->first();

            Repository::authenticationMethod()->sync($person, $authMethodsData);
        } catch (EHealthException|EHealthConnectionException $exception) {
            // Only log the error, but do not block the user from proceeding, as the person's auth methods can be synced via declaration's page
            $exception->handle('Error when getting person authentication methods', __('patients.errors.person_auth_methods_sync_error'));
        }

        Session::flash('success', $successMessage);
        $this->redirectRoute('persons.index', [legalEntity()], navigate: true);
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
     * Upload documents to URLs that eHealth provided for the person request.
     *
     * @return bool
     */
    protected function uploadDocuments(): bool
    {
        $totalFiles = count($this->form->uploadedDocuments);
        // Check that all provided files were uploaded
        if ($totalFiles !== count($this->uploadedDocuments)) {
            Session::flash('error', __('patients.messages.upload_all_files'));

            return false;
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

            return true;
        }

        return false;
    }

    /**
     * Approve person request.
     *
     * @param  array  $requestData
     * @return bool
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    private function approvePersonRequest(array $requestData = []): bool
    {
        $response = EHealth::personRequest()->approve($this->form->person['id'], $requestData);
        $responseData = $response->getData();

        try {
            Repository::personRequest()->updateStatusByUuid($responseData);
        } catch (Exception $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to update person request status');

            return false;
        }

        $this->leafletContent = $responseData['content'];

        Session::flash('success', __('patients.messages.person_request_approved'));

        return true;
    }
}
