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
    use \App\Traits\SubmitsEHealthEncounter;

    private EncounterPackageBuilder $packageBuilder;

    public ?int $prepersonId = null;

    public bool $showReferralRedeemModal = false;
    public string $referralToRedeemUuid = '';
    public string $createdEncounterUuidForRedeem = '';

    private function resolveReferralUuid(string $referralNum): ?string
    {
        if (empty($referralNum) || \Illuminate\Support\Str::isUuid($referralNum)) {
            return $referralNum;
        }

        try {
            $searchResult = \App\Classes\eHealth\EHealth::serviceRequest()->searchForServiceRequestsByParams(['requisition' => $referralNum])->getData();
            if (!empty($searchResult['data']) && is_array($searchResult['data']) && count($searchResult['data']) > 0) {
                $status = $searchResult['data'][0]['status'] ?? '';
                if (!in_array($status, ['active', 'program_processing'])) {
                    $statusName = __('forms.status.' . $status) ?? $status;
                    throw new \Exception("Направлення не дійсне (має статус: $statusName). Для взаємодії потрібне активне направлення.");
                }

                return $searchResult['data'][0]['id'];
            }
        } catch (\Exception $e) {
            throw $e;
        }

        return null;
    }

    private function resolveAllReferrals(array &$validated): void
    {
        if (($validated['encounter']['referralType'] ?? '') === 'electronic' && !empty($validated['encounter']['referralNumber'])) {
            try {
                $uuid = $this->resolveReferralUuid($validated['encounter']['referralNumber']);
                if (!$uuid) {
                    throw new \Exception('Направлення не знайдено в ЕСОЗ');
                }
                $originalNumber = $validated['encounter']['referralNumber'];
                $validated['encounter']['referralNumber'] = $uuid;
                if ($uuid !== $originalNumber) {
                    $validated['encounter']['referralDisplayValue'] = $originalNumber;
                }
            } catch (\Exception $e) {
                $this->addError('form.encounter.referralNumber', $e->getMessage());
                throw $e;
            }
        }

        if (!empty($validated['procedures']) && is_array($validated['procedures'])) {
            foreach ($validated['procedures'] as $index => $procedure) {
                if (($procedure['referralType'] ?? '') === 'electronic' && !empty($procedure['basedOnIdentifier'])) {
                    try {
                        $uuid = $this->resolveReferralUuid($procedure['basedOnIdentifier']);
                        if (!$uuid) {
                            throw new \Exception('Направлення не знайдено в ЕСОЗ');
                        }
                        $validated['procedures'][$index]['basedOnIdentifier'] = $uuid;
                    } catch (\Exception $e) {
                        $this->addError("form.procedures.{$index}.basedOnIdentifier", $e->getMessage());
                        throw $e;
                    }
                }
            }
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

        $this->setDefaultDate();

        // Pre-load in-progress referrals from eHealth for the dropdown
        $this->loadInProgressReferrals();
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
            } catch (\Exception $e) {
                $this->dispatch('scroll-to-error');

                return;
            }
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());
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
        //        dd(EHealth::job()->getDetails('6a99386b23f4bf0046aa4791')->getData());
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
            } catch (\Exception $e) {
                $this->dispatch('scroll-to-error');

                return;
            }
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());
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
            $this->validateProcedurePerformers($formattedData);
            $this->validateObservationPerformers($formattedData);
            $this->validateDiagnosticReportPerformers($formattedData);
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());

            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

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

            Session::flash('success', 'Взаємодію успішно створено та надіслано до ЕСОЗ.');
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
        $encounter = \App\Models\MedicalEvents\Sql\Encounter::where('uuid', $this->createdEncounterUuidForRedeem)->first();
        if ($encounter) {
            $this->redirectAfterCreate($encounter->id);
        } else {
            $this->redirectToPatientEncounters();
        }
    }

    public function redeemReferral(\App\Services\MedicalEvents\ReferralRequestLifecycleService $service): void
    {
        try {
            if ($this->referralToRedeemUuid && $this->createdEncounterUuidForRedeem) {
                $service->completeReferral($this->referralToRedeemUuid, $this->createdEncounterUuidForRedeem);
                Session::flash('success', 'Направлення успішно погашено!');
            }
        } catch (\Exception $e) {
            Session::flash('error', 'Не вдалося погасити направлення: ' . $e->getMessage());
        }

        $this->showReferralRedeemModal = false;

        // Find local encounter ID by UUID to redirect properly
        $encounter = \App\Models\MedicalEvents\Sql\Encounter::where('uuid', $this->createdEncounterUuidForRedeem)->first();
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
