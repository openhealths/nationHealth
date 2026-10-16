<?php

declare(strict_types=1);

namespace App\Livewire\EncounterPackage;

use App\Classes\Cipher\Api\CipherRequest;
use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\Episode\Status as EpisodeStatus;
use App\Enums\Person\EncounterStatus;
use App\Enums\Person\ServiceRequestStatus;
use App\Livewire\EncounterPackage\Forms\EncounterCancellationForm;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\Repository;
use App\Services\MedicalEvents\Fhir;
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use App\Traits\HandlesEncounterCancellation;
use App\Exceptions\Cipher\CipherConnectionException;
use App\Exceptions\Cipher\CipherException;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use App\Livewire\EncounterPackage\Concerns\ManagesEncounterEPrescription;
use App\Livewire\EncounterPackage\Concerns\ManagesEncounterReferrals;
use App\Livewire\EncounterPackage\Concerns\ResolvesEncounterStandaloneContext;
use Exception;
use App\Models\MedicalEvents\Sql\Composition;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use RuntimeException;
use Throwable;

class EncounterPackageEdit extends EncounterPackageComponent
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
     * Episode type the draft was saved with, which stays as it is, since a new episode is stored along with the draft.
     *
     * @var string
     */
    #[Locked]
    public string $episodeType = 'new';

    /**
     * Whether the encounter may be marked as entered in error by the current user.
     *
     * @var bool
     */
    #[Locked]
    public bool $canBeCancelled;

    /**
     * Encounter status, shown in the eHealth status block while the package was never submitted.
     *
     * @var string
     */
    public string $statusLabel;

    /**
     * Whether eHealth accepted the encounter, so records referring to it, as prescriptions or referrals, can be added.
     *
     * @var bool
     */
    #[Locked]
    public bool $isFinished;

    /**
     * Electronic referral the accepted encounter was based on, taken into work but not redeemed yet.
     *
     * @var string|null
     */
    #[Locked]
    public ?string $referralToRedeemId = null;

    public bool $showReferralRedeemModal = false;

    /**
     * Episode opened along with the package and stored only locally, so it is created in eHealth on signing.
     *
     * @var string|null
     */
    #[Locked]
    public ?string $draftEpisodeId = null;

    public EncounterCancellationForm $cancellationForm;

    /**
     * Where "add medical conclusion" from this encounter should send the doctor.
     *
     * Primary care always opens МВТН. Outpatient on a preperson prefers МВН when the
     * specialist may issue one, otherwise falls back to МВТН for unidentified patients.
     */
    #[Computed]
    public function createCompositionFromEncounterUrl(): ?string
    {
        if (blank($this->encounterUuid)) {
            return null;
        }

        $query = http_build_query(['encounter' => $this->encounterId]);

        if ($this->prepersonId !== null) {
            if (Auth::user()->can('createNewborn', Composition::class)) {
                return route('prepersons.compositions.newborn.create', [
                    legalEntity(),
                    'preperson' => $this->prepersonId,
                ]).'?'.$query;
            }

            if (Auth::user()->can('createTempDisability', Composition::class)) {
                return route('prepersons.compositions.temp-disability.create', [
                    legalEntity(),
                    'preperson' => $this->prepersonId,
                ]).'?'.$query;
            }

            return null;
        }

        if (!Auth::user()->can('createTempDisability', Composition::class)) {
            return null;
        }

        return route('persons.compositions.temp-disability.create', [
            legalEntity(),
            'person' => $this->personId,
        ]).'?'.$query;
    }

    public function mount(LegalEntity $legalEntity, Encounter $encounter, ?Person $person = null, ?Preperson $preperson = null): void
    {
        if ($preperson !== null) {
            $this->prepersonId = $preperson->id;
        } else {
            $this->personId = $person->id;
        }

        $this->encounterId = $encounter->id;

        $encounterModel = Encounter::withRelationships()->whereId($this->encounterId)->firstOrFail();
        $this->encounterUuid = $encounterModel->uuid;
        $this->canBeCancelled = Auth::user()->can('cancel', $encounterModel);
        $this->cancelledRecords = Repository::encounter()->cancelledRecordIds($encounterModel->uuid);

        $this->statusLabel = $encounterModel->status->label();
        $this->isFinished = $encounterModel->status === EncounterStatus::FINISHED;

        $referralId = $encounterModel->incomingReferral?->value;

        if (
            $encounterModel->status === EncounterStatus::FINISHED
            && $referralId !== null
            && strtolower((string) Repository::serviceRequest()->findByUuid($referralId)?->status) === ServiceRequestStatus::IN_PROGRESS->value
        ) {
            $this->referralToRedeemId = $referralId;
            $this->showReferralRedeemModal = true;
        }

        $encounter = $encounterModel->toArray();
        $this->isReadonly = Auth::user()->cannot('update', $encounterModel);

        $episodeId = data_get($encounter, 'episode.identifier.value', '');
        $draftEpisode = Episode::whereUuid($episodeId)->whereStatus(EpisodeStatus::DRAFT)->with('type')->first();

        if ($draftEpisode !== null) {
            $this->draftEpisodeId = $draftEpisode->uuid;
            $this->episodeType = 'new';
            $this->form->episode = array_merge(
                $this->form->episode,
                ['typeCode' => $draftEpisode->type->code, 'name' => $draftEpisode->name]
            );
        } else {
            $this->episodeType = 'existing';
            $this->form->episode = array_merge($this->form->episode, ['id' => $episodeId]);
        }

        $this->initializeComponent(loadEpisodes: !$this->isReadonly);

        $package = Fhir::encounterPackageLoader()->load($encounter);

        $reactionIds = collect($package['observations'])->pluck('reactionOn')->filter()->unique();
        $packageImmunizationIds = collect($package['immunizations'])->pluck('uuid')->filter()->unique();

        if ($reactionIds->diff($packageImmunizationIds)->isNotEmpty()) {
            $this->searchReactionImmunizations();
        }

        $this->form->encounter = $package['encounter'];
        $this->isDischarge = ($package['encounter']['typeCode'] ?? null) === 'discharge';
        $this->conditionForm->conditions = $package['conditions'];
        $this->immunizationForm->immunizations = $package['immunizations'];
        $this->diagnosticReportForm->diagnosticReports = $package['diagnosticReports'];
        $this->observationForm->observations = $package['observations'];
        $this->procedureForm->procedures = $package['procedures'];
        $this->deviceDispenseForm->deviceDispenses = $package['deviceDispenses'];
        $this->specimenForm->specimens = $package['specimens'];
        $this->deviceForm->devices = $package['devices'];
        $this->detectedIssueForm->detectedIssues = $package['detectedIssues'];
        $this->deviceAssociationForm->deviceAssociations = $package['deviceAssociations'];
        $this->clinicalImpressionForm->clinicalImpressions = $package['clinicalImpressions'];

        if ($this->isReadonly) {
            $this->loadSelectedReferralsForView([
                ...$package['procedures'],
                ...$package['diagnosticReports'],
            ]);

            $basedOnIds = collect($package['detectedIssues'])
                ->pluck('basedOnId')
                ->filter()
                ->unique()
                ->values()
                ->toArray();

            $this->previousDetectedIssues = Repository::detectedIssue()
                ->getByUuidsForSelect($this->patient(), $basedOnIds);
        }

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

        // The package may have been submitted from another page since this one was opened
        if (Auth::user()->cannot('update', Encounter::findOrFail($this->encounterId))) {
            Session::flash('error', __('encounters.policy.update'));

            return null;
        }

        try {
            $this->syncEncounterParticipants();
            // Validating from the component runs every form of the package and collects their errors in one pass
            $validated = $this->validate();
            $this->resolveAllReferrals($validated);
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());
            $this->dispatch('scroll-to-error');

            return null;
        }

        try {
            $this->syncRegisteredConditions($validated['conditions'] ?? []);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while getting registered conditions');

            return null;
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to store registered conditions');

            return null;
        }

        $encounter = Encounter::withRelationships()->whereId($this->encounterId)->firstOrFail()->toArray();
        $uuids = [
            'encounter' => $encounter['uuid'],
            'visit' => data_get($encounter, 'visit.identifier.value'),
            'employee' => Auth::user()->getEncounterWriterEmployee($validated['encounter']['classCode'])->uuid,
            'episode' => $this->episodeType === 'new' ? $this->draftEpisodeId : $validated['episode']['id']
        ];

        $fhirEpisode = $this->episodeType === 'new' && $this->draftEpisodeId !== null
            ? Fhir::episode()->toFhir(
                $validated['episode'],
                $uuids,
                $validated['encounter']['periodDate'],
                $validated['encounter']['periodStart'],
                EpisodeStatus::DRAFT
            )
            : null;

        $fhir = Fhir::encounterPackage()->toFhir($validated, $uuids);
        $fhirEncounter = $fhir['encounter'];
        $fhirEncounter['status'] = EncounterStatus::DRAFT->value;
        $fhirConditions = $fhir['conditions'];
        $fhirImmunizations = $fhir['immunizations'];
        $fhirDiagnosticReports = $fhir['diagnosticReports'];
        $fhirObservations = $fhir['observations'];
        $fhirProcedures = $fhir['procedures'];
        $fhirDeviceDispenses = $fhir['deviceDispenses'];
        $fhirDevices = $fhir['devices'];
        $fhirDeviceAssociations = $fhir['deviceAssociations'];
        $fhirDetectedIssues = $fhir['detectedIssues'];
        $fhirClinicalImpressions = $fhir['clinicalImpressions'];

        try {
            $this->storePackageElectronicReferralsIfMissing();

            if ($fhirEpisode !== null) {
                Repository::episode()->updateDraft(Episode::whereUuid($this->draftEpisodeId)->firstOrFail(), $fhirEpisode);
            }

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
            Repository::deviceDispense()->sync(
                $this->patient(),
                array_map($this->fhirToSync(...), $fhirDeviceDispenses)
            );
            Repository::specimen()->sync($this->patient(), array_map($this->fhirToSync(...), $fhir['specimens']));
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
            'episode' => $fhirEpisode,
            'conditions' => $fhirConditions,
            'immunizations' => $fhirImmunizations,
            'diagnosticReports' => $fhirDiagnosticReports,
            'observations' => $fhirObservations,
            'procedures' => $fhirProcedures,
            'deviceDispenses' => $fhirDeviceDispenses,
            'specimens' => $fhir['specimens'],
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

        // The package may have been submitted from another page since this one was opened
        if (Auth::user()->cannot('update', Encounter::findOrFail($this->encounterId))) {
            Session::flash('error', __('encounters.policy.update'));
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

        if (isset($formattedData['episode'])) {
            $formattedData['episode']['status'] = EpisodeStatus::ACTIVE->value;

            try {
                EHealth::episode()->create($this->patientUuid, Arr::toSnakeCase($formattedData['episode']));
            } catch (EHealthException|EHealthConnectionException $exception) {
                $exception->handle('Error when create episode');
                $this->showSignatureModal = false;

                return;
            }

            // eHealth accepted the episode; only now promote the local draft
            try {
                Repository::episode()->updateDraft(
                    Episode::whereUuid($this->draftEpisodeId)->firstOrFail(),
                    $formattedData['episode']
                );
            } catch (Throwable $exception) {
                $this->handleDatabaseErrors($exception, 'Failed to store created episode');
                $this->showSignatureModal = false;

                return;
            }

            // The episode exists in eHealth now, so a retry after a failure below refers to it instead of creating it again
            $this->episodeType = 'existing';
            $this->form->episode = array_merge(
                $this->form->episode,
                ['id' => $this->draftEpisodeId, 'typeCode' => '', 'name' => '']
            );
            $this->draftEpisodeId = null;

            // The episode was sent on its own, it is not part of the signed package
            unset($formattedData['episode']);
        }

        $formattedData['encounter']['status'] = EncounterStatus::FINISHED->value;
        $formattedData = Arr::toSnakeCase($formattedData);

        // Remove display_value from incoming_referral before sending to eHealth (schema rejects it)
        unset($formattedData['encounter']['incoming_referral']['display_value']);

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

        $this->showSignatureModal = false;

        try {
            $this->submitPackage(Encounter::findOrFail($this->encounterId), $formattedData, $signedContent->getBase64Data());
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while submitting encounter');

            return;
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while tracking encounter submission');

            return;
        }

        $this->redirectRoute(
            $this->prepersonId !== null ? 'prepersons.encounter.view' : 'encounter.view',
            $this->prepersonId !== null
                ? [legalEntity(), 'preperson' => $this->prepersonId, 'encounter' =>$this->encounterId]
                : [legalEntity(), 'person' => $this->personId, 'encounter' =>$this->encounterId],
            navigate: true
        );
    }

    /**
     * Redeem the electronic referral the accepted encounter was based on.
     *
     * @param  ReferralRequestLifecycleService  $service
     * @return void
     */
    public function redeemReferral(ReferralRequestLifecycleService $service): void
    {
        $this->showReferralRedeemModal = false;

        try {
            if (
                $this->referralToRedeemId === null
                || strtolower((string) Repository::serviceRequest()->findByUuid($this->referralToRedeemId)?->status) !== ServiceRequestStatus::IN_PROGRESS->value
            ) {
                throw new RuntimeException(__('Направлення не було підтверджено перед підписанням взаємодії.'));
            }

            $service->completeReferral($this->referralToRedeemId, $this->encounterUuid);
            Session::flash('success', __('encounters.messages.referral_redeemed'));
        } catch (Exception $exception) {
            Session::flash('error', __('encounters.messages.referral_redeem_error', ['message' => $exception->getMessage()]));
        }
    }

    private function loadSelectedReferralsForView(array $records): void
    {
        $referralIds = collect($records)
            ->pluck('basedOnIdentifier')
            ->filter()
            ->unique()
            ->values();

        if ($referralIds->isEmpty()) {
            $this->referralsLoaded = true;

            return;
        }

        $referrals = ServiceRequestRequest::query()
            ->whereIn('uuid', $referralIds->all())
            ->get(['uuid', 'request_number'])
            ->keyBy('uuid');

        $this->availableReferrals = $referralIds
            ->map(static function (string $referralId) use ($referrals): ?array {
                $referral = $referrals->get($referralId);

                if ($referral === null || blank($referral->requestNumber)) {
                    return null;
                }

                return [
                    'id' => $referral->uuid,
                    'requisition' => $referral->requestNumber,
                ];
            })
            ->filter()
            ->values()
            ->toArray();

        $this->referralsLoaded = true;
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
                $this->prepersonId !== null ? 'prepersons.encounter.view' : 'encounter.view',
                $this->prepersonId !== null
                    ? [legalEntity(), 'preperson' => $this->prepersonId, 'encounter' =>$this->encounterId]
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
