<?php

declare(strict_types=1);

namespace App\Livewire\Person\Traits;

use App\Classes\Cipher\Api\CipherRequest;
use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\Person\ConfidantPersonRelationshipRequestStatus;
use App\Exceptions\Cipher\CipherConnectionException;
use App\Exceptions\Cipher\CipherException;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Models\ConfidantPersonRelationshipRequest;
use App\Models\Person\Person;
use App\Models\Relations\ConfidantPerson;
use App\Repositories\Repository;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

trait ManagesConfidantPersonRelationships
{
    public const string AUTH_DRAWER_MODE_CREATE = 'create';
    public const string AUTH_DRAWER_MODE_DEACTIVATE = 'deactivate';

    public function chooseConfidantPerson(array $personData): void
    {
        $birthDate = CarbonImmutable::parse($personData['birthDate']);

        if ($birthDate->age < 18) {
            $this->invalidPersonId = $personData['id'];

            return;
        }

        $this->invalidPersonId = null;
        $this->selectedConfidantPersonId = $personData['id'];

        $person = Person::whereUuid($personData['id'])->with(['documents', 'phones'])->first();
        $personData['documents'] = $person?->documents->toArray() ?? [];
        $personData['phones'] = $person?->phones->toArray() ?? [];
        $this->newConfidantPerson = $personData;
    }

    public function removeConfidantPerson(): void
    {
        $this->selectedConfidantPersonId = null;
        $this->newConfidantPerson = ['documentsRelationship' => []];
    }

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

    public function syncConfidantPersons(): void
    {
        try {
            $response = EHealth::person()->getConfidantPersonRelationships($this->uuid);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when getting auth methods');

            return;
        }

        $confidantPersonsData = collect($response->getData())->map(function ($relationship) {
            $person = $relationship['confidant_person'];
            $person['documents'] = $relationship['confidant_person']['documents_person'];

            return [
                'person' => $person,
                'documentsRelationship' => $relationship['documents_relationship'],
                'activeTo' => convertToAppDateFormat($relationship['active_to'])
            ];
        })->toArray();

        $this->form->person['confidantPersons'] = $confidantPersonsData;

        Repository::confidantPerson()->sync($response->getData(), $this->uuid);

        Session::flash('success', __('patients.messages.confidant_persons_synced'));
    }

    public function createNewConfidantPersonRelationshipRequest(): void
    {
        if (Auth::user()->cannot('create', ConfidantPerson::class)) {
            Session::flash('error', __('patients.policy.create_confidant'));

            return;
        }

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

            $validatedData = $response->validate();
            $validatedData['status'] = ConfidantPersonRelationshipRequestStatus::from($validatedData['status']);
            $this->confidantPersonRelationshipRequests = array_merge(
                [$validatedData],
                $this->confidantPersonRelationshipRequests
            );

            $this->confidantPersonRelationshipRequestId = $validatedData['uuid'];
            $this->uploadedDocuments = $response->getUrgent()['documents'];

            try {
                $dataForCreate = $response->validate();
                $dataForCreate['person_id'] = Person::whereUuid($this->uuid)->value('id');
                $dataForCreate['documents'] = $response->getUrgent()['documents'];

                ConfidantPersonRelationshipRequest::create($dataForCreate);
            } catch (Throwable $exception) {
                $this->handleDatabaseErrors($exception, 'Failed to create confidant person relationship request');

                return;
            }
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when creating confidant person relationship');

            return;
        }

        $this->authDrawerMode = self::AUTH_DRAWER_MODE_CREATE;
        $this->showConfidantPersonDrawer = false;
        $this->showAuthDrawer = true;
    }

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
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when resending SMS');

            return;
        }

        $this->authDrawerMode = self::AUTH_DRAWER_MODE_CREATE;
    }

    public function approveFromRequest(string $requestId): void
    {
        $this->authDrawerMode = self::AUTH_DRAWER_MODE_CREATE;
        $this->showAuthDrawer = true;
        $this->confidantPersonRelationshipRequestId = $requestId;

        $this->uploadedDocuments = ConfidantPersonRelationshipRequest::whereUuid($requestId)
            ->value('documents') ?? [];
    }

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
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when approving confidant person relationship');

            return;
        }

        $this->authDrawerMode = null;
        $this->showSignatureDrawer = true;
    }

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
        } catch (CipherException|CipherConnectionException $exception) {
            $exception->handle('Error when signing data with Cipher');

            return;
        }

        try {
            $response = EHealth::person()->signConfidantPersonRelationshipRequest(
                $this->uuid,
                $this->confidantPersonRelationshipRequestId,
                ['signed_content' => $signedContent->getBase64Data()]
            );

            try {
                if ($this->authDrawerMode === self::AUTH_DRAWER_MODE_CREATE) {
                    $personData = collect($this->confidantPerson)->firstWhere('id', $this->selectedConfidantPersonId);
                    Repository::confidantPerson()->createFromSignedResponse(
                        $response->getData(),
                        $this->uuid,
                        (array) $personData
                    );

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
                $this->handleDatabaseErrors($exception, 'Failed to create confidant person relationship');

                return;
            }
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when signing confidant person relationship');

            return;
        }
    }

    public function syncConfidantPersonRelationshipRequestsList(): void
    {
        try {
            $response = EHealth::person()->getConfidantPersonRelationshipRequestsList($this->uuid);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when getting confidant person relationship requests list');

            return;
        }

        try {
            $person = Person::whereUuid($this->uuid)->firstOrFail();
            $data = $response->validate();

            Repository::confidantPersonRelationshipRequestRepository()->sync($person, $data);

            $this->confidantPersonRelationshipRequests = $this->loadConfidantPersonRelationshipRequests($person);

            Session::flash('success', __('patients.messages.confidant_requests_list_updated'));
        } catch (Exception $exception) {
            Log::error('Failed to sync confidant person relationship requests', [
                'person_uuid' => $this->uuid,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString()
            ]);

            Session::flash('error', __('patients.messages.confidant_requests_list_update_failed'));
        }
    }

    public function deactivateConfidantPerson(string $confidantPersonRelationUuid, array $documents): void
    {
        if (Auth::user()->cannot('create', ConfidantPerson::class)) {
            Session::flash('error', __('patients.policy.sign_confidant'));

            return;
        }

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
            $mainPerson = Person::whereUuid($this->uuid)->firstOrFail();

            $authResult = Repository::confidantPerson()->findAuthMethodForDeactivation(
                $mainPerson,
                $confidantPersonRelationUuid
            );

            if ($authResult['error']) {
                Session::flash('error', $authResult['error']);

                return;
            }

            $response = EHealth::person()->deactivateConfidantRelationship(
                $this->uuid,
                $validated['confidantPersonRelationUuid'],
                Arr::toSnakeCase($validated['documents']),
                $authResult['auth_method_uuid']
            );

            $this->confidantPersonRelationshipRequestId = $response->getData()['id'];
            $this->uploadedDocuments = $response->getUrgent()['documents'];

            try {
                $dataForCreate = $response->validate();
                $dataForCreate['person_id'] = Person::whereUuid($this->uuid)->value('id');
                $dataForCreate['documents'] = $response->getUrgent()['documents'];

                ConfidantPersonRelationshipRequest::create($dataForCreate);
            } catch (Throwable $exception) {
                $this->handleDatabaseErrors($exception, 'Failed to create confidant person relationship request');

                return;
            }
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when deactivating auth method');

            return;
        }

        $this->showDeactivateConfidantPersonDrawer = false;
        $this->authDrawerMode = self::AUTH_DRAWER_MODE_DEACTIVATE;
        $this->showAuthDrawer = true;
    }

    protected function loadConfidantPersonRelationshipRequests(Person $person): array
    {
        $requests = $person->confidantPersonRelationshipRequests()
            ->whereStatus(ConfidantPersonRelationshipRequestStatus::NEW)
            ->orderByDesc('created_at')
            ->get()
            ->toArray();

        return $this->convertRequestStatusesToEnums($requests);
    }

    private function convertRequestStatusesToEnums(array $requests): array
    {
        return array_map(static fn (array $request) => array_merge($request, [
            'status' => ConfidantPersonRelationshipRequestStatus::from($request['status'])
        ]), $requests);
    }
}
