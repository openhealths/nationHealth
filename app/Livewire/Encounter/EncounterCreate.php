<?php

declare(strict_types=1);

namespace App\Livewire\Encounter;

use App\Classes\Cipher\Api\CipherRequest;
use App\Classes\Cipher\Exceptions\CipherApiException;
use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Livewire\Encounter\Forms\Api\EncounterRequestApi;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\DiagnosticReport;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\MedicalEvents\Sql\Procedure;
use App\Repositories\MedicalEvents\Repository;
use App\Traits\HandlesReasonReferences;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use JsonException;
use Throwable;

class EncounterCreate extends EncounterComponent
{
    use HandlesReasonReferences;

    public function mount(LegalEntity $legalEntity, int $id): void
    {
        $this->initializeComponent($id);

        $uuid = Auth::user()->party->employees()->whereStatus('APPROVED')->first()->uuid;

        $this->form->encounter['performer']['identifier']['value'] = $uuid;
        $this->form->episode['careManager']['identifier']['value'] = $uuid;

        $this->setDefaultDate();
    }

    /**
     * Validate and save data.
     *
     * @return void
     * @throws Throwable
     */
    public function save(): void
    {
        if (Auth::user()->cannot('create', Encounter::class)) {
            Session::flash('error', 'У вас немає дозволу на створення взаємодії.');

            return;
        }

        try {
            $this->form->validate();
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        // Prepare and format data after validation
        $formattedData = $this->prepareFormattedData();

        $this->storeValidatedData($formattedData);
    }

    /**
     * Submit encrypted data about person encounter.
     *
     * @return void
     * @throws Throwable
     */
    public function sign(): void
    {
        if (Auth::user()->cannot('create', Encounter::class)) {
            Session::flash('error', 'У вас немає дозволу на створення взаємодії.');

            return;
        }

        // First validate the encounter data
        try {
            $this->form->validate();
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

        $formattedData = $this->prepareFormattedData();
        $formattedData = Arr::toSnakeCase($formattedData);

        $this->storeValidatedData($formattedData);

        if ($this->episodeType === 'new') {
            $this->createEpisode($formattedData['episode']);
        }

        try {
            $signedContent = new CipherRequest()->signData(
                $formattedData,
                $validated['knedp'],
                $validated['keyContainerUpload'],
                $validated['password'],
                Auth::user()->party->taxId
            );
        } catch (ConnectionException|CipherApiException|JsonException $exception) {
            $this->handleCipherExceptions($exception, 'Error when signing data with Cipher');

            return;
        }

        $signedSubmitEncounter = EncounterRequestApi::buildSubmitEncounterPackage(
            $formattedData,
            $signedContent->getBase64Data()
        );

        try {
            $resp = EHealth::encounter()->submit($this->patientUuid, $signedSubmitEncounter);

            dd($resp->getData());
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error while submitting encounter');

            return;
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

        $this->form->encounter['period'] = [
            'start' => $now->format('H:i'),
            'end' => $now->addMinutes(15)->format('H:i')
        ];
    }

    /**
     * Prepare formatted data.
     *
     * @return array
     */
    protected function prepareFormattedData(): array
    {
        $encounterRepository = Repository::encounter();

        $data = [
            'encounter' => $encounterRepository->formatEncounterRequest(
                $this->form->encounter,
                $this->form->conditions,
                $this->episodeType === 'new'
            ),
            'episode' => $this->episodeType === 'new'
                ? $encounterRepository->formatEpisodeRequest($this->form->episode, $this->form->encounter['period'])
                : [],
            'conditions' => $encounterRepository->formatConditionsRequest($this->form->conditions),
            'immunizations' => !empty($this->form->immunizations)
                ? $encounterRepository->formatImmunizationsRequest($this->form->immunizations)
                : [],
            'diagnosticReports' => !empty($this->form->diagnosticReports)
                ? $encounterRepository->formatDiagnosticReportsRequest(
                    $this->form->diagnosticReports,
                    $this->form->encounter['division']['identifier']['value'] ?? null
                )
                : [],
            'observations' => !empty($this->form->observations)
                ? $encounterRepository->formatObservationsRequest($this->form->observations)
                : [],
            'procedures' => !empty($this->form->procedures)
                ? $encounterRepository->formatProceduresRequest($this->form->procedures)
                : [],
            'clinicalImpressions' => !empty($this->form->clinicalImpressions)
                ? $encounterRepository->formatClinicalImpressionsRequest($this->form->clinicalImpressions)
                : []
        ];

        // Remove empty
        return array_filter($data);
    }

    /**
     * Store validated formatted data into DB.
     *
     * @param  array  $formattedData
     * @return void
     * @throws Throwable
     */
    protected function storeValidatedData(array $formattedData): void
    {
        try {
            DB::transaction(function () use ($formattedData) {
                $createdEncounterId = Repository::encounter()->store($formattedData['encounter'], $this->patientId);

                if (isset($formattedData['episode'])) {
                    Repository::episode()->store($formattedData['episode'], $this->patientId, $createdEncounterId);
                }

                Repository::condition()->store($formattedData['conditions'], $createdEncounterId);

                if (isset($formattedData['immunizations'])) {
                    Repository::immunization()->store(
                        $formattedData['immunizations'],
                        $this->patientId,
                        $createdEncounterId
                    );
                }

                if (isset($formattedData['diagnosticReports'])) {
                    Repository::diagnosticReport()->store($formattedData['diagnosticReports'], $createdEncounterId);
                }

                if (isset($formattedData['observations'])) {
                    Repository::observation()->store(
                        $formattedData['observations'],
                        $this->patientId,
                        $createdEncounterId
                    );
                }

                if (isset($formattedData['procedures'])) {
                    Repository::procedure()->store($formattedData['procedures'], $createdEncounterId);

                    // Save the selected condition and observation locally if they don't exist in our database.
                    foreach ($formattedData['procedures'] as $procedure) {
                        $this->processReasonReferences($procedure);
                        $this->processComplicationDetails($procedure);
                    }
                }

                if (isset($formattedData['clinicalImpressions'])) {
                    Repository::clinicalImpression()->store(
                        $formattedData['clinicalImpressions'],
                        $this->patientId,
                        $createdEncounterId
                    );

                    // Save the selected episode_of_care, procedure, diagnostic_report, encounter locally if they don't exist in our database.
                    foreach ($formattedData['clinicalImpressions'] as $clinicalImpression) {
                        $this->processSupportingInfo($clinicalImpression);
                    }
                }
            });
        } catch (Throwable $e) {
            Log::channel('db_errors')->error('Failed to store validated data', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            Session::flash('error', 'Виникла помилка. Зверніться до адміністратора.');

            return;
        }
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
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when sign person request');

            return;
        }
    }

    /**
     * Handles details of procedure complications
     *
     * @param  array  $procedure
     * @return void
     */
    private function processComplicationDetails(array $procedure): void
    {
        if (!isset($procedure['complicationDetails'])) {
            return;
        }

        foreach ($procedure['complicationDetails'] as $complicationDetail) {
            $this->ensureConditionExists($complicationDetail['identifier']['value']);
        }
    }

    /**
     * Process supporting info of clinical impression.
     *
     * @param  array  $clinicalImpression
     * @return void
     */
    private function processSupportingInfo(array $clinicalImpression): void
    {
        if (!isset($clinicalImpression['supportingInfo'])) {
            return;
        }

        foreach ($clinicalImpression['supportingInfo'] as $supportingInfo) {
            if ($supportingInfo['identifier']['type']['coding'][0]['code'] === 'episode_of_care') {
                $this->ensureEpisodeExists($supportingInfo['identifier']['value']);
            }

            if ($supportingInfo['identifier']['type']['coding'][0]['code'] === 'procedure') {
                $this->ensureProcedureExists($supportingInfo['identifier']['value']);
            }

            if ($supportingInfo['identifier']['type']['coding'][0]['code'] === 'diagnostic_report') {
                $this->ensureDiagnosticReportExists($supportingInfo['identifier']['value']);
            }

            if ($supportingInfo['identifier']['type']['coding'][0]['code'] === 'encounter') {
                $this->ensureEncounterExist($supportingInfo['identifier']['value']);
            }
        }
    }

    /**
     * Search for episode and save if not founded in our DB.
     *
     * @param  string  $uuid
     * @return void
     */
    private function ensureEpisodeExists(string $uuid): void
    {
        if (Episode::whereUuid($uuid)->exists()) {
            return;
        }

        try {
            $episodeData = EHealth::episode()->getById($this->patientUuid, $uuid)->getData();

            try {
                Repository::episode()->store(Arr::toCamelCase($episodeData), $this->patientId);
            } catch (Throwable $exception) {
                $this->logDatabaseErrors($exception, 'Failed to store episode');
                Session::flash('error', __('messages.database_error'));

                return;
            }
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Failed while ensuring diagnostic report existence');

            return;
        }
    }

    /**
     * Search for procedure and save if not founded in our DB.
     *
     * @param  string  $uuid
     * @return void
     */
    private function ensureProcedureExists(string $uuid): void
    {
        if (Procedure::whereUuid($uuid)->exists()) {
            return;
        }

        try {
            $procedureData = EHealth::procedure()->getById($this->patientUuid, $uuid)->getData();

            try {
                Repository::procedure()->store([Arr::toCamelCase($procedureData)]);
            } catch (Throwable $exception) {
                $this->logDatabaseErrors($exception, 'Failed to store procedure');
                Session::flash('error', __('messages.database_error'));

                return;
            }
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Failed while ensuring procedure existence');

            return;
        }
    }

    /**
     * Search for diagnostic report and save if not founded in our DB.
     *
     * @param  string  $uuid
     * @return void
     */
    private function ensureDiagnosticReportExists(string $uuid): void
    {
        if (DiagnosticReport::whereUuid($uuid)->exists()) {
            return;
        }

        try {
            $diagnosticReportData = EHealth::diagnosticReport()->getById($this->patientUuid, $uuid)->getData();

            try {
                Repository::diagnosticReport()->store([Arr::toCamelCase($diagnosticReportData)]);
            } catch (Throwable $exception) {
                $this->logDatabaseErrors($exception, 'Failed to store diagnostic report');
                Session::flash('error', __('messages.database_error'));

                return;
            }
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Failed while ensuring diagnostic report existence');

            return;
        }
    }
}
