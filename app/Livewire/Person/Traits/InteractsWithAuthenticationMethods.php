<?php

declare(strict_types=1);

namespace App\Livewire\Person\Traits;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\Person\AuthenticationMethod;
use App\Enums\Person\AuthenticationMethodAction;
use App\Enums\Person\AuthStep;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\Person\Person;
use App\Models\Relations\AuthenticationMethod as AuthenticationMethodModel;
use App\Models\Relations\ConfidantPerson;
use App\Repositories\Repository;
use App\Rules\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

trait InteractsWithAuthenticationMethods
{
    /**
     * Load the authentication methods of the patient, decorating every method that authenticates through a
     * confidant with the data of that confidant.
     *
     * @param  Person  $person
     * @return void
     */
    protected function loadAuthenticationMethods(Person $person): void
    {
        $person->loadMissing([
            'authenticationMethods',
            'confidantPersons.person.names',
            'confidantPersons.person.documents',
            'confidantPersons.person.phones'
        ]);

        $confidantPersons = $person->confidantPersons->keyBy(
            static fn (ConfidantPerson $confidantPerson): string => $confidantPerson->person->uuid
        );

        $this->authenticationMethods = $person->authenticationMethods
            ->map(static function (AuthenticationMethodModel $authenticationMethod) use ($confidantPersons): array {
                $method = $authenticationMethod->toArray();

                if ($method['type'] !== AuthenticationMethod::THIRD_PERSON->value) {
                    return $method;
                }

                $relationship = $confidantPersons->get($method['value']);

                if ($relationship === null) {
                    return $method;
                }

                $confidant = $relationship->person;
                $method['confidantPerson'] = [
                    'name' => $confidant->fullName,
                    'taxId' => $confidant->taxId,
                    'unzr' => $confidant->unzr,
                    'documentsPerson' => $confidant->documents->toArray(),
                    'phones' => $confidant->phones->first() === null
                        ? null
                        : ['number' => $confidant->phones->first()->number],
                    'relationshipIsActive' => $relationship->isActive
                ];

                return $method;
            })
            ->values()
            ->toArray();

        $this->phoneNumber = collect($this->authenticationMethods)
            ->firstWhere('type', AuthenticationMethod::OTP->value)['phoneNumber'] ?? null;
    }

    /**
     * Whether another authentication method can be added at all: a method that authenticates the patient
     * themselves rules out every further one.
     *
     * @return bool
     */
    public function canAddAuthenticationMethod(): bool
    {
        return collect($this->authenticationMethods)
            ->whereIn('type', [AuthenticationMethod::OTP->value, AuthenticationMethod::OFFLINE->value])
            ->isEmpty();
    }

    /**
     * Whether the patient can be given a method that authenticates them in person: a patient who has a confidant,
     * or who already authenticates through one, keeps being authenticated through that confidant.
     *
     * @return bool
     */
    public function canAddSelfAuthenticationMethod(): bool
    {
        return $this->canAddAuthenticationMethod()
            && empty($this->form->person['confidantPersons'])
            && collect($this->authenticationMethods)
                ->where('type', AuthenticationMethod::THIRD_PERSON->value)
                ->isEmpty();
    }

    /**
     * Whether the patient can be given a method that authenticates them through a confidant: a patient whose
     * documents prove their own legal capacity acts on their own and cannot be represented by a confidant.
     *
     * @return bool
     */
    public function canAddThirdPersonAuthenticationMethod(): bool
    {
        return !$this->form->hasLegalCapacityDocument();
    }

    /**
     * Whether the method authenticates through a confidant the patient still has a standing relationship with.
     *
     * @param  string  $authMethodUuid
     * @return bool
     */
    protected function hasActiveConfidantRelationship(string $authMethodUuid): bool
    {
        $method = collect($this->authenticationMethods)->firstWhere('uuid', $authMethodUuid);

        return (bool) ($method['confidantPerson']['relationshipIsActive'] ?? false);
    }

    /**
     * Tell whether the user is not allowed to manage the authentication methods of the patient and flash the
     * message about it.
     *
     * @return bool
     */
    protected function deniesManagingAuthMethods(): bool
    {
        if (Auth::user()->cannot('update', AuthenticationMethodModel::class)) {
            Session::flash('error', __('patients.policy.update_auth_method'));

            return true;
        }

        return false;
    }

    public function syncAuthMethods(): void
    {
        if (Auth::user()->cannot('view', AuthenticationMethodModel::class)) {
            Session::flash('error', __('patients.policy.view_auth_methods'));

            return;
        }

        try {
            $response = EHealth::person()->getAuthMethods($this->uuid);
            $authenticationMethods = $response->validate();
            $person = Person::whereUuid($this->uuid)->firstOrFail();

            try {
                Repository::authenticationMethod()->sync($person, $authenticationMethods);

                $this->loadAuthenticationMethods($person->fresh());
                Session::flash('success', __('patients.messages.auth_methods_synced'));
            } catch (Throwable $exception) {
                $this->handleDatabaseErrors($exception, 'Failed to update authentication methods');
            }
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when getting auth methods');
        }
    }

    public function openAuthMethodModal(): void
    {
        $this->showAuthMethodModal = true;
        $this->authStep = AuthStep::INITIAL;
    }

    public function selectAuthMethod(string $uuid, string $type, AuthStep $step): void
    {
        $this->selectedAuthMethodUuid = $uuid;
        $this->selectedAuthMethodType = $type;
        $this->authStep = $step;
    }

    public function createOtpAuthMethod(): void
    {
        if ($this->deniesManagingAuthMethods()) {
            return;
        }

        try {
            Validator::make([
                'action' => AuthenticationMethodAction::INSERT->value,
                'authenticationMethod' => [
                    'type' => AuthenticationMethod::OTP->value,
                    'phoneNumber' => $this->newPhoneNumber
                ]
            ], $this->rulesForInsert())->validate();
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $this->form->phoneNumber = $this->newPhoneNumber;
        $this->changePhoneNumber($this->newPhoneNumber);
    }

    /**
     * Get validation rules for creating an authentication method.
     *
     * @return array
     */
    protected function rulesForInsert(): array
    {
        return [
            'action' => ['required', 'string', 'in:' . AuthenticationMethodAction::INSERT->value],
            'authenticationMethod' => ['required', 'array'],
            'authenticationMethod.type' => [
                'required',
                'string',
                function (string $attribute, mixed $value, callable $fail): void {
                    $currentTypes = collect($this->authenticationMethods)->pluck('type');

                    $person = Person::whereUuid($this->uuid)->firstOrFail();

                    if ($value === AuthenticationMethod::THIRD_PERSON->value
                        && $this->form->hasLegalCapacityDocument()) {
                        $fail(__('patients.errors.authMethod.no_third_person_for_legally_capable_person'));

                        return;
                    }

                    if ($value !== AuthenticationMethod::THIRD_PERSON->value && $person->confidantPersons()->exists()) {
                        $fail(__('patients.errors.authMethod.only_third_person_for_person_with_confidants'));

                        return;
                    }

                    if ($value === AuthenticationMethod::OTP->value
                        && $currentTypes->contains(AuthenticationMethod::OTP->value)) {
                        $fail(__('patients.errors.person_already_has_otp_auth_method'));

                        return;
                    }

                    if ($value === AuthenticationMethod::OTP->value
                        && $currentTypes->contains(AuthenticationMethod::OFFLINE->value)) {
                        $fail(__('patients.errors.cannot_set_otp_auth_method_if_person_has_offline'));

                        return;
                    }

                    if ($value === AuthenticationMethod::OFFLINE->value
                        && $currentTypes->contains(AuthenticationMethod::OFFLINE->value)) {
                        $fail(__('patients.errors.person_already_has_offline_auth_method'));

                        return;
                    }

                    if ($value === AuthenticationMethod::OFFLINE->value
                        && $currentTypes->contains(AuthenticationMethod::OTP->value)) {
                        $fail(__('patients.errors.cannot_set_offline_auth_method_if_person_has_otp'));

                        return;
                    }

                    if ($person->age <= config('ehealth.no_self_auth_age') && in_array($value, [
                            AuthenticationMethod::OTP->value,
                            AuthenticationMethod::OFFLINE->value
                        ], true)) {
                        $fail(__('patients.errors.cannot_have_self_auth_method'));
                    }
                }
            ],
            'authenticationMethod.phoneNumber' => [
                'required_if:authenticationMethod.type,' . AuthenticationMethod::OTP->value,
                'prohibited_if:authenticationMethod.type,' . AuthenticationMethod::OFFLINE->value,
                new PhoneNumber()
            ],
            'authenticationMethod.value' => [
                'required_if:authenticationMethod.type,' . AuthenticationMethod::THIRD_PERSON->value,
                'prohibited_if:authenticationMethod.type,' . AuthenticationMethod::OTP->value . ',' . AuthenticationMethod::OFFLINE->value,
                'uuid'
            ],
            'authenticationMethod.alias' => [
                'nullable',
                'required_if:authenticationMethod.type,' . AuthenticationMethod::THIRD_PERSON->value,
                'string',
                'max:255'
            ]
        ];
    }

    public function createOfflineAuthMethod(): void
    {
        if ($this->deniesManagingAuthMethods()) {
            return;
        }

        try {
            Validator::make([
                'action' => AuthenticationMethodAction::INSERT->value,
                'authenticationMethod' => ['type' => AuthenticationMethod::OFFLINE->value]
            ], $this->rulesForInsert())->validate();
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        try {
            $response = EHealth::person()->insertAuthMethod($this->uuid, AuthenticationMethod::OFFLINE);

            $this->requestId = $response->validate()['id'];
            $this->uploadedDocuments = $response->validate()['documents'];
            $this->authStep = AuthStep::CHANGE_FROM_OFFLINE;
            // The modal carries the document upload step, so it is opened once the request is actually created
            $this->showAuthMethodModal = true;
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when creating auth method request');
        }
    }

    /**
     * Approve creating an OFFLINE authentication method.
     */
    public function approveCreatingOffline(): void
    {
        if ($this->deniesManagingAuthMethods() || !$this->uploadDocuments()) {
            return;
        }

        try {
            $response = EHealth::person()->approveAuthMethod($this->uuid, $this->requestId);

            try {
                $person = Person::whereUuid($this->uuid)->firstOrFail();
                $person->authenticationMethods()->create($response->validate());

                $this->loadAuthenticationMethods($person);
            } catch (Throwable $exception) {
                $this->handleDatabaseErrors($exception, 'Failed to create authentication method');

                return;
            }

            $this->showAuthMethodModal = false;
            Session::flash('success', __('patients.messages.offline_auth_method_added'));
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when approving offline auth method');
        }
    }

    public function verifyOwnership(): void
    {
        if ($this->deniesManagingAuthMethods()) {
            return;
        }

        try {
            $validated = $this->validate(['form.phoneNumber' => ['required', new PhoneNumber()]]);
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        try {
            $response = EHealth::verification()->findByPhoneNumber($validated['form']['phoneNumber']);

            if ($response->validate()['phone_number'] === $validated['form']['phoneNumber']) {
                $this->changePhoneNumber($response->validate()['phone_number']);

                return;
            }
        } catch (EHealthConnectionException $exception) {
            $exception->handle('Error when finding for OTP verification');

            return;
        } catch (EHealthValidationException|EHealthResponseException $exception) {
            // Only a missing verification means the number still has to be confirmed, the rest is a failure
            if ($exception->getCode() !== 404) {
                $exception->handle('Error when finding for OTP verification');

                return;
            }
        }

        try {
            EHealth::verification()->initialize(['phone_number' => $validated['form']['phoneNumber']]);
            $this->authStep = AuthStep::VERIFY_PHONE;
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when initialize OTP verification request');
        }
    }

    public function completeVerifyingOwnership(): void
    {
        if ($this->deniesManagingAuthMethods()) {
            return;
        }

        try {
            $validated = $this->validate(['code' => ['required', 'digits:4']]);
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        try {
            EHealth::verification()->complete($this->form->phoneNumber, $validated);
            $this->authStep = AuthStep::COMPLETE_VERIFICATION;
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when complete OTP verification request');

            return;
        }
    }

    /**
     * Set the number the patient has just confirmed ownership of as the authentication phone number.
     *
     * @return void
     */
    public function updatePhoneNumber(): void
    {
        $this->changePhoneNumber($this->form->phoneNumber);
    }

    protected function changePhoneNumber(string $phoneNumber): void
    {
        if ($this->deniesManagingAuthMethods()) {
            return;
        }

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
            $this->requestId = $response->validate()['id'];
            $urgent = $response->getUrgent();
            $this->uploadedDocuments = $urgent['documents'] ?? [];

            if (data_get($urgent, 'authentication_method_current.type') === AuthenticationMethod::OFFLINE->value) {
                $this->authStep = AuthStep::CHANGE_FROM_OFFLINE;
            } else {
                $this->authStep = AuthStep::CHANGE_PHONE;
            }
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when creating auth method request');
        }
    }

    /**
     * Approve phone number with a verification code.
     */
    public function approveUpdatingPhoneNumber(): void
    {
        if ($this->deniesManagingAuthMethods()) {
            return;
        }

        $validated = $this->validate(['verificationCode' => ['required', 'digits:4']]);

        try {
            EHealth::person()->approveAuthMethod($this->uuid, $this->requestId, Arr::toSnakeCase($validated));

            try {
                $person = Person::whereUuid($this->uuid)->firstOrFail();
                $person->authenticationMethods()
                    ->whereType(AuthenticationMethod::OTP)
                    ->update(['phone_number' => $this->form->phoneNumber]);

                $this->loadAuthenticationMethods($person);

                Session::flash('success', __('patients.messages.phone_number_changed'));
            } catch (Throwable $exception) {
                $this->handleDatabaseErrors($exception, 'Failed to update authentication method phone number');

                return;
            }

            $this->showAuthMethodModal = false;
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when approving changing auth phone number');
        }
    }

    /**
     * Approve changing an authentication method from OFFLINE to OTP.
     */
    public function approveChangingType(): void
    {
        if ($this->deniesManagingAuthMethods() || !$this->uploadDocuments()) {
            return;
        }

        try {
            $response = EHealth::person()->approveAuthMethod($this->uuid, $this->requestId);

            try {
                $person = Person::whereUuid($this->uuid)->firstOrFail();
                $person->authenticationMethods()
                    ->whereType(AuthenticationMethod::OFFLINE)
                    ->update(['uuid' => $response->validate()['id'], 'type' => AuthenticationMethod::OTP]);

                $this->loadAuthenticationMethods($person);
            } catch (Throwable $exception) {
                $this->handleDatabaseErrors($exception, 'Failed to update authentication method type');

                return;
            }

            $this->showAuthMethodModal = false;
            Session::flash('success', __('patients.messages.auth_method_changed_offline_to_sms'));
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when approving auth method (from OFFLINE to OTP)');
        }
    }

    /**
     * Start an authentication method alias update.
     */
    public function updateAliasName(): void
    {
        if ($this->deniesManagingAuthMethods()) {
            return;
        }

        // Renaming a method that authenticates through a confidant needs that relationship to still stand
        if ($this->selectedAuthMethodType === AuthenticationMethod::THIRD_PERSON->value
            && !$this->hasActiveConfidantRelationship($this->selectedAuthMethodUuid)) {
            Session::flash('error', __('patients.errors.confidant_relationship_required'));

            $this->showAuthMethodModal = false;
            $this->showConfidantPersonDrawer = true;

            return;
        }

        try {
            $validated = Validator::make([
                'action' => AuthenticationMethodAction::UPDATE->value,
                'authenticationMethod' => [
                    'uuid' => $this->selectedAuthMethodUuid,
                    'alias' => $this->alias
                ]
            ], $this->rulesForUpdate())->validate();
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        try {
            $response = EHealth::person()->updateAuthMethod(
                $this->uuid,
                $validated['authenticationMethod']['uuid'],
                $validated['authenticationMethod']['alias']
            );

            $this->requestId = $response->validate()['id'];
            $this->alias = $validated['authenticationMethod']['alias'];
            // A method confirmed by documents is approved by uploading them, not by a code from an SMS
            $this->uploadedDocuments = $response->getUrgent()['documents'] ?? [];

            $this->authStep = AuthStep::UPDATE_ALIAS;
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when updating alias auth method');
        }
    }

    /**
     * Get validation rules for updating an authentication method.
     *
     * @return array
     */
    protected function rulesForUpdate(): array
    {
        return [
            'action' => ['required', 'string', 'in:UPDATE'],
            'authenticationMethod' => ['required', 'array'],
            'authenticationMethod.uuid' => ['required', 'uuid'],
            'authenticationMethod.alias' => ['required', 'string', 'max:255']
        ];
    }

    /**
     * Approve an authentication method alias update.
     */
    public function approveUpdatingAlias(): void
    {
        if ($this->deniesManagingAuthMethods()) {
            return;
        }

        try {
            if ($this->selectedAuthMethodType === AuthenticationMethod::OFFLINE->value) {
                if (!$this->uploadDocuments()) {
                    return;
                }

                EHealth::person()->approveAuthMethod($this->uuid, $this->requestId);
            } else {
                $validated = $this->validate(['verificationCode' => ['required', 'digits:4']]);
                EHealth::person()->approveAuthMethod($this->uuid, $this->requestId, Arr::toSnakeCase($validated));
            }

            try {
                AuthenticationMethodModel::whereUuid($this->selectedAuthMethodUuid)->update(['alias' => $this->alias]);

                $this->loadAuthenticationMethods(Person::whereUuid($this->uuid)->firstOrFail());
            } catch (Throwable $exception) {
                $this->handleDatabaseErrors($exception, 'Failed to update authentication method alias');

                return;
            }

            $this->showAuthMethodModal = false;
            Session::flash('success', __('patients.messages.auth_method_name_changed'));
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when approving auth method request');
        }
    }

    public function deactivateAuthMethod(?string $authMethodUuid): void
    {
        if ($this->deniesManagingAuthMethods()) {
            return;
        }

        if (!$authMethodUuid) {
            Session::flash('error', __('patients.messages.sync_auth_methods_and_try_again'));

            return;
        }

        try {
            $validated = Validator::make([
                'action' => AuthenticationMethodAction::DEACTIVATE->value,
                'authenticationMethod' => ['uuid' => $authMethodUuid]
            ], $this->rulesForDeactivate())->validate();
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $this->selectedAuthMethodUuid = $validated['authenticationMethod']['uuid'];

        try {
            $response = EHealth::person()->deactivateAuthMethod($this->uuid, $this->selectedAuthMethodUuid);
            $this->requestId = $response->validate()['id'];
            $this->authStep = AuthStep::APPROVE_DEACTIVATING_METHOD;
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when deactivating auth method');
        }
    }

    /**
     * Get validation rules for deactivating an authentication method.
     *
     * @return array
     */
    protected function rulesForDeactivate(): array
    {
        return [
            'action' => ['required', 'string', 'in:DEACTIVATE'],
            'authenticationMethod' => ['required', 'array'],
            'authenticationMethod.uuid' => [
                'required',
                'uuid',
                function (string $attribute, mixed $value, callable $fail): void {
                    $authenticationMethod = collect($this->authenticationMethods)->firstWhere('uuid', $value);

                    if ($authenticationMethod['type'] !== AuthenticationMethod::THIRD_PERSON->value) {
                        $fail(__('patients.errors.authMethod.only_third_person_can_be_deactivated'));

                        return;
                    }

                    if (count($this->authenticationMethods) <= 1) {
                        $fail(__('patients.errors.authMethod.cannot_deactivate_last'));

                        return;
                    }

                    if (!CarbonImmutable::parse($authenticationMethod['ehealthEndedAt'])->isFuture()) {
                        $fail(__('patients.errors.authMethod.cannot_deactivate_inactive'));
                    }
                }
            ]
        ];
    }

    /**
     * Approve an authentication method deactivation.
     */
    public function approveDeactivatingAuthMethod(): void
    {
        if ($this->deniesManagingAuthMethods()) {
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
            EHealth::person()->approveAuthMethod($this->uuid, $this->requestId, Arr::toSnakeCase($validated));

            try {
                AuthenticationMethodModel::whereUuid($this->selectedAuthMethodUuid)->delete();

                $this->loadAuthenticationMethods(Person::whereUuid($this->uuid)->firstOrFail());

                $this->showAuthMethodModal = false;
                Session::flash('success', __('patients.messages.auth_method_deactivated'));
            } catch (Throwable $exception) {
                $this->handleDatabaseErrors($exception, 'Error when approving deactivate auth method');

                return;
            }
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when approve deactivating auth method');
        }
    }

    /**
     * Approve adding a THIRD_PERSON authentication method.
     */
    public function approveAddingNewMethod(): void
    {
        if ($this->deniesManagingAuthMethods()) {
            return;
        }

        $validated = $this->validate(['verificationCode' => ['required', 'digits:4']]);

        try {
            EHealth::person()->approveAuthMethod($this->uuid, $this->requestId, Arr::toSnakeCase($validated));

            $forCreate = [
                'type' => AuthenticationMethod::THIRD_PERSON,
                'value' => $this->confidantPersonId,
                'alias' => $this->alias
            ];

            try {
                $person = Person::whereUuid($this->uuid)->firstOrFail();
                $person->authenticationMethods()->create($forCreate);

                $this->loadAuthenticationMethods($person);

                Session::flash('success', __('patients.messages.new_auth_method_added'));
            } catch (Throwable $exception) {
                $this->handleDatabaseErrors($exception, 'Failed to update authentication method phone number');

                return;
            }

            $this->showAuthMethodModal = false;
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when approving changing auth phone number');
        }
    }

    public function chooseConfidantFromRelation(string $confidantPersonId): void
    {
        $this->confidantPersonId = $confidantPersonId;
        $this->authStep = AuthStep::ADD_ALIAS_FOR_THIRD_PERSON;
    }

    public function addAuthMethodFromRelation(string $alias): void
    {
        if ($this->deniesManagingAuthMethods()) {
            return;
        }

        try {
            Validator::make([
                'action' => AuthenticationMethodAction::INSERT->value,
                'authenticationMethod' => [
                    'type' => AuthenticationMethod::THIRD_PERSON->value,
                    'value' => $this->confidantPersonId,
                    'alias' => $alias
                ]
            ], $this->rulesForInsert())->validate();
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $this->alias = $alias;

        try {
            $response = EHealth::person()->insertAuthMethod(
                $this->uuid,
                AuthenticationMethod::THIRD_PERSON,
                value: $this->confidantPersonId,
                alias: $alias
            );
            $this->requestId = $response->validate()['id'];
            $this->authStep = AuthStep::APPROVE_ADDING_NEW_METHOD;
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when adding auth method from relation');
        }
    }

    public function resendCode(): void
    {
        if ($this->deniesManagingAuthMethods()) {
            return;
        }

        try {
            EHealth::person()->resendAuthOtp($this->uuid, $this->requestId);
            Session::flash('success', __('patients.messages.code_resent_to_phone'));
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when resending SMS');
        }
    }
}
