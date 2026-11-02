<?php

declare(strict_types=1);

namespace App\Livewire\Person\Traits;

use App\Classes\Cipher\Api\CipherRequest;
use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\Person\ConfidantPersonRelationshipRequestAction;
use App\Enums\Person\ConfidantPersonRelationshipRequestStatus;
use App\Exceptions\Cipher\CipherConnectionException;
use App\Exceptions\Cipher\CipherException;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Models\ConfidantPersonRelationshipRequest;
use App\Models\Person\Person;
use App\Models\Relations\ConfidantPerson;
use App\Models\Relations\Document;
use App\Models\Relations\Phone;
use App\Repositories\Repository;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

trait ManagesConfidantPersonRelationships
{
    public function chooseConfidantPerson(array $personData): void
    {
        // Drop whatever the previously inspected person left behind, so that a rejection or a failed
        // eligibility check never shows next to the person the user is looking at now
        $this->invalidPersonId = null;
        $this->invalidPersonReason = null;
        $this->selectedConfidantPersonId = null;

        try {
            // A relationship with an already registered patient does not need the person to be verified
            $this->invalidPersonReason = $this->form->confidantRejectionReason($personData, requiresVerification: false);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when checking the chosen person as a confidant');

            return;
        }

        if ($this->invalidPersonReason !== null) {
            $this->invalidPersonId = $personData['id'];

            return;
        }

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
            $this->confidantPerson = array_map(
                static fn (array $person): array => [...$person, 'birthDate' => convertToAppDateFormat($person['birthDate'] ?? null)],
                Arr::toCamelCase(EHealth::person()->searchForPersonByParams($validated)->validate())
            );
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when searching for person');

            return;
        }
    }

    public function syncConfidantPersons(): void
    {
        if (Auth::user()->cannot('view', ConfidantPerson::class)) {
            Session::flash('error', __('patients.policy.view_confidant'));

            return;
        }

        try {
            $relationships = EHealth::person()->getConfidantPersonRelationships($this->uuid)->validate();
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when getting confidant person relationships');

            return;
        }

        Repository::confidantPerson()->sync($relationships, $this->uuid);

        $this->reloadConfidantPersons();

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
            $validated = $this->validate(
                $this->form->rulesForCreateNewConfidantPersonRelationshipRequest(
                    $this->hasLegalCapacityDocument(),
                    $this->documentsRelationship
                ),
                ['documentsRelationship.*.activeTo.after' => __('validation.custom.person.expiration_date_in_future')]
            );
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
            $this->confidantRequestAuthenticationMethod = $response->getUrgent()['authentication_method_current'] ?? [];

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
    }

    public function approveFromRequest(string $requestId): void
    {
        $this->showAuthDrawer = true;
        $this->confidantPersonRelationshipRequestId = $requestId;
        $this->confidantRequestAuthenticationMethod = [];

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

        if (!$this->uploadDocuments()) {
            return;
        }

        try {
            $response = EHealth::person()->approveConfidantPersonRelationshipRequest(
                $this->uuid,
                $this->confidantPersonRelationshipRequestId,
                ['verification_code' => (int) $validated['verificationCode']]
            );

            $this->approvedData = $response->getData();
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when approving confidant person relationship');

            return;
        }

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

            // The signed request itself carries the action it was created with, so what to do with it is read
            // from the answer rather than kept in the component between the two steps
            $signedRequest = $response->validate();
            $startsRelationship = $signedRequest['action'] === ConfidantPersonRelationshipRequestAction::INSERT->value;

            try {
                if ($startsRelationship) {
                    $personData = collect($this->confidantPerson)->firstWhere('id', $this->selectedConfidantPersonId);
                    Repository::confidantPerson()->createFromSignedResponse(
                        $response->getData(),
                        $this->uuid,
                        (array) $personData
                    );
                } else {
                    ConfidantPerson::whereUuid($signedRequest['confidant_person_relationship']['id'])
                        ->update(['active_to' => now()]);

                    $this->showTerminateModal = true;
                }

                $this->closeConfidantDrawers();
                $this->completeConfidantPersonRelationshipRequest();
                $this->reloadConfidantPersons();

                // An ended relationship is reported by the modal that also carries what it means for the patient,
                // so only a new one needs a message of its own
                if ($startsRelationship) {
                    Session::flash('success', __('patients.messages.new_confidant_person_added'));
                }
            } catch (Exception $exception) {
                $this->handleDatabaseErrors($exception, 'Failed to create confidant person relationship');

                return;
            }
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when signing confidant person relationship');

            return;
        }
    }

    /**
     * Close every drawer of the confidant flow, so that a signed request leaves the user back on the patient
     * card no matter which of the steps they came through.
     *
     * @return void
     */
    private function closeConfidantDrawers(): void
    {
        $this->showSignatureDrawer = false;
        $this->showAuthDrawer = false;
        $this->showConfidantPersonDrawer = false;
        $this->showDeactivateConfidantPersonDrawer = false;
    }

    /**
     * Re-read the confidant persons of the patient from the database, so that the table reflects a relationship
     * that has just been created or ended.
     *
     * @return void
     */
    private function reloadConfidantPersons(): void
    {
        $person = Person::whereUuid($this->uuid)
            ->with([
                'confidantPersons.person.names',
                'confidantPersons.person.documents',
                'confidantPersons.person.phones',
                'confidantPersons.documentsRelationship'
            ])
            ->firstOrFail();

        $this->form->person['confidantPersons'] = $this->loadConfidantPersons($person);
    }

    /**
     * Mark the signed request as done and drop it from the list of requests still awaiting the user, which only
     * holds the ones in the NEW status.
     *
     * @return void
     */
    private function completeConfidantPersonRelationshipRequest(): void
    {
        ConfidantPersonRelationshipRequest::whereUuid($this->confidantPersonRelationshipRequestId)
            ->update(['status' => ConfidantPersonRelationshipRequestStatus::COMPLETED]);

        $this->confidantPersonRelationshipRequests = array_values(array_filter(
            $this->confidantPersonRelationshipRequests,
            fn (array $request): bool => $request['uuid'] !== $this->confidantPersonRelationshipRequestId
        ));
    }

    public function syncConfidantPersonRelationshipRequestsList(): void
    {
        if (Auth::user()->cannot('viewRequests', ConfidantPerson::class)) {
            Session::flash('error', __('patients.policy.view_confidant_requests'));

            return;
        }

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
            ], $this->form->rulesForDeactivateConfidantPerson($documents))->validate();
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
            $this->confidantRequestAuthenticationMethod = $response->getUrgent()['authentication_method_current'] ?? [];

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

    /**
     * Get the confidant persons of the patient with only the data that is shown of them, the identifiers masked the
     * way the registry returns them, only the last two characters stay visible, since the whole list reaches the
     * browser. The relationship documents are included only when the caller has loaded them.
     *
     * @param  Person  $person
     * @return array
     */
    protected function loadConfidantPersons(Person $person): array
    {
        return $person->confidantPersons
            ->map(static function (ConfidantPerson $relationship): array {
                $confidant = $relationship->person;

                return [
                    'uuid' => $relationship->uuid,
                    'isActive' => $relationship->isActive,
                    'activeTo' => $relationship->activeTo,
                    'documentsRelationship' => $relationship->relationLoaded('documentsRelationship')
                        ? $relationship->documentsRelationship
                            ->map(static fn (Document $document): array => [
                                'type' => $document->type,
                                'number' => Str::mask($document->number, '*', 0, -2)
                            ])
                            ->all()
                        : [],
                    'person' => [
                        'uuid' => $confidant->uuid,
                        'name' => $confidant->fullName,
                        'names' => Arr::toCamelCase($confidant->names->toArray()),
                        'gender' => $confidant->gender,
                        'taxId' => $confidant->taxId === null ? null : Str::mask($confidant->taxId, '*', 0, -2),
                        'unzr' => $confidant->unzr === null ? null : Str::mask($confidant->unzr, '*', 0, -2),
                        'documents' => $confidant->documents
                            ->map(static fn (Document $document): array => [
                                'type' => $document->type,
                                'number' => Str::mask($document->number, '*', 0, -2)
                            ])
                            ->all(),
                        'phones' => $confidant->phones
                            ->map(static fn (Phone $phone): array => [
                                'type' => $phone->type,
                                'number' => Str::mask($phone->number, '*', 0, -2)
                            ])
                            ->all()
                    ]
                ];
            })
            ->all();
    }

    private function convertRequestStatusesToEnums(array $requests): array
    {
        return array_map(static fn (array $request) => array_merge($request, [
            'status' => ConfidantPersonRelationshipRequestStatus::from($request['status'])
        ]), $requests);
    }
}
