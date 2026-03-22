<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\Person\AuthenticationMethodAction;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Livewire\Person\Records\Forms\PersonForm as Form;
use App\Models\Person\Person;
use App\Repositories\Repository;
use App\Traits\FormTrait;
use Exception;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;

class PatientData extends BasePatientComponent
{
    use FormTrait;

    public Form $form;

    public string $firstName;

    public string $lastName;

    public array $phones = [];

    public array $confidantPersonRelationships;

    /**
     * List of patient authentication methods.
     *
     * @var array
     */
    public array $authenticationMethods;

    /**
     * ID that returns after createAuthMethod request, need for resendSMS request.
     *
     * @var string
     */
    protected string $authMethodId;

    /**
     * ID that returns after createAuthMethod request, need for resendSMS request.
     *
     * @var string
     */
    protected string $authMethodRequestId;

    protected function initializeComponent(): void
    {
        $patient = Person::with('phones')->whereId($this->id)->firstOrFail();

        $this->firstName = $patient->firstName;
        $this->lastName = $patient->lastName;
        $this->phones = $patient->phones->toArray();
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

            try {
                Repository::person()->updateVerificationStatusById(
                    $this->uuid,
                    $response->getData()['verification_status']
                );

                $this->verificationStatus = $response->getData()['verification_status'];
            } catch (Exception $exception) {
                $this->logDatabaseErrors($exception, 'Error when updating person verification status');
                Session::flash('error', __('messages.database_error'));

                return;
            }
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when getting person verification details');

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
            $response = EHealth::person()->getConfidantPersonRelationships($this->uuid, ['is_expired' => false]);

            $this->confidantPersonRelationships = $response->getData();
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when getting confidant person relationships');

            return;
        }
    }

    /**
     * Get patient authentication methods.
     *
     * @return void
     */
    public function getAuthenticationMethods(): void
    {
        try {
            $response = EHealth::person()->getAuthMethods($this->uuid);

            $this->authenticationMethods = Arr::toCamelCase($response->getData());
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when getting auth methods');

            return;
        }
    }

    /**
     * Deactivate authentication method.
     *
     * @param  array  $data
     * @return void
     */
    public function deactivateAuthMethod(array $data): void
    {
        $this->form->action = AuthenticationMethodAction::DEACTIVATE->value;
        $this->form->authenticationMethod = $data;

        try {
            $validated = $this->form->validate($this->form->rulesForDeactivate());
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());

            return;
        }

        try {
            EHealth::person()->createAuthMethod($this->uuid, Arr::toSnakeCase($validated));
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when deactivating auth method request');

            return;
        }
    }

    /**
     * Create an authentication method request.
     *
     * @param  array  $data
     * @return void
     */
    public function createAuthMethod(array $data): void
    {
        $this->form->action = AuthenticationMethodAction::INSERT->value;
        $this->form->authenticationMethod = $data;

        try {
            $validated = $this->form->validate($this->form->rulesForInsert());
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());

            return;
        }

        try {
            $response = EHealth::person()->createAuthMethod($this->uuid, Arr::toSnakeCase(removeEmptyKeys($validated)));

            if ($response->successful()) {
                $this->authMethodId = $response->getData()['id'];
            }
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when creating auth method request');

            return;
        }
    }

    /**
     * Re-send SMS.
     *
     * @return void
     */
    public function resendSms(): void
    {
        try {
            $response = EHealth::person()->resendAuthOtp($this->uuid, $this->authMethodId);

            if ($response->getData()['status'] === 'new') {
                Session::flash('success', __('patients.messages.sms_sent_successfully'));
            }
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when resending sms to person');

            return;
        }
    }

    public function render(): View
    {
        return view('livewire.person.records.patient-data');
    }
}
