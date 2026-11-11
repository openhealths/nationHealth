<?php

declare(strict_types=1);

namespace App\Traits;

use App\Classes\Cipher\Api\CipherRequest;
use App\Classes\eHealth\EHealth;
use App\Exceptions\Cipher\CipherConnectionException;
use App\Exceptions\Cipher\CipherException;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Jobs\RemoteEHealthLinksProcessing;
use App\Livewire\EncounterPackage\Forms\EncounterCancellationForm;
use App\Models\MedicalEvents\Sql\Device;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Repositories\MedicalEvents\Repository;
use App\Services\MedicalEvents\Fhir;
use App\Services\MedicalEvents\FhirResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;
use Throwable;

/**
 * Marking an encounter and everything recorded alongside it as entered in error. The reason and the explanation
 * are collected by the cancellation form, while the signing credentials are taken from the form the page signs
 * with, since that is the one the signature modal is bound to.
 */
trait HandlesEncounterCancellation
{
    // The signature modal takes the key container as an upload, so every page that cancels also handles files
    use WithFileUploads;

    public bool $showCancellationModal = false;

    public bool $showSignatureModal = false;

    public ?string $actionType = null;

    /**
     * Package section and eHealth ID of the single record being marked as entered in error, set when the
     * cancellation was opened for one record rather than for the encounter as a whole.
     *
     * @var string
     */
    #[Locked]
    public string $cancellingRecordSection = '';

    #[Locked]
    public string $cancellingRecordId = '';

    /** Submission IDs seen by this page remain available when the worker completes between polls. */
    #[Locked]
    public array $cancellationSubmissionIds = [];

    /**
     * The form holding the reason and the explanation of the cancellation.
     *
     * @return EncounterCancellationForm
     */
    abstract protected function encounterCancellationForm(): EncounterCancellationForm;

    /**
     * Refresh the page after eHealth has confirmed the cancellation.
     *
     * @return void
     */
    abstract protected function afterEncounterCancelled(): void;

    /**
     * Records picked for cancellation, keyed by package section. While it stays empty the whole package,
     * with the encounter itself, is the one being marked as entered in error.
     *
     * @return array
     */
    protected function selectedCancellationRecords(): array
    {
        return $this->cancellingRecordId === ''
            ? []
            : [$this->cancellingRecordSection => [$this->cancellingRecordId]];
    }

    /**
     * Ask for the reason a single record of an encounter package is being marked as entered in error. Most of
     * these records have no method of their own in eHealth, so they travel as part of the package of the
     * encounter they were recorded in, which is the one the cancellation is opened for.
     *
     * @param  string  $section  Package section the record belongs to, e.g. `observations`
     * @param  string  $recordId  eHealth ID of the record
     * @return void
     */
    public function openRecordCancellation(string $section, string $recordId): void
    {
        $encounterId = Repository::encounter()->encounterIdOfRecord($section, $recordId, $this->patient());

        if ($encounterId === null) {
            Session::flash('error', __('encounters.messages.record_not_found_in_db'));

            return;
        }

        $this->openEncounterCancellation($encounterId);

        // The encounter may turn out to be missing or out of reach, and then there is no cancellation to scope
        if (!$this->showCancellationModal) {
            return;
        }

        $this->cancellingRecordSection = $section;
        $this->cancellingRecordId = $recordId;
    }

    /**
     * Ask for the reason the encounter and everything recorded alongside it are being marked as entered in error.
     *
     * @param  string  $id  eHealth ID of the encounter
     * @return void
     */
    public function openEncounterCancellation(string $id): void
    {
        $encounter = $this->findEncounter($id);

        if ($encounter === null) {
            return;
        }

        if (Auth::user()->cannot('cancel', $encounter)) {
            Session::flash('error', __('encounters.policy.cancel'));

            return;
        }

        if (Repository::encounter()->pendingCancellation($encounter) !== null) {
            Session::flash('warning', __('encounters.messages.cancel_pending'));

            return;
        }

        $this->resetCancellationState();

        // Opening the cancellation of an encounter drops the scope of whatever single record was opened before
        $this->cancellingRecordSection = '';
        $this->cancellingRecordId = '';

        $this->encounterCancellationForm()->cancellingId = $encounter->uuid;
        $this->showCancellationModal = true;
    }

    /**
     * Drop the reason collected so far and leave the cancellation behind.
     *
     * @return void
     */
    public function closeEncounterCancellationModal(): void
    {
        $this->resetCancellationState();
    }

    /**
     * Hand the collected reason over to the signing step.
     *
     * @return void
     */
    public function proceedToSignature(): void
    {
        $encounter = $this->findEncounter($this->encounterCancellationForm()->cancellingId);

        if ($encounter === null) {
            return;
        }

        if (Auth::user()->cannot('cancel', $encounter)) {
            Session::flash('error', __('encounters.policy.cancel'));

            return;
        }

        try {
            $this->encounterCancellationForm()->validate($this->encounterCancellationForm()->cancellationRules());
        } catch (ValidationException $exception) {
            $this->showCancellationModal = true;
            $this->showSignatureModal = false;

            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $this->showCancellationModal = false;
        $this->actionType = 'cancel_encounter';
        $this->showSignatureModal = true;
    }

    /**
     * Sign the whole encounter package and send it to eHealth as entered in error.
     *
     * @return void
     */
    public function cancelSelectedEncounter(): void
    {
        if ($this->actionType !== 'cancel_encounter') {
            return;
        }

        try {
            $validated = [
                ...$this->encounterCancellationForm()->validate(
                    $this->encounterCancellationForm()->cancellationRules()
                ),
                ...$this->form->validate($this->form->signingRules())
            ];
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $encounter = $this->findEncounter($this->encounterCancellationForm()->cancellingId);

        if ($encounter === null) {
            return;
        }

        if (Auth::user()->cannot('cancel', $encounter)) {
            Session::flash('error', __('encounters.policy.cancel'));

            return;
        }

        if (Repository::encounter()->pendingCancellation($encounter) !== null) {
            Session::flash('warning', __('encounters.messages.cancel_pending'));

            return;
        }

        try {
            Repository::encounter()->synchronizeCancellationPackage($encounter, $this->patient());
            $encounter->refresh();
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while fetching the complete encounter package', __('encounters.messages.cancel_package_prepare_error'));

            return;
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while synchronizing the cancellation package', __('encounters.messages.cancel_package_prepare_error'));

            return;
        }

        // Recheck the current remote performer and status before signing a cancellation.
        if (Auth::user()->cannot('cancel', $encounter)) {
            Session::flash('error', __('encounters.policy.cancel'));

            return;
        }

        $selectedRecords = Repository::encounter()->withWholeDeviceGroup(
            $encounter->uuid,
            $this->selectedCancellationRecords()
        );

        $isDeviceGroupPicked = !empty($selectedRecords['devices'])
            || !empty($selectedRecords['deviceAssociations'])
            || !empty($selectedRecords['detectedIssues']);

        if ($isDeviceGroupPicked && Auth::user()->cannot('cancel', Device::class)) {
            Session::flash('error', __('devices.policy.cancel'));

            return;
        }

        try {
            $package = $selectedRecords === []
                ? $this->buildCancellationPackage(
                    $encounter,
                    $validated['cancellationReason'],
                    $validated['explanatoryLetter']
                )
                : $this->buildRecordCancellationPackage(
                    $encounter,
                    $selectedRecords,
                    $validated['cancellationReason'],
                    $validated['explanatoryLetter']
                );
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors(
                $exception,
                'Error while building encounter cancellation package',
                __('encounters.messages.cancel_package_prepare_error')
            );

            return;
        }

        try {
            $signedContent = new CipherRequest()->signData(
                $package,
                $validated['knedp'],
                $validated['keyContainerUpload'],
                $validated['password'],
                Auth::user()->party->taxId
            );
        } catch (CipherException|CipherConnectionException $exception) {
            $exception->handle(
                'Error while signing encounter cancellation package',
                __('encounters.messages.cancel_package_sign_error')
            );

            return;
        } finally {
            $this->form->resetSigningFields();
        }

        try {
            $submission = Repository::encounter()->startCancellation($encounter, [
            ...$package,
            'operation' => 'cancelEncounter',
            'selectedRecords' => $selectedRecords,
            'cancellationReasonConcept' => FhirResource::make()
                ->coding('eHealth/cancellation_reasons', $validated['cancellationReason'])
                ->toCodeableConcept(data_get($this->dictionaries, 'eHealth/cancellation_reasons.'.$validated['cancellationReason'], '')),
            'explanatoryLetter' => $validated['explanatoryLetter'],
            ]);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while saving encounter cancellation submission', __('encounters.messages.cancel_package_save_error'));

            return;
        }
        if ($submission === null) {
            Session::flash('warning', __('encounters.messages.cancel_pending'));

            return;
        }

        $this->cancellationSubmissionIds[] = $submission->id;

        try {
            $response = EHealth::encounter()->cancel($this->patient()->uuid, [
                'signed_data' => $signedContent->getBase64Data(),
                'signed_data_encoding' => 'base64'
            ]);
            $receipt = $response->validate();
            $job = collect($receipt['links'] ?? [])->first(
                static fn (array $link): bool => str_contains(strtolower($link['href']), '/jobs/')
            );
            if ($job === null) {
                // An accepted request without a job link remains guarded until it can be reconciled.
                $submission->job()->update(['response_data' => $receipt]);
                Session::flash('warning', __('encounters.messages.cancel_unconfirmed'));

                return;
            }
            $receipt['links'] = [$job];
            RemoteEHealthLinksProcessing::track($submission, $receipt);
        } catch (EHealthException|EHealthConnectionException $exception) {
            if ($exception instanceof EHealthValidationException
                || ($exception instanceof EHealthResponseException && $exception->getCode() >= 400
                    && $exception->getCode() < 500 && $exception->getCode() !== 408)) {
                RemoteEHealthLinksProcessing::markSubmissionFailed($submission);
            }
            $exception->handle(
                'Error while sending encounter cancellation package',
                __('encounters.messages.cancel_package_request_error')
            );

            return;
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors(
                $exception,
                'Encounter cancellation outcome is unconfirmed',
                __('encounters.messages.cancel_unconfirmed')
            );

            return;
        }

        $this->resetCancellationState();

        Session::flash('info', __('encounters.messages.cancel_pending'));

        unset($this->pendingEncounterCancellations);
    }

    #[Computed]
    public function pendingEncounterCancellations(): Collection
    {
        $pending = Repository::encounter()->pendingPatientCancellations($this->patient());
        $submissions = $pending->merge(Repository::encounter()->patientCancellationsByIds($this->patient(), $this->cancellationSubmissionIds))
            ->unique('id')->filter(static fn ($submission): bool => Auth::user()->can('viewCancellation', $submission->linkable));

        // Remember jobs rendered after reopening the page, including completion by a worker before the next poll.
        $this->cancellationSubmissionIds = $submissions->pluck('id')->values()->all();

        return $submissions;
    }

    /** Poll durable jobs after a refresh; a receipt alone never changes clinical statuses. */
    public function pollEncounterCancellation(): void
    {
        foreach ($this->pendingEncounterCancellations as $submission) {
            $this->cancellationSubmissionIds = array_values(array_unique([...$this->cancellationSubmissionIds, $submission->id]));
            try {
                if (in_array($submission->status, ['PENDING', 'PROCESSING'], true)) {
                    if (is_string($submission->href) && $submission->href !== '') {
                        $result = EHealth::job()->getDetailsByHref($submission->href)->validate();
                        Repository::encounter()->finishCancellation($submission, $result);
                    } else {
                        Repository::encounter()->reconcileCancellation($submission, $this->patient());
                    }
                }
                $submission->refresh();
                if (in_array($submission->status, ['PROCESSED', 'FAILED'], true)) {
                    $succeeded = $submission->status === 'PROCESSED';
                    Session::flash($succeeded ? 'success' : 'error', __($succeeded
                        ? 'encounters.messages.cancel_confirmed' : 'encounters.messages.cancel_package_request_error'));
                    $this->cancellationSubmissionIds = array_values(array_diff($this->cancellationSubmissionIds, [$submission->id]));
                    if ($succeeded) {
                        // Restore the submitted selection before refreshing pages which distinguish whole and selected cancellation.
                        $selected = $submission->job->request_data['selectedRecords'];
                        if (property_exists($this, 'selectedRecords')) {
                            $this->selectedRecords = $selected;
                        } else {
                            $this->cancellingRecordSection = array_key_first($selected) ?? '';
                            $this->cancellingRecordId = $selected[$this->cancellingRecordSection][0] ?? '';
                        }
                        $this->afterEncounterCancelled();
                    }
                }
            } catch (EHealthException|EHealthConnectionException $exception) {
                $exception->handle('Error while polling encounter cancellation');
            } catch (ValidationException) {
                Session::flash('error', __('encounters.messages.cancel_unconfirmed'));
            } catch (Throwable $exception) {
                $this->handleDatabaseErrors($exception, 'Cancellation remains unconfirmed after remote reread', __('encounters.messages.cancel_unconfirmed'));
            }
        }
        unset($this->pendingEncounterCancellations);
    }

    /**
     * Get the locally stored encounter by its eHealth ID.
     *
     * @param  string  $id  eHealth ID of the encounter
     * @return Encounter|null
     */
    private function findEncounter(string $id): ?Encounter
    {
        $encounter = Encounter::forPatient($this->patient())->whereUuid($id)->first();

        if ($encounter === null) {
            Session::flash('error', __('encounters.messages.not_found_in_db'));
        }

        return $encounter;
    }

    /**
     * Rebuild the encounter package out of the local database and turn it into the one that cancels every
     * record in it. Going back through the builder that created the package is what keeps the signed content
     * equal to the content eHealth stored, which it compares the signature against.
     *
     * @param  Encounter  $encounter
     * @param  string  $cancellationReason
     * @param  string  $explanatoryLetter
     * @return array
     */
    private function buildCancellationPackage(
        Encounter $encounter,
        string $cancellationReason,
        string $explanatoryLetter
    ): array {
        return Fhir::encounter()->toCancellationPackage(
            $this->rebuildEncounterPackage($encounter),
            $cancellationReason,
            $explanatoryLetter,
            data_get($this->dictionaries, 'eHealth/cancellation_reasons.' . $cancellationReason)
        );
    }

    /**
     * Rebuild the encounter package and turn it into the one that marks the given records of it as entered in
     * error, leaving the encounter itself and every record left out as they are.
     *
     * @param  Encounter  $encounter
     * @param  array  $recordIds  eHealth IDs of the records to mark, keyed by package section
     * @param  string  $cancellationReason
     * @param  string  $explanatoryLetter
     * @return array
     */
    private function buildRecordCancellationPackage(
        Encounter $encounter,
        array $recordIds,
        string $cancellationReason,
        string $explanatoryLetter
    ): array {
        return Fhir::encounter()->toRecordCancellationPackage(
            $this->rebuildEncounterPackage($encounter),
            $encounter->status->value,
            $recordIds,
            $cancellationReason,
            $explanatoryLetter,
            data_get($this->dictionaries, 'eHealth/cancellation_reasons.' . $cancellationReason)
        );
    }

    /**
     * Rebuild the package out of the local database. Going back through the builder that created it is what
     * keeps the signed content equal to the content eHealth stored, which it compares the signature against.
     *
     * @param  Encounter  $encounter
     * @return array
     */
    private function rebuildEncounterPackage(Encounter $encounter): array
    {
        $storedEncounter = Encounter::withRelationships()->whereId($encounter->id)->firstOrFail()->toArray();

        // The employee comes from the record, not from whoever is cancelling, so that the performer stays as signed
        $uuids = [
            'encounter' => $encounter->uuid,
            'visit' => data_get($storedEncounter, 'visit.identifier.value'),
            'employee' => data_get($storedEncounter, 'performer.identifier.value'),
            'episode' => data_get($storedEncounter, 'episode.identifier.value')
        ];

        return Fhir::encounterPackage()->toFhir(Fhir::encounterPackageLoader()->load($storedEncounter), $uuids);
    }

    /**
     * Close both steps of the cancellation and forget everything collected along the way.
     *
     * @return void
     */
    private function resetCancellationState(): void
    {
        $this->showCancellationModal = false;
        $this->showSignatureModal = false;
        $this->actionType = null;
        $this->encounterCancellationForm()->resetCancellationFields();
        $this->form->resetSigningFields();

        $this->resetErrorBag();
        $this->resetValidation();
    }
}
