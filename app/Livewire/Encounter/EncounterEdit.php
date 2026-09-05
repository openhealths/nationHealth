<?php

declare(strict_types=1);

namespace App\Livewire\Encounter;

use App\Classes\Cipher\Api\CipherRequest;
use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\Person\EncounterStatus;
use App\Livewire\Encounter\Forms\EncounterCancellationForm;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\Repository;
use App\Services\MedicalEvents\Fhir;
use App\Traits\HandlesEncounterCancellation;
use App\Exceptions\Cipher\CipherConnectionException;
use App\Exceptions\Cipher\CipherException;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use App\Livewire\Encounter\Concerns\ManagesEncounterEPrescription;
use App\Livewire\Encounter\Concerns\ManagesEncounterReferrals;
use App\Livewire\Encounter\Concerns\ResolvesEncounterStandaloneContext;
use Livewire\Attributes\Locked;
use Throwable;

class EncounterEdit extends EncounterComponent
{
    use HandlesEncounterCancellation;
    use ResolvesEncounterStandaloneContext;
    use ManagesEncounterEPrescription;
    use ManagesEncounterReferrals;

    #[Locked]
    public int $encounterId;

    #[Locked]
    public string $encounterUuid;

    #[Locked]
    public bool $isReadonly;

    /**
     * Whether the encounter may be marked as entered in error by the current user.
     *
     * @var bool
     */
    #[Locked]
    public bool $canBeCancelled;

    public EncounterCancellationForm $cancellationForm;

    public function mount(LegalEntity $legalEntity, int $encounterId, ?Person $person = null, ?Preperson $preperson = null): void
    {
        if ($preperson !== null) {
            $this->prepersonId = $preperson->id;
        } else {
            $this->personId = $person->id;
        }

        $this->initializeComponent();
        $this->encounterId = $encounterId;

        $encounterModel = Encounter::withRelationships()->whereId($encounterId)->firstOrFail();
        $this->encounterUuid = $encounterModel->uuid;
        $this->canBeCancelled = Auth::user()->can('cancel', $encounterModel);
        $this->cancelledRecords = Repository::encounter()->cancelledRecordIds($encounterModel->uuid);

        $encounter = $encounterModel->toArray();
        $this->isReadonly = $encounter['status'] !== EncounterStatus::DRAFT->value;

        $package = Fhir::encounterPackageLoader()->load($encounter);

        $reactionIds = collect($package['observations'])->pluck('reactionOn')->filter()->unique();
        $packageImmunizationIds = collect($package['immunizations'])->pluck('uuid')->filter()->unique();

        if ($reactionIds->diff($packageImmunizationIds)->isNotEmpty()) {
            $this->searchReactionImmunizations();
        }

        $this->form->encounter = $package['encounter'];
        $this->form->conditions = $package['conditions'];
        $this->form->immunizations = $package['immunizations'];
        $this->form->diagnosticReports = $package['diagnosticReports'];
        $this->form->observations = $package['observations'];
        $this->form->procedures = $package['procedures'];
        $this->deviceForm->devices = $package['devices'];
        $this->detectedIssueForm->detectedIssues = $package['detectedIssues'];
        $this->deviceAssociationForm->deviceAssociations = $package['deviceAssociations'];
        $this->clinicalImpressionForm->clinicalImpressions = $package['clinicalImpressions'];

        if ($this->isReadonly) {
            $basedOnIds = collect($package['detectedIssues'])
                ->pluck('basedOnId')
                ->filter()
                ->unique()
                ->values()
                ->toArray();

            $this->previousDetectedIssues = Repository::detectedIssue()
                ->getByUuidsForSelect($this->patient(), $basedOnIds);
        }

        $this->episodeType = 'existing';
        $this->form->episode = array_merge(
            $this->form->episode,
            ['id' => data_get($encounter, 'episode.identifier.value', '')]
        );

        $this->loadIcd10Descriptions(
            collect([...$package['procedures'], ...$package['clinicalImpressions']])
                ->flatMap(static fn (array $record) => array_merge(
                    $record['reasonReferences'] ?? [],
                    $record['complicationDetails'] ?? [],
                    $record['problems'] ?? [],
                    $record['findings'] ?? []
                ))
                ->concat(
                    collect($package['diagnosticReports'])
                        ->filter(static fn (array $report): bool => !empty($report['conclusionCode']))
                        ->map(static fn (array $report): array => [
                            'codeSystem' => 'eHealth/ICD10_AM/condition_codes',
                            'codeCode' => $report['conclusionCode'],
                        ])
                )
                ->toArray()
        );
    }

    /**
     * Validate and update data.
     *
     * @return array|null
     */
    public function save(): ?array
    {
        if ($this->isReadonly) {
            return null;
        }

        try {
            $this->syncEncounterParticipants();
            // Validating from the component runs every form of the package and collects their errors in one pass
            $validated = $this->validate();
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());
            $this->dispatch('scroll-to-error');

            return null;
        }

        $encounter = Encounter::withRelationships()->whereId($this->encounterId)->firstOrFail()->toArray();
        $uuids = [
            'encounter' => $encounter['uuid'],
            'visit' => data_get($encounter, 'visit.identifier.value'),
            'employee' => Auth::user()->getEncounterWriterEmployee($validated['encounter']['classCode'])->uuid,
            'episode' => $validated['episode']['id']
        ];

        $fhir = Fhir::encounterPackage()->toFhir($validated, $uuids);
        $fhirEncounter = $fhir['encounter'];
        $fhirEncounter['status'] = EncounterStatus::DRAFT->value;
        $fhirConditions = $fhir['conditions'];
        $fhirImmunizations = $fhir['immunizations'];
        $fhirDiagnosticReports = $fhir['diagnosticReports'];
        $fhirObservations = $fhir['observations'];
        $fhirProcedures = $fhir['procedures'];
        $fhirDevices = $fhir['devices'];
        $fhirDeviceAssociations = $fhir['deviceAssociations'];
        $fhirDetectedIssues = $fhir['detectedIssues'];
        $fhirClinicalImpressions = $fhir['clinicalImpressions'];

        try {
            Repository::encounter()->sync($this->patient(), [$this->fhirToSync($fhirEncounter)]);
            Repository::condition()->sync($this->patient(), array_map($this->fhirToSync(...), $fhirConditions));
            Repository::immunization()->sync($this->patient(), array_map($this->fhirToSync(...), $fhirImmunizations));
            Repository::diagnosticReport()->sync(
                $this->patient(),
                array_map($this->fhirToSync(...), $fhirDiagnosticReports)
            );
            Repository::observation()->sync(
                $this->patient(),
                array_map($this->fhirToSync(...), $fhirObservations),
                $uuids['encounter']
            );
            Repository::procedure()->sync($this->patient(), array_map($this->fhirToSync(...), $fhirProcedures));
            Repository::device()->sync($this->patient(), array_map($this->fhirToSync(...), $fhirDevices));
            Repository::deviceAssociation()->sync(
                $this->patient(),
                array_map($this->fhirToSync(...), $fhirDeviceAssociations)
            );
            Repository::detectedIssue()->sync(
                $this->patient(),
                array_map($this->fhirToSync(...), $fhirDetectedIssues)
            );
            Repository::clinicalImpression()->sync(
                $this->patient(),
                array_map($this->fhirToSync(...), $fhirClinicalImpressions)
            );
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to sync encounter package data');

            return null;
        }

        Session::flash('success', __('encounters.messages.updated'));

        // Sections with no records stay out of the package, the same way the create flow signs it
        return array_filter([
            'encounter' => $fhirEncounter,
            'conditions' => $fhirConditions,
            'immunizations' => $fhirImmunizations,
            'diagnosticReports' => $fhirDiagnosticReports,
            'observations' => $fhirObservations,
            'procedures' => $fhirProcedures,
            'devices' => $fhirDevices,
            'deviceAssociations' => $fhirDeviceAssociations,
            'detectedIssues' => $fhirDetectedIssues,
            'clinicalImpressions' => $fhirClinicalImpressions
        ]);
    }

    /**
     * Submit encrypted data about person encounter.
     *
     * @return void
     */
    public function sign(): void
    {
        if ($this->actionType === 'sign_eprescription') {
            $this->signEncounterEPrescription();

            return;
        }

        if ($this->actionType === 'sign_referral') {
            $this->signEncounterReferral();

            return;
        }

        if ($this->isReadonly) {
            return;
        }

        if (Auth::user()->cannot('create', Encounter::class)) {
            Session::flash('error', __('encounters.policy.create'));
            $this->showSignatureModal = false;

            return;
        }

        try {
            $validated = $this->form->validate($this->form->signingRules());
        } catch (ValidationException $exception) {
            // The KEP errors render inside the signature modal, so it stays open to show them
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $formattedData = $this->save();
        if (is_null($formattedData)) {
            return;
        }

        $formattedData['encounter']['status'] = EncounterStatus::FINISHED->value;
        $formattedData = Arr::toSnakeCase($formattedData);

        // Remove display_value from incoming_referral before sending to eHealth (schema rejects it)
        unset($formattedData['encounter']['incoming_referral']['display_value']);

        try {
            $this->validateObservationPerformers($formattedData);
            $this->validateProcedurePerformers($formattedData);
            $this->validateDiagnosticReportPerformers($formattedData);
            $this->validateEncounterPerformer($formattedData);
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());

            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        try {
            $signedContent = new CipherRequest()->signData(
                $formattedData,
                $validated['knedp'],
                $validated['keyContainerUpload'],
                $validated['password'],
                Auth::user()->party->taxId
            );
        } catch (CipherException|CipherConnectionException $exception) {
            $exception->handle('Error when signing data with Cipher');
            $this->showSignatureModal = false;

            return;
        }

        try {
            $resp = EHealth::encounter()->submit($this->patientUuid, [
                'visit' => [
                    'id' => data_get($formattedData, 'encounter.visit.identifier.value'),
                    'period' => data_get($formattedData, 'encounter.period')
                ],
                'signed_data' => $signedContent->getBase64Data()
            ]);

            $jobId = $resp->getData()['job_id'] ?? null;
            if (!$jobId && isset($resp->getData()['links'][0]['href'])) {
                $jobId = basename($resp->getData()['links'][0]['href']);
            }

            if (!$jobId) {
                throw new \RuntimeException('Не вдалося отримати Job ID від ЕСОЗ.');
            }

            $jobApi = EHealth::job();
            $attempts = 0;
            do {
                sleep(2);
                $finalResponse = $jobApi->getDetails($jobId)->getData();
                $attempts++;
                $status = strtolower((string) ($finalResponse['status'] ?? ''));
            } while (in_array($status, ['pending', 'accepted', 'processing'], true) && $attempts < 15);

            if ($status !== 'processed' && $status !== 'active') {
                $errorHandler = new \App\Classes\eHealth\Errors\ErrorHandler();
                $errorResult = $errorHandler->handleError($finalResponse);
                $errorMessages = $errorResult['errors'] ?? [];

                if (empty($errorMessages) || $errorMessages[0] === 'No valid error information provided.') {
                    $fallbackMsg = data_get($finalResponse, 'error.message')
                        ?? data_get($finalResponse, 'message')
                        ?? 'Unknown eHealth Error';
                    $errorMessages = [$fallbackMsg];
                }

                $formattedError = implode("\n", $errorMessages);
                throw new \RuntimeException($formattedError);
            }

            $encounterUuid = $formattedData['encounter']['id'];
            $syncData = EHealth::encounter()->getById($this->patientUuid, $encounterUuid)->validate();
            Repository::encounter()->sync($this->patient(), [$syncData]);

            Session::flash('success', 'Взаємодію успішно підписано та надіслано до ЕСОЗ.');
            $this->showSignatureModal = false;

            if ($this->prepersonId !== null) {
                $this->redirectRoute(
                    'prepersons.encounter.edit',
                    [legalEntity(), 'preperson' => $this->prepersonId, 'encounterId' => $this->encounterId],
                    navigate: true
                );
            } else {
                $this->redirectRoute(
                    'encounter.edit',
                    [legalEntity(), 'person' => $this->personId, 'encounterId' => $this->encounterId],
                    navigate: true
                );
            }

        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while submitting encounter');
            $this->showSignatureModal = false;
        } catch (\RuntimeException $exception) {
            logger()->error('Encounter submission runtime error: ' . $exception->getMessage());
            Session::flash('error', $exception->getMessage());
            $this->showSignatureModal = false;
        } catch (\Throwable $exception) {
            logger()->error('Encounter submission unexpected error: ' . $exception->getMessage(), [
                'trace' => $exception->getTraceAsString(),
            ]);
            $errorMessage = __('encounters.messages.unexpected_error');
            Session::flash('error', $errorMessage);
            $this->showSignatureModal = false;
        }

        Encounter::query()
            ->whereId($this->encounterId)
            ->update([
                'status' => EncounterStatus::FINISHED->value
            ]);

        $this->redirectRoute('persons.index', [legalEntity()], navigate: true);
    }

    /**
     * Ask for the reason the whole package is being marked as entered in error, dropping whatever records
     * were picked before, so that the encounter itself is the one being cancelled.
     *
     * @return void
     */
    public function openPackageCancellation(): void
    {
        $this->selectedRecords = self::NO_RECORDS_SELECTED;

        $this->openEncounterCancellation($this->encounterUuid);
    }

    /**
     * Ask for the reason the picked records are being marked as entered in error.
     *
     * @return void
     */
    public function openRecordsCancellation(): void
    {
        if ($this->selectedCancellationRecords() === []) {
            Session::flash('error', __('encounters.messages.cancel_records_not_picked'));

            return;
        }

        $this->openEncounterCancellation($this->encounterUuid);
    }

    /**
     * @inheritDoc
     */
    protected function encounterCancellationForm(): EncounterCancellationForm
    {
        return $this->cancellationForm;
    }

    /**
     * @inheritDoc
     */
    protected function selectedCancellationRecords(): array
    {
        // The picks arrive from the browser, so only known sections holding record ids survive
        return array_filter(array_map(
            static fn (mixed $recordIds): array => array_filter((array)$recordIds, 'is_string'),
            array_intersect_key($this->selectedRecords, self::NO_RECORDS_SELECTED)
        ));
    }

    /**
     * @inheritDoc
     */
    protected function afterEncounterCancelled(): void
    {
        // Cancelled records leave the encounter itself alive, so the page reloads to show them as they now are
        if ($this->selectedCancellationRecords() !== []) {
            $this->redirectRoute(
                $this->prepersonId !== null ? 'prepersons.encounter.edit' : 'encounter.edit',
                $this->prepersonId !== null
                    ? [legalEntity(), 'preperson' => $this->prepersonId, 'encounterId' => $this->encounterId]
                    : [legalEntity(), $this->personId, $this->encounterId],
                navigate: true
            );

            return;
        }

        if ($this->prepersonId !== null) {
            $this->redirectRoute(
                'prepersons.encounters',
                [legalEntity(), 'preperson' => $this->prepersonId],
                navigate: true
            );

            return;
        }

        $this->redirectRoute('persons.encounters', [legalEntity(), $this->personId], navigate: true);
    }

    /**
     * Rename 'id' to 'uuid' and convert keys to snake_case for sync methods.
     *
     * @param  array  $fhirItem
     * @return array
     */
    private function fhirToSync(array $fhirItem): array
    {
        return Arr::toSnakeCase(
            collect($fhirItem)->put('uuid', $fhirItem['id'])->forget(['id'])->all()
        );
    }
}
