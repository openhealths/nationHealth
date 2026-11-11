<?php

declare(strict_types=1);

namespace App\Livewire\EncounterPackage;

use App\Classes\Cipher\Api\CipherRequest;
use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\Episode\Status;
use App\Enums\Episode\Status as EpisodeStatus;
use App\Enums\Person\EncounterStatus;
use App\Exceptions\Cipher\CipherConnectionException;
use App\Exceptions\Cipher\CipherException;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Livewire\EncounterPackage\Concerns\ManagesIncomingReferralSelection;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\Repository;
use App\Services\MedicalEvents\EHealthJobResolver;
use App\Services\MedicalEvents\EncounterPackageBuilder;
use App\Traits\EnsuresEntityExists;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class EncounterPackageCreate extends EncounterPackageComponent
{
    use EnsuresEntityExists;
    use ManagesIncomingReferralSelection;

    private EncounterPackageBuilder $packageBuilder;

    private ?array $confirmedEpisode = null;

    public ?int $prepersonId = null;

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

        match (request()->query('type')) {
            'discharge' => $this->presetDischarge(),
            'hospitalization_refusal' => $this->presetHospitalizationRefusal(),
            default => null
        };

        $this->setDefaultDate();
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

        $formattedData = $this->packageBuilder->build($validated, $this->episodeType, Status::DRAFT);

        try {
            $this->syncReferencedRecords();
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while getting the records the package refers to');

            return;
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to store the records the package refers to');

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
                'prepersons.encounters.edit',
                [legalEntity(), 'preperson' => $this->prepersonId, 'encounter' => $encounterId],
                navigate: true
            );

            return;
        }

        $this->redirectRoute('persons.encounters.edit', [legalEntity(), $this->personId, $encounterId], navigate: true);
    }

    /**
     * Persist the encounter as a draft, sign it and submit it to eHealth.
     *
     * @return void
     */
    public function sign(): void
    {
        $this->confirmedEpisode = null;
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

        $formattedData = $this->packageBuilder->build($validatedData, $this->episodeType);

        try {
            $this->syncReferencedRecords();
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while getting the records the package refers to');
            $this->showSignatureModal = false;

            return;
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to store the records the package refers to');
            $this->showSignatureModal = false;

            return;
        }

        try {
            $this->syncSelectedEpisode($validatedData['episode']['id'] ?? null);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while getting the selected episode');
            $this->showSignatureModal = false;

            return;
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to store the selected episode');
            $this->showSignatureModal = false;

            return;
        }

        if ($this->episodeType === 'new') {
            try {
                $response = EHealth::episode()->create($this->patientUuid, Arr::toSnakeCase($formattedData['episode']));
                $resolver = app(EHealthJobResolver::class);
                // An accepted episode receipt is only a job acknowledgement; the package needs its confirmed result.
                $resolver->assertSuccessful($resolver->resolve($response->validate()));
                // The job receipt contains no episode snapshot. Read the authoritative patient-scoped resource.
                $episodeUuid = $formattedData['episode']['id'];
                $episode = EHealth::episode()->getById($this->patientUuid, $episodeUuid)->validate();
                if (($episode['uuid'] ?? null) !== $episodeUuid
                    || ($episode['status'] ?? null) !== EpisodeStatus::ACTIVE->value
                    || data_get($episode, 'managing_organization.identifier.value') !== data_get($formattedData, 'episode.managingOrganization.identifier.value')
                    || data_get($episode, 'care_manager.identifier.value') !== data_get($formattedData, 'episode.careManager.identifier.value')) {
                    throw ValidationException::withMessages(['episode' => __('errors.ehealth.messages.validation_error')]);
                }
                $this->confirmedEpisode = $episode;
            } catch (ValidationException $exception) {
                Session::flash('error', __('errors.ehealth.messages.validation_error'));

                return;
            } catch (EHealthException|EHealthConnectionException $exception) {
                $exception->handle('Error when create episode');
                $this->showSignatureModal = false;

                return;
            }
        }

        // eHealth confirmed the episode; only now persist the package locally.
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
                $this->prepersonId !== null ? 'prepersons.encounters.edit' : 'persons.encounters.edit',
                $this->prepersonId !== null
                    ? [legalEntity(), 'preperson' => $this->prepersonId, 'encounter' => $createdEncounterId]
                    : [legalEntity(), 'person' => $this->personId, 'encounter' => $createdEncounterId],
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
                'prepersons.encounters.package.show',
                [legalEntity(), 'preperson' => $this->prepersonId, 'encounter' => $encounterId],
                navigate: true
            );
        } else {
            $this->redirectRoute(
                'persons.encounters.package.show',
                [legalEntity(), 'person' => $this->personId, 'encounter' => $encounterId],
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
                if ($this->confirmedEpisode !== null) {
                    Repository::episode()->syncFull($this->patient(), [$this->confirmedEpisode]);
                }
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
     * Render the encounter package form.
     *
     * @return View
     */
    public function render(): View
    {
        return view('livewire.encounter-package.encounter-package-create');
    }
}
