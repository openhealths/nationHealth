<?php

declare(strict_types=1);

namespace App\Livewire\Person;

use App\Classes\Cipher\Api\CipherRequest;
use App\Classes\Cipher\Exceptions\CipherApiException;
use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\Person\AuthStep;
use App\Enums\Person\ConfidantPersonRelationshipRequestStatus;
use App\Models\ConfidantPersonRelationshipRequest;
use App\Models\Relations\AuthenticationMethod as AuthenticationMethodModel;
use App\Enums\Person\AuthenticationMethod;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\LegalEntity;
use App\Models\Person\Person;
use App\Models\Person\PersonRequest;
use App\Models\Relations\ConfidantPerson;
use App\Repositories\Repository;
use App\Rules\PhoneNumber;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use JsonException;
use Livewire\Attributes\Locked;
use Throwable;

/**
 * Used for updating person by using person request call
 */
class PersonUpdate extends PersonComponent
{
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
    public string $selectedAuthMethodType;

    /**
     * Alias name.
     *
     * @var string
     */
    public string $alias;

    /**
     * Data about new confidant person.
     *
     * @var array
     */
    public array $newConfidantPerson;

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
        $this->personId = $person->id;
        $this->uuid = $person->uuid;
        $this->baseMount();

        $this->form->person = Arr::toCamelCase(
            $person->load([
                'addresses',
                'documents',
                'phones',
                'authenticationMethods',
                'confidantPersons.person:id,uuid,gender,last_name,first_name,second_name,tax_id,unzr',
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
     * Show modal for choosing authorize with param.
     *
     * @return void
     */
    public function openAuthMethodModal(): void
    {
        $this->showAuthMethodModal = true;
        $this->authStep = AuthStep::INITIAL;
    }

    public function syncAuthMethods(): void
    {
        try {
            $response = EHealth::person()->getAuthMethods($this->uuid);
            $newAuthMethods = collect($response->validate());
            $person = Person::whereUuid($this->uuid)->firstOrFail();

            try {
                Repository::authenticationMethod()->sync($person, $newAuthMethods->toArray());

                $this->authenticationMethods = Arr::toCamelCase($newAuthMethods->toArray());
                Session::flash('success', __('patients.messages.auth_methods_synced'));
            } catch (Throwable $exception) {
                $this->logDatabaseErrors($exception, 'Failed to update authentication methods');
                Session::flash(
                    'error',
                    'Виникла помилка при оновленні методів автентифікації. Зверніться до адміністратора.'
                );
            }
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when getting auth methods');

            return;
        }
    }

    /**
     * Set auth data for future interaction.
     *
     * @param  string  $uuid
     * @param  string  $type
     * @param  AuthStep  $step
     * @return void
     */
    public function selectAuthMethod(string $uuid, string $type, AuthStep $step): void
    {
        $this->selectedAuthMethodUuid = $uuid;
        $this->selectedAuthMethodType = $type;
        $this->authStep = $step;
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
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when updating a person request');

            return;
        }

        if ($response->successful()) {
            // save in DB
            try {
                Repository::personRequest()->update(removeEmptyKeys($response->map($response->validate())));
            } catch (Throwable $exception) {
                $this->logDatabaseErrors($exception, 'Failed to update person request');
                Session::flash('error', 'Виникла помилка. Зверніться до адміністратора.');

                return;
            }

            $this->form->person['id'] = $response->getData()['id'];
            $this->uploadedDocuments = $response->getUrgent()['documents'];
            $this->viewState = 'new';
        }
    }

    /**
     * Create new OTP auth method.
     *
     * @return void
     */
    public function createOtpAuthMethod(): void
    {
        $this->changePhoneNumber($this->newPhoneNumber);
    }

    /**
     * Create new OFFLINE auth method.
     *
     * @return void
     */
    public function createOfflineAuthMethod(): void
    {
        try {
            $response = EHealth::person()->insertAuthMethod($this->uuid, AuthenticationMethod::OFFLINE);

            $this->requestId = $response->validate()['id'];
            $this->uploadedDocuments = $response->validate()['documents'];
            $this->authStep = AuthStep::CHANGE_FROM_OFFLINE;
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when creating auth method request');

            return;
        }
    }

    /**
     * Approve creating OFFLINE method.
     *
     * @return void
     */
    public function approveCreatingOffline(): void
    {
        try {
            $this->uploadDocuments();
            $response = EHealth::person()->approveAuthMethod($this->uuid, $this->requestId);

            try {
                // Update uuid and type with approved
                Person::whereUuid($this->uuid)->firstOrFail()
                    ->authenticationMethods()
                    ->create($response->validate());
            } catch (Throwable $exception) {
                $this->logDatabaseErrors($exception, 'Failed to create authentication method');
                Session::flash(
                    'error',
                    'Виникла помилка при збереженні методу автентифікації. Зверніться до адміністратора.'
                );

                return;
            }

            $this->showAuthMethodModal = false;
            Session::flash('success', __('patients.messages.offline_auth_method_added'));
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when approving offline auth method');

            return;
        }
    }

    /**
     * Verify is current phone number belongs to person.
     *
     * @return void
     */
    public function verifyOwnership(): void
    {
        try {
            $validated = $this->validate(['form.phoneNumber' => ['required', new PhoneNumber()]]);
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        try {
            $response = EHealth::verification()->findByPhoneNumber($validated['form']['phoneNumber']);

            // If phone number is found, it means that phone number is verified, so we move to step with changing number
            if ($response->validate()['phone_number'] === $validated['form']['phoneNumber']) {
                $this->changePhoneNumber($response->validate()['phone_number']);

                return;
            }
        } catch (ConnectionException $exception) {
            $this->logConnectionError($exception, 'Error when finding for OTP verification');
            Session::flash('error', "Виникла помилка. Відсутній зв'язок із ЕСОЗ");

            return;
        } catch (EHealthValidationException|EHealthResponseException $exception) {
            // If you get error then it means that number is no verify, then initialize phone verification
            if ($exception->getCode() === 404) {
                try {
                    EHealth::verification()->initialize(['phone_number' => $validated['form']['phoneNumber']]);
                    $this->authStep = AuthStep::VERIFY_PHONE;
                } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
                    $this->handleEHealthExceptions($exception, 'Error when initialize OTP verification request');

                    return;
                }
            }
        }
    }

    /**
     * Complete OTP verification.
     *
     * @return void
     */
    public function completeVerifyingOwnership(): void
    {
        try {
            $validated = $this->validate(['code' => ['required', 'integer']]);
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        try {
            EHealth::verification()->complete($this->form->phoneNumber, $validated);
            $this->authStep = AuthStep::COMPLETE_VERIFICATION;
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when complete OTP verification request');

            return;
        }
    }

    /**
     * Update phone number with verified new number.
     *
     * @return void
     */
    public function updatePhoneNumber(): void
    {
        $this->changePhoneNumber($this->newPhoneNumber);
    }

    /**
     * Approve phone number with verification code.
     *
     * @return void
     */
    public function approveUpdatingPhoneNumber(): void
    {
        $validated = $this->validate(['verificationCode' => ['required', 'digits:4']]);

        try {
            EHealth::person()->approveAuthMethod($this->uuid, $this->requestId, Arr::toSnakeCase($validated));

            try {
                // Update uuid with approved
                Person::whereUuid($this->uuid)->firstOrFail()
                    ->authenticationMethods()
                    ->whereType(AuthenticationMethod::OTP)
                    ->update(['phone_number' => $this->form->phoneNumber]);

                Session::flash('success', __('patients.messages.phone_number_changed'));
            } catch (Throwable $exception) {
                $this->logDatabaseErrors($exception, 'Failed to update authentication method phone number');
                Session::flash(
                    'error',
                    'Виникла помилка при оновленні методу автентифікації. Зверніться до адміністратора.'
                );

                return;
            }

            $this->showAuthMethodModal = false;
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when approving changing auth phone number');

            return;
        }
    }

    /**
     * Approve changing auth method type from OFFLINE to OTP.
     *
     * @return void
     */
    public function approveChangingType(): void
    {
        try {
            $this->uploadDocuments();
            $response = EHealth::person()->approveAuthMethod($this->uuid, $this->requestId);

            try {
                // Update uuid and type with approved
                Person::whereUuid($this->uuid)->firstOrFail()
                    ->authenticationMethods()
                    ->whereType(AuthenticationMethod::OFFLINE)
                    ->update(['uuid' => $response->validate()['id'], 'type' => AuthenticationMethod::OTP]);
            } catch (Throwable $exception) {
                $this->logDatabaseErrors($exception, 'Failed to update authentication method type');
                Session::flash(
                    'error',
                    'Виникла помилка при зміні методу автентифікації. Зверніться до адміністратора.'
                );

                return;
            }

            $this->showAuthMethodModal = false;
            Session::flash('success', __('patients.messages.auth_method_changed_offline_to_sms'));
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when approving auth method (from OFFLINE to OTP)');

            return;
        }
    }

    /**
     * Update alias name in auth method
     *
     * @return void
     */
    public function updateAliasName(): void
    {
        $validated = $this->validate(['alias' => ['required', 'string', 'max:255']]);

        try {
            $response = EHealth::person()->updateAuthMethod(
                $this->uuid,
                $this->selectedAuthMethodUuid,
                $validated['alias']
            );

            $this->requestId = $response->validate()['id'];

            try {
                // Update alias
                AuthenticationMethodModel::whereUuid($this->selectedAuthMethodUuid)
                    ->update(['alias' => $validated['alias']]);
            } catch (Throwable $exception) {
                $this->logDatabaseErrors($exception, 'Failed to update authentication method type');
                Session::flash(
                    'error',
                    'Виникла помилка при зміні методу автентифікації. Зверніться до адміністратора.'
                );

                return;
            }

            $this->authStep = AuthStep::UPDATE_ALIAS;
            Session::flash('success', __('patients.messages.method_name_updated'));
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when updating alias auth method');

            return;
        }
    }

    /**
     * Update alias of auth method.
     *
     * @return void
     */
    public function approveUpdatingAlias(): void
    {
        try {
            if ($this->selectedAuthMethodType === AuthenticationMethod::OFFLINE->value) {
                $this->uploadDocuments();
                EHealth::person()->approveAuthMethod($this->uuid, $this->requestId);
            } else {
                $validated = $this->validate(['verificationCode' => ['required', 'digits:4']]);
                EHealth::person()->approveAuthMethod($this->uuid, $this->requestId, Arr::toSnakeCase($validated));
            }

            try {
                // Update alias value
                AuthenticationMethodModel::whereUuid($this->selectedAuthMethodUuid)->update(['alias' => $this->alias]);
            } catch (Throwable $exception) {
                $this->logDatabaseErrors($exception, 'Failed to update authentication method alias');
                Session::flash(
                    'error',
                    'Виникла помилка при оновленні назви методу автентифікації. Зверніться до адміністратора.'
                );

                return;
            }

            $this->showAuthMethodModal = false;
            Session::flash('success', __('patients.messages.auth_method_name_changed'));
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when approving auth method request');

            return;
        }
    }

    /**
     * Start deactivating THIRD_PERSON auth method.
     *
     * @param  string|null  $authMethodUuid
     * @return void
     */
    public function deactivateAuthMethod(?string $authMethodUuid): void
    {
        if (!$authMethodUuid) {
            Session::flash('error', __('patients.messages.sync_auth_methods_and_try_again'));

            return;
        }

        $this->selectedAuthMethodUuid = $authMethodUuid;

        try {
            $response = EHealth::person()->deactivateAuthMethod($this->uuid, $authMethodUuid);
            $this->requestId = $response->getData()['id'];
            $this->authStep = AuthStep::APPROVE_DEACTIVATING_METHOD;
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when deactivating auth method');
            Session::flash(
                'error',
                'Виникла помилка при деактивації методу автентифікації. Зверніться до адміністратора.'
            );

            return;
        }
    }

    /**
     * Confirm deactivating auth method by providing sms code from confidant person phone number.
     *
     * @return void
     */
    public function approveDeactivatingAuthMethod(): void
    {
        try {
            $validated = $this->form->validate($this->form->rulesForApprove());
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        try {
            EHealth::person()->approveAuthMethod($this->uuid, $this->requestId, Arr::toSnakeCase($validated));

            try {
                AuthenticationMethodModel::whereUuid($this->selectedAuthMethodUuid)->delete();

                $this->showAuthMethodModal = false;
                Session::flash('success', __('patients.messages.auth_method_deactivated'));
            } catch (Throwable $exception) {
                $this->logDatabaseErrors($exception, 'Error when approving deactivate auth method');
                Session::flash(
                    'error',
                    'Виникла помилка при підтвердженні деактивації методу автентифікації. Зверніться до адміністратора.'
                );

                return;
            }
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when approve deactivating auth method');

            return;
        }
    }

    /**
     * Choose confidant person for adding to new auth method.
     *
     * @param  string  $confidantPersonId
     * @return void
     */
    public function chooseConfidantFromRelation(string $confidantPersonId): void
    {
        $this->confidantPersonId = $confidantPersonId;
        $this->authStep = AuthStep::ADD_ALIAS_FOR_THIRD_PERSON;
    }

    /**
     * Start creating new auth method for THIRD PERSON with provided alias name.
     *
     * @param  string  $alias
     * @return void
     */
    public function addAuthMethodFromRelation(string $alias): void
    {
        $this->alias = $alias;

        try {
            $response = EHealth::person()->insertAuthMethod(
                $this->uuid,
                AuthenticationMethod::THIRD_PERSON,
                value: $this->confidantPersonId,
                alias: $alias
            );
            $this->requestId = $response->getData()['id'];
            $this->authStep = AuthStep::APPROVE_ADDING_NEW_METHOD;
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when adding auth method from relation');

            return;
        }
    }

    /**
     * Approve adding new auth method with code from sms.
     *
     * @return void
     */
    public function approveAddingNewMethod(): void
    {
        $validated = $this->validate(['verificationCode' => ['required', 'digits:4']]);

        try {
            EHealth::person()->approveAuthMethod($this->uuid, $this->requestId, Arr::toSnakeCase($validated));

            $forCreate = [
                'type' => AuthenticationMethod::THIRD_PERSON,
                'value' => $this->confidantPersonId,
                'alias' => $this->alias
            ];

            try {
                // Create new auth method
                Person::whereUuid($this->uuid)->firstOrFail()
                    ->authenticationMethods()
                    ->create($forCreate);

                Session::flash('success', __('patients.messages.new_auth_method_added'));
            } catch (Throwable $exception) {
                $this->logDatabaseErrors($exception, 'Failed to update authentication method phone number');
                Session::flash(
                    'error',
                    'Виникла помилка при оновленні методу автентифікації. Зверніться до адміністратора.'
                );

                return;
            }

            $this->showAuthMethodModal = false;
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when approving changing auth phone number');

            return;
        }
    }

    /**
     * Resend code to phone number.
     *
     * @return void
     */
    public function resendCode(): void
    {
        try {
            EHealth::person()->resendAuthOtp($this->uuid, $this->requestId);
            Session::flash('success', __('patients.messages.code_resent_to_phone'));
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when resending SMS');

            return;
        }
    }

    /**
     * Sync confidant persons with phones and documents.
     *
     * @return void
     */
    public function syncConfidantPersons(): void
    {
        try {
            $response = EHealth::person()->getConfidantPersonRelationships($this->uuid);
        } catch (ConnectionException $exception) {
            $this->logConnectionError($exception, 'Error when getting auth methods');
            Session::flash('error', "Виникла помилка. Відсутній зв'язок із ЕСОЗ");

            return;
        } catch (EHealthValidationException|EHealthResponseException $exception) {
            $this->logEHealthException($exception, 'Error when getting auth methods');

            if ($exception instanceof EHealthValidationException) {
                Session::flash('error', $exception->getFormattedMessage());
            } else {
                Session::flash('error', 'Помилка від ЕСОЗ: ' . $exception->getMessage());
            }

            return;
        }

        // Map the API response to the form structure
        $confidantPersonsData = collect($response->getData())->map(function ($relationship) {
            $person = $relationship['confidant_person'];
            $person['documents'] = $relationship['confidant_person']['documents_person'];

            return [
                'person' => $person,
                'documentsRelationship' => $relationship['documents_relationship'],
                'activeTo' => convertToAppDateFormat($relationship['active_to'])
            ];
        })->toArray();

        // Assign to the form
        $this->form->person['confidantPersons'] = $confidantPersonsData;

        // Sync to database
        Repository::confidantPerson()->sync($response->getData(), $this->uuid);

        Session::flash('success', __('patients.messages.confidant_persons_synced'));
    }

    /**
     * First step for adding new confidant person relationship.
     *
     * @return void
     */
    public function createNewConfidantPersonRelationshipRequest(): void
    {
        if (Auth::user()->cannot('create', ConfidantPerson::class)) {
            Session::flash('error', __('patients.policy.create_confidant'));

            return;
        }

        // Set the properties that validation expects
        $this->confidantPersonId = $this->selectedConfidantPersonId ?? '';
        $this->documentsRelationship = $this->newConfidantPerson['documentsRelationship'] ?? [];

        try {
            $validated = $this->validate($this->form->rulesForCreateNewConfidantPersonRelationshipRequest());
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        try {
            $response = EHealth::person()->createConfidantRelationship($this->uuid, $validated);

            // Update table of requests
            $validatedData = $response->validate();
            $validatedData['status'] = ConfidantPersonRelationshipRequestStatus::from($validatedData['status']);
            $this->confidantPersonRelationshipRequests = array_merge(
                [$validatedData],
                $this->confidantPersonRelationshipRequests
            );

            // Set value of attributes for future interaction
            $this->confidantPersonRelationshipRequestId = $validatedData['uuid'];
            $this->uploadedDocuments = $response->getUrgent()['documents'];

            try {
                $dataForCreate = $response->validate();
                $dataForCreate['person_id'] = Person::whereUuid($this->uuid)->value('id');
                $dataForCreate['documents'] = $response->getUrgent()['documents'];

                ConfidantPersonRelationshipRequest::create($dataForCreate);
            } catch (Throwable $exception) {
                $this->logDatabaseErrors($exception, 'Failed to create confidant person relationship request');
                Session::flash('error', 'Виникла помилка при збереженні. Зверніться до адміністратора.');

                return;
            }
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when creating confidant person relationship');

            return;
        }

        $this->authDrawerMode = 'create';
        $this->showConfidantPersonDrawer = false;
        $this->showAuthDrawer = true;
    }

    /**
     * Resend SMS code for new confidant person.
     *
     * @return void
     */
    public function resendCodeOnConfidantPersonRelationship(): void
    {
        if (Auth::user()->cannot('create', ConfidantPerson::class)) {
            Session::flash('error', __('patients.policy.resend_sms'));

            return;
        }

        try {
            EHealth::person()->resendAuthOtpOnConfidantPersonRelationship(
                $this->uuid,
                $this->confidantPersonRelationshipRequestId
            );

            Session::flash('success', __('patients.messages.code_resent_to_phone'));
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when resending SMS');

            return;
        }

        $this->authDrawerMode = 'create';
    }

    /**
     * Continue adding new confidant person relationship.
     *
     * @param  string  $requestId
     * @return void
     */
    public function approveFromRequest(string $requestId): void
    {
        $this->authDrawerMode = 'create';
        $this->showAuthDrawer = true;
        $this->confidantPersonRelationshipRequestId = $requestId;

        $this->uploadedDocuments = ConfidantPersonRelationshipRequest::whereUuid($requestId)
            ->value('documents') ?? [];
    }

    /**
     * Second step of creating new confidant person relationship in which we approve by providing confidence documents.
     *
     * @return void
     */
    public function approveConfidantPersonRelationshipRequest(): void
    {
        if (Auth::user()->cannot('create', ConfidantPerson::class)) {
            Session::flash('error', __('patients.policy.approve_confidant'));

            return;
        }

        try {
            $validated = $this->form->validate($this->form->rulesForApprove());
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        try {
            $this->uploadDocuments();

            $response = EHealth::person()->approveConfidantPersonRelationshipRequest(
                $this->uuid,
                $this->confidantPersonRelationshipRequestId,
                Arr::toSnakeCase($validated)
            );

            $this->approvedData = $response->getData();
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when approving confidant person relationship');

            return;
        }

        $this->authDrawerMode = null;
        $this->showSignatureDrawer = true;
    }

    /**
     * Sign with KEP data about new confidant person relationship.
     *
     * @return void
     */
    public function signConfidantPersonRelationship(): void
    {
        if (Auth::user()->cannot('create', ConfidantPerson::class)) {
            Session::flash('error', __('patients.policy.sign_confidant'));

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
            $signedContent = new CipherRequest()->signData(
                $this->approvedData,
                $validated['knedp'],
                $validated['keyContainerUpload'],
                $validated['password'],
                Auth::user()->party->taxId
            );
        } catch (ConnectionException $exception) {
            $this->logConnectionError($exception, 'Error connecting to Cipher when signing data');
            Session::flash('error', __('validation.custom.connection_exception'));

            return;
        } catch (CipherApiException $exception) {
            $this->logCipherError($exception, 'Cipher API error when signing data');
            Session::flash('error', $exception->getMessage());

            return;
        } catch (JsonException $exception) {
            $this->logDatabaseErrors($exception, 'JSON encoding error when signing data');
            Session::flash('error', 'Помилка обробки даних. Зверніться до адміністратора.');

            return;
        }

        try {
            $response = EHealth::person()->signConfidantPersonRelationshipRequest(
                $this->uuid,
                $this->confidantPersonRelationshipRequestId,
                ['signed_content' => $signedContent->getBase64Data()]
            );

            // Save confidant person relationship to database using repository
            try {
                if ($this->authDrawerMode === 'create') {
                    $personData = collect($this->confidantPerson)->firstWhere('id', $this->selectedConfidantPersonId);
                    Repository::confidantPerson()->createFromSignedResponse($response->getData(), $this->uuid, $personData);

                    $this->showSignatureDrawer = false;
                    $this->showAuthDrawer = false;
                } else {
                    ConfidantPerson::whereUuid($response->getData()['confidant_person_relationship']['id'])
                        ->update(['active_to' => now()]);

                    $this->showSignatureDrawer = false;
                    $this->showTerminateModal = true;
                }

                Session::flash('success', __('patients.messages.new_confidant_person_added'));
            } catch (Exception $exception) {
                $this->logDatabaseErrors($exception, 'Failed to create confidant person relationship');
                Session::flash('error', 'Виникла помилка при збереженні. Зверніться до адміністратора.');

                return;
            }
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when signing confidant person relationship');

            return;
        }
    }

    /**
     * Sync list of requests for adding confidant person.
     *
     * @return void
     */
    public function syncConfidantPersonRelationshipRequestsList(): void
    {
        try {
            $response = EHealth::person()->getConfidantPersonRelationshipRequestsList($this->uuid);
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions(
                $exception,
                'Error when getting confidant person relationship requests list'
            );

            return;
        }

        try {
            $person = Person::whereUuid($this->uuid)->firstOrFail();
            $data = $response->validate();

            Repository::confidantPersonRelationshipRequestRepository()->sync($person, $data);

            // Refresh the property to show updated data
            $this->confidantPersonRelationshipRequests = $this->loadConfidantPersonRelationshipRequests($person);

            Session::flash('success', __('patients.messages.confidant_requests_list_updated'));
        } catch (Exception $exception) {
            Log::error('Failed to sync confidant person relationship requests', [
                'person_uuid' => $this->uuid,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString()
            ]);

            Session::flash('error', __('Помилка при синхронізації запитів на створення законних представників'));
        }
    }

    /**
     * Start to deactivate confidant person.
     *
     * @param  string  $confidantPersonRelationUuid
     * @param  array  $documents
     * @return void
     */
    public function deactivateConfidantPerson(string $confidantPersonRelationUuid, array $documents): void
    {
        try {
            $validated = Validator::make([
                'confidantPersonRelationUuid' => $confidantPersonRelationUuid,
                'documents' => $documents
            ], $this->form->rulesForDeactivateConfidantPerson())->validate();
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        try {
            // Find the main person
            $mainPerson = Person::whereUuid($this->uuid)->firstOrFail();

            // Find suitable authentication method using repository
            $authResult = Repository::confidantPerson()->findAuthMethodForDeactivation($mainPerson, $confidantPersonRelationUuid);

            if ($authResult['error']) {
                Session::flash('error', $authResult['error']);

                return;
            }

            $authMethodUuid = $authResult['auth_method_uuid'];

            $response = EHealth::person()->deactivateConfidantRelationship(
                $this->uuid,
                $validated['confidantPersonRelationUuid'],
                Arr::toSnakeCase($validated['documents']),
                $authMethodUuid
            );

            $this->confidantPersonRelationshipRequestId = $response->getData()['id'];
            $this->uploadedDocuments = $response->getUrgent()['documents'];

            try {
                $dataForCreate = $response->validate();
                $dataForCreate['person_id'] = Person::whereUuid($this->uuid)->value('id');
                $dataForCreate['documents'] = $response->getUrgent()['documents'];

                ConfidantPersonRelationshipRequest::create($dataForCreate);
            } catch (Throwable $exception) {
                $this->logDatabaseErrors($exception, 'Failed to create confidant person relationship request');
                Session::flash('error', 'Виникла помилка при збереженні. Зверніться до адміністратора.');

                return;
            }
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when deactivating auth method');

            return;
        }

        $this->showDeactivateConfidantPersonDrawer = false;
        $this->authDrawerMode = 'deactivate';
        $this->showAuthDrawer = true;
    }

    /**
     * Change phone number with new one.
     *
     * @param  string  $phoneNumber
     * @return void
     */
    protected function changePhoneNumber(string $phoneNumber): void
    {
        $validated = Validator::make(
            ['newPhoneNumber' => $phoneNumber],
            ['newPhoneNumber' => 'required', new PhoneNumber()]
        )->validate();

        try {
            $response = EHealth::person()->insertAuthMethod(
                $this->uuid,
                AuthenticationMethod::OTP,
                $validated['newPhoneNumber']
            );
            $this->requestId = $response->getData()['id'];
            $this->uploadedDocuments = $response->getUrgent()['documents'] ?? [];

            // If the change type from OTP to Offline, then show the step, request to change the phone number
            if ($this->selectedAuthMethodType === AuthenticationMethod::OFFLINE->value) {
                $this->authStep = AuthStep::CHANGE_FROM_OFFLINE;
            } else {
                $this->authStep = AuthStep::CHANGE_PHONE;
            }
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when creating auth method request');

            return;
        }
    }

    /**
     * Load confidant person relationship requests from database as arrays with enum conversion
     *
     * @param  Person  $person
     * @return array
     */
    private function loadConfidantPersonRelationshipRequests(Person $person): array
    {
        $requests = $person->confidantPersonRelationshipRequests()
            ->whereStatus(ConfidantPersonRelationshipRequestStatus::NEW)
            ->orderByDesc('created_at')
            ->get()
            ->toArray();

        return $this->convertRequestStatusesToEnums($requests);
    }

    /**
     * Convert status strings to enum instances in request arrays
     *
     * @param  array  $requests
     * @return array
     */
    private function convertRequestStatusesToEnums(array $requests): array
    {
        return array_map(static fn (array $request) => array_merge($request, [
            'status' => ConfidantPersonRelationshipRequestStatus::from($request['status'])
        ]), $requests);
    }

    public function render(): View
    {
        return view('livewire.person.person-edit');
    }
}
