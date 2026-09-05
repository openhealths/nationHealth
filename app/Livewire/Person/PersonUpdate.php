<?php

declare(strict_types=1);

namespace App\Livewire\Person;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\Person\AuthStep;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Livewire\Person\Traits\InteractsWithAuthenticationMethods;
use App\Livewire\Person\Traits\ManagesConfidantPersonRelationships;
use App\Models\LegalEntity;
use App\Models\Person\Person;
use App\Models\Person\PersonRequest;
use App\Notifications\NhsVerificationNeededNotification;
use App\Repositories\Repository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Throwable;

/**
 * Used for updating person by using person request call
 */
class PersonUpdate extends PersonComponent
{
    use InteractsWithAuthenticationMethods;
    use ManagesConfidantPersonRelationships;

    #[Locked]
    public string $uuid;

    /**
     * List of available auth methods.
     *
     * @var array
     */
    public array $authenticationMethods;

    public bool $showAuthMethodModal = false;

    public AuthStep $authStep = AuthStep::INITIAL;

    /**
     * Current phone number.
     *
     * @var string|null
     */
    public ?string $phoneNumber = null;

    /**
     * Confirmation code that need for 'Complete OTP Verification' endpoint
     *
     * @var int
     */
    public int $code;

    /**
     * Phone number that person will be used instead of old one.
     *
     * @var string
     */
    public string $newPhoneNumber;

    /**
     * Code for approving phone number.
     *
     * @var int
     */
    public int $verificationCode;

    /**
     * ID that needed for approving auth method.
     *
     * @var string
     */
    #[Locked]
    public string $requestId;

    /**
     * UUID of auth method with which we interact.
     *
     * @var string
     */
    public string $selectedAuthMethodUuid;

    /**
     * Selected auth method type.
     *
     * @var string
     */
    public string $selectedAuthMethodType = '';

    /**
     * Alias name.
     *
     * @var string
     */
    public string $alias;

    public string $confidantPersonRelationshipRequestId;

    public string $confidantPersonId;

    public array $documentsRelationship = [];

    public bool $showSignatureDrawer = false;

    public bool $showAuthDrawer = false;

    public bool $showConfidantPersonDrawer = false;

    public bool $showDeactivateConfidantPersonDrawer = false;

    /**
     * List of confidant person relationship requests for current person.
     *
     * @var array
     */
    public array $confidantPersonRelationshipRequests;

    /**
     * Data for signing confidant person relationship.
     *
     * @var array
     */
    public array $approvedData;

    /**
     * Show a message about success deactivation.
     *
     * @var bool
     */
    public bool $showTerminateModal = false;

    public function mount(LegalEntity $legalEntity, Person $person): void
    {
        $this->canManageConfidantRelationships = true;
        $this->personId = $person->id;
        $this->uuid = $person->uuid;
        $this->baseMount();

        // Updating a person sends the request straight from the form, it has no leaflet step to answer this on
        $this->form->processDisclosureDataConsent = true;

        $this->form->person = Arr::toCamelCase(
            $person->load([
                'names',
                'addresses',
                'documents',
                'phones',
                'authenticationMethods',
                'confidantPersons.person:id,uuid,gender,tax_id,unzr',
                'confidantPersons.person.names',
                'confidantPersons.documentsRelationship',
                'confidantPersons.person.phones',
                'confidantPersons.person.documents'
            ])->toArray()
        );

        $uaOnlyTypes = config('ehealth.document_types_issuing_country_ua_only');

        // Fix cases where the issuing country is not set for document types that require it to be 'UA'
        foreach ($this->form->person['documents'] as &$document) {
            $document['issuingCountry'] ??= \in_array($document['type'], $uaOnlyTypes, true) ? 'UA' : '';
        }

        $this->addresses = Arr::get($this->form->person, 'addresses') ?: $this->addresses;

        if (empty($this->form->person['phones'])) {
            $this->form->person['phones'] = [['type' => null, 'number' => null]];
        }

        if (empty($this->form->person['emergencyContact'])) {
            $this->form->person['emergencyContact']['phones'] = [['type' => null, 'number' => null]];
        }

        // Initialize confidant person relationship requests for all cases
        $this->confidantPersonRelationshipRequests = $this->loadConfidantPersonRelationshipRequests($person);

        $this->loadAuthenticationMethods($person);
    }

    /**
     * Update data for created person.
     *
     * @return void
     */
    public function update(?string $authorizeWith = null): void
    {
        if (Auth::user()->cannot('create', PersonRequest::class)) {
            Session::flash('error', __('patients.policy.update'));

            return;
        }

        // The OTP method carries its own confirmation step, which fills the property in before getting here
        if ($authorizeWith !== null) {
            $this->form->authorizeWith = $authorizeWith;
        }

        $this->form->person['addresses'] = $this->addresses;
        $this->form->person['id'] = $this->uuid;

        try {
            $validated = $this->form->validate($this->form->rulesForUpdate());
            $this->formKey++;
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setAddressAwareErrorBag($exception);
            $this->formKey++;

            return;
        }

        try {
            // update
            $response = EHealth::personRequest()->create($validated);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when updating a person request');

            return;
        }

        // save in DB
        try {
            Repository::personRequest()->update(removeEmptyKeys($response->map($response->validate())));
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to update person request');

            return;
        }

        $urgent = $response->getUrgent();
        $this->form->person['id'] = $response->getData()['id'];
        $this->uploadedDocuments = $urgent['documents'] ?? [];
        $this->authenticationMethodCurrent = $urgent['authentication_method_current'] ?? [];
        $this->viewState = 'new';
        $this->showAuthMethodModal = false;

        if ($this->form->needsNhsVerification()) {
            Auth::user()->notify(new NhsVerificationNeededNotification());
        }
    }

    public function render(): View
    {
        return view('livewire.person.person-edit');
    }
}
