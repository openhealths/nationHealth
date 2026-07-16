<?php

declare(strict_types=1);

namespace App\Livewire\Person;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\Person\AuthenticationMethod;
use App\Enums\Person\AuthStep;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Livewire\Person\Traits\InteractsWithAuthenticationMethods;
use App\Livewire\Person\Traits\ManagesConfidantPersonRelationships;
use App\Models\LegalEntity;
use App\Models\Person\Person;
use App\Models\Person\PersonRequest;
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

    /**
     * Mode for the auth drawer - 'create' or 'deactivate'
     *
     * @var string|null
     */
    public ?string $authDrawerMode = null;

    public function mount(LegalEntity $legalEntity, Person $person): void
    {
        $this->canManageConfidantRelationships = true;
        $this->personId = $person->id;
        $this->uuid = $person->uuid;
        $this->baseMount();

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

        $this->address = Arr::get($this->form->person, 'addresses.0', []);

        if (empty($this->form->person['phones'])) {
            $this->form->person['phones'] = [['type' => null, 'number' => null]];
        }

        if (empty($this->form->person['emergencyContact'])) {
            $this->form->person['emergencyContact']['phones'] = [['type' => null, 'number' => null]];
        }

        $authenticationMethods = $person->authenticationMethods->toArray();

        // Initialize confidant person relationship requests for all cases
        $this->confidantPersonRelationshipRequests = $this->loadConfidantPersonRelationshipRequests($person);

        if ($person->confidantPersons->isNotEmpty()) {
            // Create a lookup map of confidant persons by their UUID
            $confidantPersonsLookup = $person->confidantPersons->keyBy(function ($confidantPerson) {
                return $confidantPerson->person->uuid;
            });

            $modifiedMethods = collect($authenticationMethods)->map(
                function (array $method) use ($confidantPersonsLookup) {
                    if ($method['type'] === AuthenticationMethod::THIRD_PERSON->value) {
                        // Find the corresponding confidant person using the authentication method's 'value' field
                        $confidantPersonRelation = $confidantPersonsLookup->get($method['value']);

                        if ($confidantPersonRelation && $confidantPersonRelation->person) {
                            $confidantPersonData = $confidantPersonRelation->person;
                            $method['confidantPerson'] = [
                                'name' => $confidantPersonData->fullName,
                                'taxId' => $confidantPersonData->taxId,
                                'unzr' => $confidantPersonData->unzr,
                                'documentsPerson' => $confidantPersonData->documents->toArray(),
                                'phones' => $confidantPersonData->phones->first() ?
                                    ['number' => $confidantPersonData->phones->first()->number] : null
                            ];
                        }
                    }

                    return $method;
                }
            );

            $this->authenticationMethods = $modifiedMethods->toArray();
        } else {
            $this->authenticationMethods = $authenticationMethods;
            $this->phoneNumber = collect($authenticationMethods)
                ->where('type', AuthenticationMethod::OTP->value)
                ->pluck('phoneNumber')
                ->first();
        }
    }

    /**
     * Update data for created person.
     *
     * @return void
     */
    public function update(): void
    {
        if (Auth::user()->cannot('create', PersonRequest::class)) {
            Session::flash('error', __('patients.policy.update'));

            return;
        }

        $this->form->person['addresses'] = [$this->address]; // must be multiple

        try {
            $addressErrors = $this->addressValidation();
            if (!empty($addressErrors)) {
                throw ValidationException::withMessages($addressErrors);
            }

            $validated = $this->form->validate($this->form->rulesForUpdate());
            $this->formKey++;
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());
            $this->formKey++;

            return;
        }

        $validated = array_merge($validated, ['addresses' => $this->form->addresses]);
        $validated['person']['id'] = $this->uuid;

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
    }

    public function render(): View
    {
        return view('livewire.person.person-edit');
    }
}
