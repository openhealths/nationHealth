<?php

declare(strict_types=1);

namespace App\Livewire\Encounter;

use App\Classes\Cipher\Api\CipherRequest;
use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Exceptions\Cipher\CipherConnectionException;
use App\Exceptions\Cipher\CipherException;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Enums\Person\EpisodeStatus;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\Person\Person;
use App\Models\Preperson;
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
            $validated = $this->form->validate();
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $formattedData = $this->packageBuilder->build($validated, $this->episodeType, EpisodeStatus::DRAFT);

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
     * Submit encrypted data about person encounter.
     *
     * @return void
     */
    public function sign(): void
    {
        if (Auth::user()->cannot('create', Encounter::class)) {
            Session::flash('error', __('patients.policy.create_encounter'));

            return;
        }

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

        $formattedData = $this->packageBuilder->build($validatedData, $this->episodeType);

        try {
            $createdEncounterId = $this->storeValidatedData($formattedData);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to store validated data');

            return;
        }

        $formattedData = Arr::toSnakeCase($formattedData);

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

            Session::flash('success', 'Взаємодію успішно створено та надіслано до ЕСОЗ.');
            $this->showSignatureModal = false;

            if ($this->prepersonId !== null) {
                $this->redirectRoute(
                    'prepersons.encounter.edit',
                    [legalEntity(), 'preperson' => $this->prepersonId, 'encounterId' => $createdEncounterId],
                    navigate: true
                );
            } else {
                $this->redirectRoute(
                    'encounter.edit',
                    [legalEntity(), 'person' => $this->personId, 'encounterId' => $createdEncounterId],
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
            Session::flash('error', __('patients.messages.unexpected_error') ?? 'Виникла непередбачувана помилка.');
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
