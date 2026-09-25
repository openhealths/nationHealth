<?php

declare(strict_types=1);

namespace App\Livewire\Encounter;

use App\Classes\Cipher\Api\CipherRequest;
use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\Episode\Status;
use App\Enums\Person\EncounterStatus;
use App\Enums\Person\ServiceRequestStatus;
use App\Exceptions\Cipher\CipherConnectionException;
use App\Exceptions\Cipher\CipherException;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\Repository;
use App\Services\MedicalEvents\EncounterPackageBuilder;
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use App\Traits\EnsuresEntityExists;
use App\Traits\SubmitsEHealthEncounter;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class EncounterCreate extends EncounterComponent
{
    use EnsuresEntityExists;
    use SubmitsEHealthEncounter;

    private EncounterPackageBuilder $packageBuilder;

    public ?int $prepersonId = null;

    public bool $showReferralRedeemModal = false;
    public string $referralToRedeemUuid = '';
    public string $createdEncounterUuidForRedeem = '';

    /**
     * UUID of the electronic referral already taken into work at selection time.
     * Keeps qualify/process off the KEP signing path.
     */
    public ?string $preparedElectronicReferralUuid = null;

    private function resolveReferralUuid(string $referralNumber): ?string
    {
        if (empty($referralNumber) || Str::isUuid($referralNumber)) {
            return $referralNumber;
        }

        if ($this->selectedReferralUuid !== null) {
            $selectedReferral = collect($this->availableReferrals)->firstWhere('id', $this->selectedReferralUuid);

            if (($selectedReferral['requisition'] ?? null) === $referralNumber) {
                return $this->selectedReferralUuid;
            }
        }

        $referral = collect($this->availableReferrals)->firstWhere('requisition', $referralNumber);

        return data_get($referral, 'id');
    }

    /**
     * Called when the doctor picks an electronic referral from the dropdown.
     * Runs eHealth use/qualify here so signing the encounter stays on Cipher only.
     */
    public function selectElectronicReferral(string $referralUuid, ReferralRequestLifecycleService $lifecycle): void
    {
        $referralUuid = trim($referralUuid);
        if ($referralUuid === '' || !Str::isUuid($referralUuid)) {
            return;
        }

        $referral = collect($this->availableReferrals)->firstWhere('id', $referralUuid);
        if ($referral === null) {
            Session::flash('error', __('encounters.messages.referral_not_found'));

            return;
        }

        $this->selectedReferralUuid = $referralUuid;
        $this->form->encounter['referralType'] = 'electronic';
        $this->form->encounter['referralNumber'] = (string) ($referral['requisition'] ?? $referralUuid);

        try {
            $this->ensureReferralTakenIntoWork($lifecycle, $referralUuid);
            $this->preparedElectronicReferralUuid = $referralUuid;
        } catch (Throwable $exception) {
            $this->preparedElectronicReferralUuid = null;
            Session::flash(
                'error',
                __('Не вдалося взяти направлення в роботу: ').$exception->getMessage()
            );
        }
    }

    /**
     * Take an active electronic referral into work (eHealth use / qualify).
     * Idempotent when local status is already in_progress.
     */
    private function ensureReferralTakenIntoWork(ReferralRequestLifecycleService $service, string $referralUuid): void
    {
        $local = Repository::serviceRequest()->findByUuid($referralUuid);
        $status = strtolower((string) ($local?->status ?? ''));

        if ($status === ServiceRequestStatus::IN_PROGRESS->value) {
            return;
        }

        $needsTakeIntoWork = $local === null
            || $status === ''
            || $status === ServiceRequestStatus::ACTIVE->value
            || $status === 'active';

        if (!$needsTakeIntoWork) {
            return;
        }

        $employee = Auth::user()?->employees()
            ->where('legal_entity_id', legalEntity()->id)
            ->first();

        if ($employee === null) {
            throw new RuntimeException(__('Не знайдено співробітника для взяття направлення в роботу.'));
        }

        $service->takeIntoWork(
            $referralUuid,
            $employee,
            $this->patientUuid ?: null,
            array_filter([
                'program_id' => $local?->programId,
            ])
        );
    }

    private function resolveAllReferrals(array &$validated): void
    {
        if (($validated['encounter']['referralType'] ?? '') === 'electronic' && !empty($validated['encounter']['referralNumber'])) {
            $originalNumber = $validated['encounter']['referralNumber'];
            $uuid = $this->resolveReferralUuid($originalNumber);

            if ($uuid === null) {
                throw ValidationException::withMessages([
                    'form.encounter.referralNumber' => __('encounters.messages.referral_not_found')
                ]);
            }

            $validated['encounter']['referralNumber'] = $uuid;
            $validated['encounter']['referralDisplayValue'] = $originalNumber;
        }

        foreach ($validated['procedures'] ?? [] as $index => $procedure) {
            if (($procedure['referralType'] ?? '') !== 'electronic' || empty($procedure['basedOnIdentifier'])) {
                continue;
            }

            $uuid = $this->resolveReferralUuid($procedure['basedOnIdentifier']);

            if ($uuid === null) {
                throw ValidationException::withMessages([
                    "form.procedures.{$index}.basedOnIdentifier" => __('encounters.messages.referral_not_found')
                ]);
            }

            $validated['procedures'][$index]['basedOnIdentifier'] = $uuid;
        }
    }

    public function boot(): void
    {
        parent::boot();
        $this->packageBuilder = app(EncounterPackageBuilder::class);
    }

    public function mount(LegalEntity $legalEntity, ?Person $person = null, ?Preperson $preperson = null): void
    {
        if ($preperson !== null) {
            $this->prepersonId = $preperson->id;
        } else {
            $this->personId = $person->id;
        }

        $this->initializeComponent();

        if (request()->query('type') === 'discharge') {
            $this->presetDischarge();
        }

        $this->setDefaultDate();

        $this->loadAvailableReferrals();
    }

    /**
     * Fill in the interaction class and type a patient discharge is always submitted with,
     * and bind the interaction to the episode the patient was hospitalized within.
     *
     * @return void
     */
    protected function presetDischarge(): void
    {
        if (!isset($this->dictionaries['eHealth/encounter_classes']['INPATIENT'])) {
            return;
        }

        $this->form->encounter['classCode'] = 'INPATIENT';
        $this->adjustEncounterTypes();

        if (isset($this->dictionaries['eHealth/encounter_types']['discharge'])) {
            $this->form->encounter['typeCode'] = 'discharge';
        }

        $this->episodeType = 'existing';
    }

    /**
     * Validate and save data.
     *
     * @return void
     */
    public function save(): void
    {
        if (Auth::user()->cannot('create', Encounter::class)) {
            Session::flash('error', __('encounters.policy.create'));

            return;
        }

        try {
            $this->syncEncounterParticipants();
            // Validating from the component runs every form of the package and collects their errors in one pass
            $validated = $this->validate();
            try {
                $this->resolveAllReferrals($validated);
            } catch (Exception $e) {
                $this->dispatch('scroll-to-error');

                return;
            }
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());
            $this->dispatch('scroll-to-error');

            return;
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while searching for referral');
            $this->dispatch('scroll-to-error');

            return;
        }

        $formattedData = $this->packageBuilder->build($validated, $this->episodeType, Status::DRAFT);
        $formattedData['encounter']['status'] = EncounterStatus::DRAFT->value;

        try {
            $encounterId = $this->storeValidatedData($formattedData);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to store validated data');

            return;
        }

        Session::flash('success', __('encounters.messages.created'));

        if ($this->prepersonId !== null) {
            $this->redirectRoute(
                'prepersons.encounter.edit',
                [legalEntity(), 'preperson' => $this->prepersonId, 'encounterId' => $encounterId],
                navigate: true
            );

            return;
        }

        $this->redirectRoute('encounter.edit', [legalEntity(), $this->personId, $encounterId], navigate: true);
    }

    /**
     * Sign the encounter, submit it to eHealth, then persist it locally.
     *
     * @return void
     */
    public function sign(): void
    {
        if (Auth::user()->cannot('create', Encounter::class)) {
            Session::flash('error', __('encounters.policy.create'));
            $this->showSignatureModal = false;

            return;
        }

        $this->syncEncounterParticipants();

        try {
            // Validating from the component runs every form of the package and collects their errors in one pass
            $validatedData = $this->validate();
            try {
                $this->resolveAllReferrals($validatedData);
            } catch (Exception $e) {
                $this->dispatch('scroll-to-error');

                return;
            }
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());
            $this->showSignatureModal = false;
            $this->dispatch('scroll-to-error');

            return;
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while searching for referral');
            $this->showSignatureModal = false;
            $this->dispatch('scroll-to-error');

            return;
        }

        // Then validate signing requirements
        try {
            $validated = $this->form->validate($this->form->signingRules());
        } catch (ValidationException $exception) {
            // The KEP errors render inside the signature modal, so it stays open to show them
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $formattedData = $this->packageBuilder->build($validatedData, $this->episodeType);

        try {
            $createdEncounterId = $this->storeValidatedData($formattedData);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to store validated data');
            $this->showSignatureModal = false;

            return;
        }

        $formattedData = Arr::toSnakeCase($formattedData);

        // Remove display_value from incoming_referral before sending to eHealth (schema rejects it)
        unset($formattedData['encounter']['incoming_referral']['display_value']);

        try {
            $this->validateEncounterPerformer($formattedData);
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());

            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        if ($this->episodeType === 'new') {
            $this->createEpisode($formattedData['episode']);
            unset($formattedData['episode']);
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

            logger()->debug('Job ID to further debug', $resp->getData());
            $encounterUuid = $formattedData['encounter']['id'];

            // Call trait helper
            $this->waitForEncounterJobAndSync(
                $resp->getData(),
                $this->patientUuid,
                $encounterUuid,
                $this->patient()
            );

            Session::flash('success', __('Взаємодію успішно створено та надіслано до ЕСОЗ.'));
            $this->showSignatureModal = false;

            if (($this->form->encounter['referralType'] ?? '') === 'electronic' && !empty($this->form->encounter['referralNumber'])) {
                $this->referralToRedeemUuid = $this->resolveReferralUuid($this->form->encounter['referralNumber']);
                $this->createdEncounterUuidForRedeem = $encounterUuid;
                if ($this->referralToRedeemUuid) {
                    $this->showReferralRedeemModal = true;

                    return; // Prevent redirect, show modal
                }
            }

            $this->redirectAfterCreate($createdEncounterId);

        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while submitting encounter');
            $this->showSignatureModal = false;
        } catch (RuntimeException $exception) {
            logger()->error('Encounter submission runtime error: ' . $exception->getMessage());
            Session::flash('error', $exception->getMessage());
            $this->showSignatureModal = false;
        } catch (Throwable $exception) {
            logger()->error('Encounter submission unexpected error: ' . $exception->getMessage(), [
                'trace' => $exception->getTraceAsString(),
            ]);
            $errorMessage = __('encounters.messages.unexpected_error');
            Session::flash('error', $errorMessage);
            $this->showSignatureModal = false;
        }
    }

    /**
     * Set default encounter period date.
     *
     * @return void
     */
    private function setDefaultDate(): void
    {
        $now = CarbonImmutable::now();

        $this->form->encounter['periodDate'] = $now->format(config('app.date_format'));
        $this->form->encounter['periodStart'] = $now->subMinutes(15)->max($now->startOfDay())->format('H:i');
        $this->form->encounter['periodEnd'] = $now->format('H:i');
    }

    /**
     * Store validated formatted data into DB.
     *
     * @param  array  $formattedData
     * @return int
     * @throws Throwable
     */
    protected function storeValidatedData(array $formattedData): int
    {
        return DB::transaction(function () use ($formattedData) {
            $createdEncounterId = Repository::encounter()->store($formattedData['encounter'], $this->patient());
            $this->storePackageElectronicReferralsIfMissing();

            if (isset($formattedData['episode'])) {
                Repository::episode()->store($formattedData['episode'], $this->patient(), $createdEncounterId);
            }

            if (isset($formattedData['conditions'])) {
                Repository::condition()->store($formattedData['conditions'], $this->patient());
            }

            if (isset($formattedData['immunizations'])) {
                Repository::immunization()->store($formattedData['immunizations'], $this->patient());
            }

            if (isset($formattedData['diagnosticReports'])) {
                Repository::diagnosticReport()->store($formattedData['diagnosticReports'], $this->patient());
            }

            if (isset($formattedData['observations'])) {
                Repository::observation()->store($formattedData['observations'], $this->patient());
            }

            if (isset($formattedData['procedures'])) {
                Repository::procedure()->store($formattedData['procedures'], $this->patient());

                foreach ($formattedData['procedures'] as $procedure) {
                    $this->processReasonReferences($procedure);
                    $this->processComplicationDetails($procedure);
                }
            }

            if (isset($formattedData['deviceDispenses'])) {
                Repository::deviceDispense()->store($formattedData['deviceDispenses'], $this->patient());
            }

            if (isset($formattedData['specimens'])) {
                Repository::specimen()->store($formattedData['specimens'], $this->patient());
            }

            if (isset($formattedData['devices'])) {
                Repository::device()->store($formattedData['devices'], $this->patient());
            }

            if (isset($formattedData['deviceAssociations'])) {
                Repository::deviceAssociation()->store($formattedData['deviceAssociations'], $this->patient());
            }

            if (isset($formattedData['detectedIssues'])) {
                Repository::detectedIssue()->store($formattedData['detectedIssues'], $this->patient());
            }

            if (isset($formattedData['clinicalImpressions'])) {
                Repository::clinicalImpression()->store($formattedData['clinicalImpressions'], $this->patient());

                foreach ($formattedData['clinicalImpressions'] as $clinicalImpression) {
                    $this->processPrevious($clinicalImpression);
                    $this->processSupportingInfo($clinicalImpression);
                    $this->processFindings($clinicalImpression);
                }
            }

            return $createdEncounterId;
        });
    }

    /**
     * Create episode for patient.
     *
     * @param  array  $formattedEpisode
     * @return void
     */
    protected function createEpisode(array $formattedEpisode): void
    {
        try {
            EHealth::episode()->create($this->patientUuid, Arr::toSnakeCase($formattedEpisode));
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when create episode');

            return;
        }
    }

    public function closeRedeemModal(): void
    {
        $this->showReferralRedeemModal = false;
        $encounter = Encounter::where('uuid', $this->createdEncounterUuidForRedeem)->first();
        if ($encounter) {
            $this->redirectAfterCreate($encounter->id);
        } else {
            $this->redirectToPatientEncounters();
        }
    }

    public function redeemReferral(ReferralRequestLifecycleService $service): void
    {
        try {
            if ($this->referralToRedeemUuid && $this->createdEncounterUuidForRedeem) {
                // use/qualify already ran on referral selection; redeem only completes.
                // Safety net: if the doctor typed a number without picking from the list, take into work once here.
                if ($this->preparedElectronicReferralUuid !== $this->referralToRedeemUuid) {
                    $this->ensureReferralTakenIntoWork($service, $this->referralToRedeemUuid);
                }

                $service->completeReferral($this->referralToRedeemUuid, $this->createdEncounterUuidForRedeem);
                Session::flash('success', __('Направлення успішно погашено!'));
            }
        } catch (Exception $e) {
            Session::flash('error', __('Не вдалося погасити направлення: ') . $e->getMessage());
        }

        $this->showReferralRedeemModal = false;

        // Find local encounter ID by UUID to redirect properly
        $encounter = Encounter::where('uuid', $this->createdEncounterUuidForRedeem)->first();
        if ($encounter) {
            $this->redirectAfterCreate($encounter->id);
        } else {
            $this->redirectToPatientEncounters();
        }
    }

    /**
     * Fall back to the encounter list of the patient the package was written for.
     *
     * @return void
     */
    private function redirectToPatientEncounters(): void
    {
        if ($this->prepersonId !== null) {
            $this->redirectRoute('prepersons.encounters', [legalEntity(), 'preperson' => $this->prepersonId]);

            return;
        }

        $this->redirectRoute('persons.encounters', [legalEntity(), 'person' => $this->personId]);
    }

    public function redirectAfterCreate(int $encounterId): void
    {
        if ($this->prepersonId !== null) {
            $this->redirectRoute(
                'prepersons.encounter.edit',
                [legalEntity(), 'preperson' => $this->prepersonId, 'encounterId' => $encounterId],
                navigate: true
            );
        } else {
            $this->redirectRoute(
                'encounter.edit',
                [legalEntity(), 'person' => $this->personId, 'encounterId' => $encounterId],
                navigate: true
            );
        }
    }
}
