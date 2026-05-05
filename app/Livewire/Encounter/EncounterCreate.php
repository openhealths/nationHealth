<?php

declare(strict_types=1);

namespace App\Livewire\Encounter;

use App\Classes\Cipher\Api\CipherRequest;
use App\Classes\Cipher\Exceptions\CipherApiException;
use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\DiagnosticReport;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\MedicalEvents\Sql\Procedure;
use App\Repositories\MedicalEvents\Repository;
use App\Services\MedicalEvents\EncounterPackageBuilder;
use App\Traits\HandlesReasonReferences;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use JsonException;
use Throwable;

class EncounterCreate extends EncounterComponent
{
    use HandlesReasonReferences;

    private EncounterPackageBuilder $packageBuilder;

    public function boot(): void
    {
        parent::boot();
        $this->packageBuilder = app(EncounterPackageBuilder::class);
    }

    public function mount(LegalEntity $legalEntity, int $personId): void
    {
        $this->initializeComponent($personId);

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

        // Prepare and format data after validation
        $formattedData = $this->prepareFormattedData($validated);

        try {
            $encounterId = $this->storeValidatedData($formattedData);
        } catch (Throwable $exception) {
            $this->logDatabaseErrors($exception, 'Failed to store validated data');
            Session::flash('error', __('messages.database_error'));

            return;
        }

        Session::flash('success', __('patients.messages.encounter_created'));
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

        $formattedData = $this->prepareFormattedData($validatedData);

        try {
            $this->storeValidatedData($formattedData);
        } catch (Throwable $exception) {
            $this->logDatabaseErrors($exception, 'Failed to store validated data');
            Session::flash('error', __('messages.database_error'));

            return;
        }

        $formattedData = Arr::toSnakeCase($formattedData);

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

        try {
            $resp = EHealth::encounter()->submit($this->patientUuid, [
                'visit' => [
                    'id' => data_get($formattedData, 'encounter.visit.identifier.value'),
                    'period' => data_get($formattedData, 'encounter.period')
                ],
                'signed_data' => $signedContent->getBase64Data()
            ]);

            logger()->debug('Job ID to further debug', $resp->getData());
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error while submitting encounter');

            return;
        }

        $this->redirectRoute('persons.index', [legalEntity()], navigate: true);
    }

    /**
     * Set default encounter period date.
     *
     * @return void
     */
    private function setDefaultDate(): void
    {
        $now = CarbonImmutable::now();

        $this->form->encounter['periodDate'] = $now->format('Y-m-d');
        $this->form->encounter['periodStart'] = $now->format('H:i');
        $this->form->encounter['periodEnd'] = $now->addMinutes(15)->format('H:i');
    }

    /**
     * Prepare formatted data.
     *
     * @param  array  $validated
     * @return array
     */
    protected function prepareFormattedData(array $validated): array
    {
        $package = $this->packageBuilder->build($validated, $this->episodeType);

        $encounterRepository = Repository::encounter();

        if (!empty($this->form->observations)) {
            $package['observations'] = $encounterRepository->formatObservationsRequest($this->form->observations);
        }

        if (!empty($this->form->procedures)) {
            $package['procedures'] = $encounterRepository->formatProceduresRequest($this->form->procedures);
        }

        if (!empty($this->form->clinicalImpressions)) {
            $package['clinicalImpressions'] = $encounterRepository->formatClinicalImpressionsRequest(
                $this->form->clinicalImpressions
            );
        }

        return $package;
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
            $createdEncounterId = Repository::encounter()->store($formattedData['encounter'], $this->personId);

            if (isset($formattedData['episode'])) {
                Repository::episode()->store($formattedData['episode'], $this->personId, $createdEncounterId);
            }

            if (isset($formattedData['conditions'])) {
                Repository::condition()->store($formattedData['conditions'], $createdEncounterId, $this->personId);
            }

            if (isset($formattedData['immunizations'])) {
                Repository::immunization()->store($formattedData['immunizations'], $this->personId);
            }

            if (isset($formattedData['diagnosticReports'])) {
                Repository::diagnosticReport()->store($formattedData['diagnosticReports'], $this->personId);
            }

            if (isset($formattedData['observations'])) {
                Repository::observation()->store(
                    $formattedData['observations'],
                    $this->personId,
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
                    $this->personId,
                    $createdEncounterId
                );

                // Save the selected episode_of_care, procedure, diagnostic_report, encounter locally if they don't exist in our database.
                foreach ($formattedData['clinicalImpressions'] as $clinicalImpression) {
                    $this->processSupportingInfo($clinicalImpression);
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
                Repository::episode()->store([Arr::toCamelCase($episodeData)], $this->personId);
            } catch (Throwable $exception) {
                $this->logDatabaseErrors($exception, 'Failed to store episode');
                Session::flash('error', __('messages.database_error'));

                return;
            }
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Failed while ensuring episode existence');

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
