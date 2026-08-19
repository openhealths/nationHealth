<?php

declare(strict_types=1);

namespace App\Livewire\Encounter;

use App\Classes\Cipher\Api\CipherRequest;
use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\Episode\Status;
use App\Exceptions\Cipher\CipherConnectionException;
use App\Exceptions\Cipher\CipherException;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Enums\Person\EncounterStatus;
use App\Repositories\MedicalEvents\Repository;
use App\Services\MedicalEvents\EncounterPackageBuilder;
use App\Traits\EnsuresEntityExists;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Throwable;

class EncounterCreate extends EncounterComponent
{
    use EnsuresEntityExists;

    private EncounterPackageBuilder $packageBuilder;

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
            Session::flash('error', __('patients.policy.create_encounter'));

            return;
        }

        try {
            $this->syncEncounterParticipants();
            $validated = $this->form->validate();
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

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

        Session::flash('success', __('patients.messages.encounter_created'));

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
            Session::flash('error', __('patients.policy.create_encounter'));

            return;
        }

        $this->syncEncounterParticipants();

        // First validate the encounter data
        try {
            $validatedData = $this->form->validate();
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        // Then validate signing requirements
        try {
            $validated = $this->form->validate($this->form->signingRules());
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $package = $this->packageBuilder->build($validatedData, $this->episodeType);
        $apiData = Arr::toSnakeCase($package);

        if ($this->episodeType === 'new') {
            $this->createEpisode($apiData['episode']);
            unset($apiData['episode']);
        }

        try {
            $signedContent = new CipherRequest()->signData(
                $apiData,
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
            $response = EHealth::encounter()->submit($this->patientUuid, [
                'visit' => [
                    'id' => data_get($apiData, 'encounter.visit.identifier.value'),
                    'period' => data_get($apiData, 'encounter.period')
                ],
                'signed_data' => $signedContent->getBase64Data()
            ]);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while submitting encounter');

            return;
        }

        logger()->debug('Job ID to further debug', $response->getData());

        // eHealth accepted the package; only now persist it locally
        try {
            $this->storeValidatedData($package);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to store validated data');

            return;
        }

        Session::flash('success', __('patients.messages.encounter_created'));

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
     * Set default encounter period date.
     *
     * @return void
     */
    private function setDefaultDate(): void
    {
        $now = CarbonImmutable::now();

        $this->form->encounter['periodDate'] = $now->format(config('app.date_format'));
        $this->form->encounter['periodStart'] = $now->format('H:i');
        $this->form->encounter['periodEnd'] = $now->addMinutes(15)->format('H:i');
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

            if (isset($formattedData['devices'])) {
                Repository::device()->store($formattedData['devices'], $this->patient());
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
}
