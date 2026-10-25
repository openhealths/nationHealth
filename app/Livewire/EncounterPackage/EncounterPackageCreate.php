<?php

declare(strict_types=1);

namespace App\Livewire\EncounterPackage;

use App\Classes\Cipher\Api\CipherRequest;
use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\Episode\Status;
use App\Enums\Person\EncounterStatus;
use App\Exceptions\Cipher\CipherConnectionException;
use App\Exceptions\Cipher\CipherException;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\Repository;
use App\Traits\EnsuresEntityExists;
use App\Traits\MedicalEvents\UpdatesReferralExecution;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use RuntimeException;
use Throwable;

class EncounterPackageCreate extends EncounterPackageComponent
{
    use EnsuresEntityExists;
    use UpdatesReferralExecution;

    public ?int $prepersonId = null;

    /**
     * UUID of the electronic referral already taken into work at selection time.
     * Keeps qualify/process off the KEP signing path.
     */
    public ?string $preparedElectronicReferralUuid = null;

    #[Locked]
    public ?string $confirmedElectronicReferralUuid = null;

    public function mount(LegalEntity $legalEntity, ?Person $person = null, ?Preperson $preperson = null): void
    {
        if ($preperson !== null) {
            $this->prepersonId = $preperson->id;
        } else {
            $this->personId = $person->id;
        }

        $this->initializeComponent();

        match (request()->query('type')) {
            'discharge' => $this->presetDischarge(),
            'hospitalization_refusal' => $this->presetHospitalizationRefusal(),
            default => null
        };

        $this->setDefaultDate();
    }

    /**
     * Called when the doctor picks an electronic referral from the dropdown.
     * Runs eHealth use/qualify here so signing the encounter stays on Cipher only.
     */
    public function selectElectronicReferral(string $referralUuid): void
    {
        $this->authorize('use', ServiceRequestRequest::class);
        $this->preparedElectronicReferralUuid = null;
        $this->confirmedElectronicReferralUuid = null;
        $this->selectedReferralUuid = null;
        $this->resetValidation('form.encounter.referralNumber');

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
            $this->ensureReferralTakenIntoWork($referralUuid);
            $this->preparedElectronicReferralUuid = $referralUuid;
            $this->confirmedElectronicReferralUuid = $referralUuid;
        } catch (Throwable $exception) {
            $this->preparedElectronicReferralUuid = null;
            $this->selectedReferralUuid = null;
            $this->addError('form.encounter.referralNumber', $exception->getMessage());
            Session::flash(
                'error',
                __('Не вдалося взяти направлення в роботу: ').$exception->getMessage()
            );
        }
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
            $this->resolveAllReferrals($validated);
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

        $formattedData = $this->buildEncounterPackage($validated, $this->episodeType, Status::DRAFT);
        $formattedData['encounter']['status'] = EncounterStatus::DRAFT->value;

        try {
            $this->syncRegisteredConditions($validated['conditions'] ?? []);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while getting registered conditions');

            return;
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to store registered conditions');

            return;
        }

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
                [legalEntity(), 'preperson' => $this->prepersonId, 'encounter' =>$encounterId],
                navigate: true
            );

            return;
        }

        $this->redirectRoute('encounter.edit', [legalEntity(), $this->personId, $encounterId], navigate: true);
    }

    /**
     * Persist the encounter as a draft, sign it and submit it to eHealth.
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
            $this->resolveAllReferrals($validatedData, requirePreparation: true);
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

        $formattedData = $this->buildEncounterPackage($validatedData, $this->episodeType);

        try {
            $this->syncRegisteredConditions($validatedData['conditions'] ?? []);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while getting registered conditions');
            $this->showSignatureModal = false;

            return;
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to store registered conditions');
            $this->showSignatureModal = false;

            return;
        }

        if ($this->episodeType === 'new') {
            try {
                EHealth::episode()->create($this->patientUuid, Arr::toSnakeCase($formattedData['episode']));
            } catch (EHealthException|EHealthConnectionException $exception) {
                $exception->handle('Error when create episode');
                $this->showSignatureModal = false;

                return;
            }
        }

        // eHealth accepted the episode; only now persist the package locally
        try {
            $createdEncounterId = $this->storeValidatedData($formattedData);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to store validated data');
            $this->showSignatureModal = false;

            return;
        }

        $formattedData = Arr::toSnakeCase($formattedData);

        // Remove display_value from incoming_referral before sending to eHealth (schema rejects it); the episode was sent on its own
        unset($formattedData['encounter']['incoming_referral']['display_value'], $formattedData['episode']);

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

            // The draft and its episode are stored already, so signing is retried from the draft's edit page
            $this->redirectRoute(
                $this->prepersonId !== null ? 'prepersons.encounter.edit' : 'encounter.edit',
                $this->prepersonId !== null
                    ? [legalEntity(), 'preperson' => $this->prepersonId, 'encounter' =>$createdEncounterId]
                    : [legalEntity(), 'person' => $this->personId, 'encounter' =>$createdEncounterId],
                navigate: true
            );

            return;
        }

        $this->showSignatureModal = false;

        try {
            $this->submitPackage(Encounter::findOrFail($createdEncounterId), $formattedData, $signedContent->getBase64Data());
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while submitting encounter');
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while tracking encounter submission');
        }

        $this->redirectAfterCreate($createdEncounterId);
    }

    public function redirectAfterCreate(int $encounterId): void
    {
        if ($this->prepersonId !== null) {
            $this->redirectRoute(
                'prepersons.encounter.view',
                [legalEntity(), 'preperson' => $this->prepersonId, 'encounter' =>$encounterId],
                navigate: true
            );
        } else {
            $this->redirectRoute(
                'encounter.view',
                [legalEntity(), 'person' => $this->personId, 'encounter' =>$encounterId],
                navigate: true
            );
        }
    }

    /**
     * Fix the interaction class and type a patient discharge is always submitted with,
     * and bind the interaction to the episode the patient was hospitalized within.
     *
     * @return void
     */
    protected function presetDischarge(): void
    {
        if (!in_array('discharge', $this->encounterClassTypes['INPATIENT'] ?? [], true)) {
            return;
        }

        $this->form->encounter['classCode'] = 'INPATIENT';
        $this->form->encounter['typeCode'] = 'discharge';
        $this->isDischarge = true;
        $this->episodeType = 'existing';
    }

    /**
     * Fix the interaction class and type a hospitalization refusal is always submitted with,
     * and open a new episode for it.
     *
     * @return void
     */
    protected function presetHospitalizationRefusal(): void
    {
        if (!in_array('service_delivery_location', $this->encounterClassTypes['AMB'] ?? [], true)) {
            return;
        }

        $this->form->encounter['classCode'] = 'AMB';
        $this->form->encounter['typeCode'] = 'service_delivery_location';
        $this->isHospitalizationRefusal = true;
        $this->episodeType = 'new';
    }

    /**
     * Store validated formatted data into DB. The encounter is kept as a draft until eHealth accepts the package.
     *
     * @param  array  $formattedData
     * @return int
     * @throws Throwable
     */
    protected function storeValidatedData(array $formattedData): int
    {
        $formattedData['encounter']['status'] = EncounterStatus::DRAFT->value;

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
     * Take an active electronic referral into work (eHealth use / qualify).
     * The shared execution concern verifies current eHealth processing status and executor.
     */
    private function ensureReferralTakenIntoWork(string $referralUuid): void
    {
        $local = Repository::serviceRequest()->findByUuid($referralUuid);
        if ($local === null || (int) $local->personId !== $this->personId) {
            throw new RuntimeException(__('Направлення не належить поточному пацієнту.'));
        }

        $employee = Auth::user()?->employees()
            ->whereLegalEntityId(legalEntity()->id)
            ->first();

        if ($employee === null) {
            throw new RuntimeException(__('Не знайдено співробітника для взяття направлення в роботу.'));
        }

        $this->takeReferralIntoWork(
            $referralUuid,
            $employee,
            $this->patientUuid ?: null,
            array_filter([
                'program_id' => $local?->programId,
            ])
        );
    }

    protected function resolveAllReferrals(array &$validated, bool $requirePreparation = false): void
    {
        parent::resolveAllReferrals($validated);

        if (
            $requirePreparation
            && ($validated['encounter']['referralType'] ?? '') === 'electronic'
            && !empty($validated['encounter']['referralNumber'])
            && $this->confirmedElectronicReferralUuid !== $validated['encounter']['referralNumber']
        ) {
            throw ValidationException::withMessages([
                'form.encounter.referralNumber' => __('Оберіть направлення зі списку та підтвердьте його взяття в роботу.'),
            ]);
        }
    }

    /**
     * Render the encounter package form.
     *
     * @return View
     */
    public function render(): View
    {
        return view('livewire.encounter-package.encounter-package-create');
    }
}
