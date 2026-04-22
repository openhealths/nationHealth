<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\JobStatus;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Jobs\EpisodeSync;
use App\Jobs\EncounterShortSync;
use App\Jobs\ClinicalImpressionSync;
use App\Jobs\ImmunizationSync;
use App\Jobs\ObservationSync;
use App\Jobs\ConditionSync;
use App\Jobs\DiagnosticReportSync;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\ClinicalImpression;
use App\Models\MedicalEvents\Sql\Condition;
use App\Models\MedicalEvents\Sql\DiagnosticReport;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\MedicalEvents\Sql\Immunization;
use App\Models\MedicalEvents\Sql\Observation;
use App\Models\User;
use App\Notifications\SyncNotification;
use App\Repositories\MedicalEvents\Repository;
use App\Traits\BatchLegalEntityQueries;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use InvalidArgumentException;
use Throwable;

class PatientSummary extends BasePatientComponent
{
    use BatchLegalEntityQueries;

    public const string ENTITY_TYPE_EPISODE = 'episode';
    public const string ENTITY_TYPE_ENCOUNTER = 'encounter';
    public const string ENTITY_TYPE_CLINICAL_IMPRESSION = 'clinical_impression';
    public const string ENTITY_TYPE_IMMUNIZATION = 'immunization';
    public const string ENTITY_TYPE_OBSERVATION = 'observation';
    public const string ENTITY_TYPE_CONDITION = 'condition';
    public const string ENTITY_TYPE_DIAGNOSTIC_REPORT = 'diagnostic_report';

    public array $episodes = [];

    public array $encounters = [];

    public array $clinicalImpressions = [];

    public array $immunizations = [];

    public array $observations = [];

    public array $diagnoses = [];

    public array $conditions = [];

    public array $diagnosticReports = [];

    public array $allergyIntolerances;

    public array $riskAssessments;

    public array $devices;

    public array $medicationStatements;

    /**
     * Stores synchronization statuses for all entity types.
     *
     * @var array
     */
    public array $syncStatuses = [];

    protected array $dictionaryNames = [
        'eHealth/encounter_classes',
        'eHealth/encounter_types',
        'SPECIALITY_TYPE',
        'eHealth/clinical_impression_patient_categories',
        'eHealth/vaccine_codes',
        'eHealth/vaccination_routes',
        'eHealth/reason_explanations',
        'eHealth/immunization_body_sites',
        'eHealth/observation_categories',
        'eHealth/ICF/observation_categories',
        'eHealth/LOINC/observation_codes',
        'eHealth/report_origins',
        'eHealth/observation_methods',
        'eHealth/observation_interpretations',
        'eHealth/body_sites',
        'eHealth/ICPC2/condition_codes',
        'eHealth/ICD10/condition_codes',
        'eHealth/condition_severities',
        'eHealth/diagnostic_report_categories',
    ];

    /**
     * Generic method to check if any entity is currently syncing.
     *
     * @param  string  $entityConstant  The entity constant from LegalEntity class (e.g., 'episode')
     * @return bool
     */
    public function isEntitySyncing(string $entityConstant): bool
    {
        return $this->isSyncProcessing($entityConstant);
    }

    protected function initializeComponent(): void
    {
        $this->getDictionary();

        $this->dictionaries['eHealth/ICF/classifiers'] = dictionary()->basics()
            ->byName('eHealth/ICF/classifiers')
            ->flattenedChildValues()
            ->toArray();

        // Initialize sync statuses for all entities
        $this->syncStatuses = [
            self::ENTITY_TYPE_EPISODE => legalEntity()->getEntityStatus(LegalEntity::ENTITY_EPISODE),
            self::ENTITY_TYPE_ENCOUNTER => legalEntity()->getEntityStatus(LegalEntity::ENTITY_ENCOUNTER),
            self::ENTITY_TYPE_CLINICAL_IMPRESSION => legalEntity()->getEntityStatus(LegalEntity::ENTITY_CLINICAL_IMPRESSION),
            self::ENTITY_TYPE_IMMUNIZATION => legalEntity()->getEntityStatus(LegalEntity::ENTITY_IMMUNIZATION),
            self::ENTITY_TYPE_OBSERVATION => legalEntity()->getEntityStatus(LegalEntity::ENTITY_OBSERVATION),
            self::ENTITY_TYPE_CONDITION => legalEntity()->getEntityStatus(LegalEntity::ENTITY_CONDITION),
            self::ENTITY_TYPE_DIAGNOSTIC_REPORT => legalEntity()->getEntityStatus(LegalEntity::ENTITY_DIAGNOSTIC_REPORT),
        ];
    }

    /**
     * Sync patient episodes from eHealth API to database.
     *
     * @return void
     */
    public function syncEpisodes(): void
    {
        if ($this->cannotStartSync(self::ENTITY_TYPE_EPISODE)) {
            return;
        }

        if ($this->shouldResumeSync(self::ENTITY_TYPE_EPISODE)) {
            $this->handleResumeLogic(self::ENTITY_TYPE_EPISODE, LegalEntity::ENTITY_EPISODE);

            return;
        }

        try {
            $response = EHealth::episode()->getShortEpisodes($this->uuid);
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error while synchronizing episodes');

            return;
        }

        try {
            $validatedData = $response->validate();
            Repository::episode()->sync($this->personId, $validatedData);
        } catch (Throwable $exception) {
            $this->logDatabaseErrors($exception, 'Error while synchronizing episodes');
            Session::flash('error', __('patients.messages.episode_sync_database_error'));

            return;
        }

        if ($response->isNotLast()) {
            $this->dispatchRemainingPages(self::ENTITY_TYPE_EPISODE);
        } else {
            legalEntity()->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_EPISODE);
            Session::flash('success', __('patients.messages.episodes_synced_successfully'));
        }

        $this->episodes = Arr::toCamelCase($this->formatDatesForDisplay($validatedData));
    }

    public function getEpisodes(): void
    {
        $this->episodes = Episode::with('period')->wherePersonId($this->personId)->get()->toArray();
    }

    public function syncEncounters(): void
    {
        if ($this->cannotStartSync(self::ENTITY_TYPE_ENCOUNTER)) {
            return;
        }

        if ($this->shouldResumeSync(self::ENTITY_TYPE_ENCOUNTER)) {
            $this->handleResumeLogic(self::ENTITY_TYPE_ENCOUNTER, LegalEntity::ENTITY_ENCOUNTER);

            return;
        }

        try {
            $response = EHealth::encounter()->getShortBySearchParams($this->uuid);
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error while synchronizing encounters');

            return;
        }

        try {
            $validatedData = $response->validate();
            Repository::encounter()->sync($this->personId, $validatedData);
        } catch (Throwable $exception) {
            $this->logDatabaseErrors($exception, 'Error while synchronizing encounters');
            Session::flash('error', __('patients.messages.encounter_sync_database_error'));

            return;
        }

        if ($response->isNotLast()) {
            $this->dispatchRemainingPages(self::ENTITY_TYPE_ENCOUNTER);
        } else {
            legalEntity()->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_ENCOUNTER);
            Session::flash('success', __('patients.messages.encounters_synced_successfully'));
        }

        $this->encounters = Arr::toCamelCase($this->formatDatesForDisplay($validatedData));
    }

    public function getEncounters(): void
    {
        $this->encounters = Encounter::wherePersonId($this->personId)
            ->with(['class', 'episode.type.coding', 'type.coding', 'period', 'performerSpeciality.coding'])
            ->get()
            ->toArray();
    }

    public function syncClinicalImpressions(): void
    {
        if ($this->cannotStartSync(self::ENTITY_TYPE_CLINICAL_IMPRESSION)) {
            return;
        }

        if ($this->shouldResumeSync(self::ENTITY_TYPE_CLINICAL_IMPRESSION)) {
            $this->handleResumeLogic(self::ENTITY_TYPE_CLINICAL_IMPRESSION, LegalEntity::ENTITY_CLINICAL_IMPRESSION);

            return;
        }

        try {
            $response = EHealth::clinicalImpression()->getSummary($this->uuid);
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error while synchronizing clinical impressions');

            return;
        }

        try {
            $validatedData = $response->validate();
            Repository::clinicalImpression()->sync($this->personId, $validatedData);
        } catch (Throwable $exception) {
            $this->logDatabaseErrors($exception, 'Error while synchronizing clinical impressions');
            Session::flash('error', __('patients.messages.clinical_impression_sync_database_error'));

            return;
        }

        if ($response->isNotLast()) {
            $this->dispatchRemainingPages(self::ENTITY_TYPE_CLINICAL_IMPRESSION);
        } else {
            legalEntity()->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_CLINICAL_IMPRESSION);
            Session::flash('success', __('patients.messages.clinical_impressions_synced_successfully'));
        }

        $this->clinicalImpressions = Arr::toCamelCase($this->formatDatesForDisplay($validatedData));
    }

    public function getClinicalImpressions(): void
    {
        $this->clinicalImpressions = ClinicalImpression::wherePersonId($this->personId)
            ->withAllRelations()
            ->get()
            ->toArray();
    }

    public function syncImmunizations(): void
    {
        if ($this->cannotStartSync(self::ENTITY_TYPE_IMMUNIZATION)) {
            return;
        }

        if ($this->shouldResumeSync(self::ENTITY_TYPE_IMMUNIZATION)) {
            $this->handleResumeLogic(self::ENTITY_TYPE_IMMUNIZATION, LegalEntity::ENTITY_IMMUNIZATION);

            return;
        }

        try {
            $response = EHealth::immunization()->getSummary($this->uuid);
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error while synchronizing immunizations');

            return;
        }

        try {
            $validatedData = $response->validate();
            Repository::immunization()->sync($this->personId, $validatedData);
        } catch (Throwable $exception) {
            $this->logDatabaseErrors($exception, 'Error while synchronizing immunizations');
            Session::flash('error', __('patients.messages.immunization_sync_database_error'));

            return;
        }

        if ($response->isNotLast()) {
            $this->dispatchRemainingPages(self::ENTITY_TYPE_IMMUNIZATION);
        } else {
            legalEntity()->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_IMMUNIZATION);
            Session::flash('success', __('patients.messages.immunizations_synced_successfully'));
        }

        $this->immunizations = Arr::toCamelCase($this->formatDatesForDisplay($validatedData));
    }

    public function getImmunizations(): void
    {
        $this->immunizations = Immunization::wherePersonId($this->personId)
            ->withAllRelations()
            ->get()
            ->toArray();
    }

    public function syncObservations(): void
    {
        if ($this->cannotStartSync(self::ENTITY_TYPE_OBSERVATION)) {
            return;
        }

        if ($this->shouldResumeSync(self::ENTITY_TYPE_OBSERVATION)) {
            $this->handleResumeLogic(self::ENTITY_TYPE_OBSERVATION, LegalEntity::ENTITY_OBSERVATION);

            return;
        }

        try {
            $response = EHealth::observation()->getBySearchParams(
                $this->uuid,
                ['managing_organization_id' => legalEntity()->uuid]
            );
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error while synchronizing observations');

            return;
        }

        try {
            $validatedData = $response->validate();
            Repository::observation()->sync($this->personId, $validatedData);
        } catch (Throwable $exception) {
            $this->logDatabaseErrors($exception, 'Error while synchronizing observations');
            Session::flash('error', __('patients.messages.observation_sync_database_error'));

            return;
        }

        if ($response->isNotLast()) {
            $this->dispatchRemainingPages(self::ENTITY_TYPE_OBSERVATION);
        } else {
            legalEntity()->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_OBSERVATION);
            Session::flash('success', __('patients.messages.observations_synced_successfully'));
        }

        $this->observations = Arr::toCamelCase($this->formatDatesForDisplay($validatedData));
    }

    public function getObservations(): void
    {
        $this->observations = Observation::wherePersonId($this->personId)
            ->withAllRelations()
            ->get()
            ->toArray();
    }

    public function syncDiagnoses(): void
    {
        try {
            $response = EHealth::patient()->getActiveDiagnoses($this->uuid);

            // Refresh data for display
            $this->diagnoses = Arr::toCamelCase($this->formatDatesForDisplay($response->getData()));
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when getting diagnoses');

            return;
        }
    }

    public function getDiagnoses(): void
    {
        //
    }

    public function syncConditions(): void
    {
        if ($this->cannotStartSync(self::ENTITY_TYPE_CONDITION)) {
            return;
        }

        if ($this->shouldResumeSync(self::ENTITY_TYPE_CONDITION)) {
            $this->handleResumeLogic(self::ENTITY_TYPE_CONDITION, LegalEntity::ENTITY_CONDITION);

            return;
        }

        try {
            $response = EHealth::condition()->getBySearchParams(
                $this->uuid,
                ['managing_organization_id' => legalEntity()->uuid]
            );
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error while synchronizing conditions');

            return;
        }

        try {
            $validatedData = $response->validate();
            Repository::condition()->sync($this->personId, $validatedData);
        } catch (Throwable $exception) {
            $this->logDatabaseErrors($exception, 'Error while synchronizing conditions');
            Session::flash('error', __('patients.messages.condition_sync_database_error'));

            return;
        }

        if ($response->isNotLast()) {
            $this->dispatchRemainingPages(self::ENTITY_TYPE_CONDITION);
        } else {
            legalEntity()->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_CONDITION);
            Session::flash('success', __('patients.messages.conditions_synced_successfully'));
        }

        $this->conditions = Arr::toCamelCase($this->formatDatesForDisplay($validatedData));
    }

    public function getConditions(): void
    {
        $this->conditions = Condition::wherePersonId($this->personId)
            ->withAllRelations()
            ->get()
            ->toArray();
    }

    public function syncDiagnosticReports(): void
    {
        if ($this->cannotStartSync(self::ENTITY_TYPE_DIAGNOSTIC_REPORT)) {
            return;
        }

        if ($this->shouldResumeSync(self::ENTITY_TYPE_DIAGNOSTIC_REPORT)) {
            $this->handleResumeLogic(self::ENTITY_TYPE_DIAGNOSTIC_REPORT, LegalEntity::ENTITY_DIAGNOSTIC_REPORT);

            return;
        }

        try {
            $response = EHealth::diagnosticReport()->getBySearchParams(
                $this->uuid,
                ['managing_organization_id' => legalEntity()->uuid]
            );
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error while synchronizing diagnostic reports');

            return;
        }

        try {
            $validatedData = $response->validate();
            Repository::diagnosticReport()->sync($this->personId, $validatedData);
        } catch (Throwable $exception) {
            $this->logDatabaseErrors($exception, 'Error while synchronizing diagnostic reports');
            Session::flash('error', __('patients.messages.diagnostic_report_sync_database_error'));

            return;
        }

        if ($response->isNotLast()) {
            $this->dispatchRemainingPages(self::ENTITY_TYPE_DIAGNOSTIC_REPORT);
        } else {
            legalEntity()->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_DIAGNOSTIC_REPORT);
            Session::flash('success', __('patients.messages.diagnostic_reports_synced_successfully'));
        }

        $this->diagnosticReports = Arr::toCamelCase($this->formatDatesForDisplay($validatedData));
    }

    public function getDiagnosticReports(): void
    {
        $this->diagnosticReports = DiagnosticReport::wherePersonId($this->personId)
            ->withAllRelations()
            ->get()
            ->toArray();
    }

    public function syncAllergyIntolerances(): void
    {
        try {
            $response = EHealth::patient()->getAllergyIntolerances($this->uuid);
            $validatedData = $response->validate();
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when getting allergy intolerances');

            return;
        }
    }

    public function syncRiskAssessments(): void
    {
        try {
            $response = EHealth::patient()->getRiskAssessments($this->uuid);
            $validatedData = $response->validate();
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when getting risk assessments');

            return;
        }
    }

    public function syncDevices(): void
    {
        try {
            $response = EHealth::patient()->getDevices($this->uuid);
            $validatedData = $response->validate();
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when getting devices');

            return;
        }
    }

    public function syncMedicationStatements(): void
    {
        try {
            $response = EHealth::patient()->getMedicationStatements($this->uuid);
            $validatedData = $response->validate();
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when getting medication statements');

            return;
        }
    }

    /**
     * Generic method to check if any entity is currently syncing.
     *
     * @param  string  $entityType
     * @return bool
     */
    protected function isSyncProcessing(string $entityType): bool
    {
        $batchName = $this->getBatchName($entityType);
        $runningBatches = $this->findRunningBatchesByLegalEntity(legalEntity()->id);

        return $runningBatches->where('name', $batchName . '_' . $this->uuid)->isNotEmpty();
    }

    /**
     * Check if sync cannot be started (already running).
     *
     * @param  string  $entityType
     * @return bool
     */
    private function cannotStartSync(string $entityType): bool
    {
        if ($this->isSyncProcessing($entityType)) {
            Session::flash('error', __('patients.messages.' . $entityType . '_sync_already_running'));

            return true;
        }

        return false;
    }

    /**
     * Check if sync should be resumed.
     *
     * @param  string  $entityType
     * @return bool
     */
    private function shouldResumeSync(string $entityType): bool
    {
        return $this->syncStatuses[$entityType] === JobStatus::PAUSED->value
            || $this->syncStatuses[$entityType] === JobStatus::FAILED->value;
    }

    /**
     * Handle resume logic for both episode and encounter syncs.
     *
     * @param  string  $entityType
     * @param  string  $entityConstant
     * @return void
     */
    private function handleResumeLogic(string $entityType, string $entityConstant): void
    {
        $user = Auth::user();
        $token = Session::get(config('ehealth.api.oauth.bearer_token'));

        $this->resumeSynchronization($entityType, $entityConstant, $user, $token);
        Session::flash('success', __('patients.messages.' . $entityType . '_sync_resume_started'));
        $user->notify(new SyncNotification($entityType, 'resumed'));
    }

    /**
     * Dispatch background jobs for remaining pages.
     *
     * @param  string  $entityType
     * @return void
     */
    private function dispatchRemainingPages(string $entityType): void
    {
        $user = Auth::user();
        $token = Session::get(config('ehealth.api.oauth.bearer_token'));

        try {
            $user->notify(new SyncNotification($entityType, 'started'));
            $this->dispatchNextJobs($entityType, $user, $token);
            Session::flash('success', __('patients.messages.' . $entityType . 's_first_page_synced_successfully'));
        } catch (Throwable $exception) {
            Log::error('Failed to dispatch ' . ucfirst($entityType) . 'Sync batch', ['exception' => $exception]);
            $user->notify(new SyncNotification($entityType, 'failed'));
            Session::flash('error', __('patients.messages.' . $entityType . '_sync_background_dispatch_error'));
        }
    }

    /**
     * Generic method to resume synchronization.
     *
     * @param  string  $entityType
     * @param  string  $entityConstant
     * @param  User  $user
     * @param  string  $token
     * @return void
     */
    private function resumeSynchronization(string $entityType, string $entityConstant, User $user, string $token): void
    {
        $encryptedToken = Crypt::encryptString($token);
        $batchName = $this->getBatchName($entityType);

        $failedBatches = $this->findFailedBatchesByLegalEntity(legalEntity()->id, 'ASC');

        foreach ($failedBatches as $batch) {
            if ($batch->name === $batchName . '_' . $this->uuid) {
                Log::info('Resuming ' . ucfirst($entityType) . ' sync batch: ' . $batch->name . ' id: ' . $batch->id);

                legalEntity()->setEntityStatus(JobStatus::PROCESSING, $entityConstant);
                $this->restartBatch($batch, $user, $encryptedToken, legalEntity());
                break;
            }
        }
    }

    /**
     * Dispatch next sync jobs for remaining pages.
     *
     * @param  string  $entityType
     * @param  User  $user
     * @param  string  $token
     * @return void
     * @throws Throwable
     */
    private function dispatchNextJobs(string $entityType, User $user, string $token): void
    {
        $batchName = $this->getBatchName($entityType);
        $jobClass = $this->getJobClass($entityType);
        $entityConstant = $this->getEntityConstant($entityType);

        Bus::batch([new $jobClass(legalEntity(), page: 2)])
            ->withOption('legal_entity_id', legalEntity()->id)
            ->withOption('token', Crypt::encryptString($token))
            ->withOption('user', $user)
            ->withOption('patient_uuid', $this->uuid)
            ->withOption('person_id', $this->personId)
            ->then(fn () => $user->notify(new SyncNotification($entityType, 'completed')))
            ->catch(function (Batch $batch, Throwable $exception) use ($user, $entityType) {
                Log::error(ucfirst($entityType) . ' sync batch failed.', [
                    'batch_id' => $batch->id,
                    'patient_uuid' => $this->uuid,
                    'exception' => $exception
                ]);

                $user->notify(new SyncNotification($entityType, 'failed'));
            })
            ->onQueue('sync')
            ->name($batchName . '_' . $this->uuid)
            ->dispatch();

        legalEntity()->setEntityStatus(JobStatus::PROCESSING, $entityConstant);
    }

    /**
     * Get batch name for entity type.
     *
     * @param  string  $entityType
     * @return string
     */
    private function getBatchName(string $entityType): string
    {
        return match ($entityType) {
            self::ENTITY_TYPE_EPISODE => EpisodeSync::BATCH_NAME,
            self::ENTITY_TYPE_ENCOUNTER => EncounterShortSync::BATCH_NAME,
            self::ENTITY_TYPE_CLINICAL_IMPRESSION => ClinicalImpressionSync::BATCH_NAME,
            self::ENTITY_TYPE_IMMUNIZATION => ImmunizationSync::BATCH_NAME,
            self::ENTITY_TYPE_OBSERVATION => ObservationSync::BATCH_NAME,
            self::ENTITY_TYPE_CONDITION => ConditionSync::BATCH_NAME,
            self::ENTITY_TYPE_DIAGNOSTIC_REPORT => DiagnosticReportSync::BATCH_NAME,
            default => throw new InvalidArgumentException('Unknown entity type: ' . $entityType),
        };
    }

    /**
     * Get job class for entity type.
     *
     * @param  string  $entityType
     * @return string
     */
    private function getJobClass(string $entityType): string
    {
        return match ($entityType) {
            self::ENTITY_TYPE_EPISODE => EpisodeSync::class,
            self::ENTITY_TYPE_ENCOUNTER => EncounterShortSync::class,
            self::ENTITY_TYPE_CLINICAL_IMPRESSION => ClinicalImpressionSync::class,
            self::ENTITY_TYPE_IMMUNIZATION => ImmunizationSync::class,
            self::ENTITY_TYPE_OBSERVATION => ObservationSync::class,
            self::ENTITY_TYPE_CONDITION => ConditionSync::class,
            self::ENTITY_TYPE_DIAGNOSTIC_REPORT => DiagnosticReportSync::class,
            default => throw new InvalidArgumentException('Unknown entity type: ' . $entityType),
        };
    }

    /**
     * Get entity constant for entity type.
     *
     * @param  string  $entityType
     * @return string
     */
    private function getEntityConstant(string $entityType): string
    {
        return match ($entityType) {
            self::ENTITY_TYPE_EPISODE => LegalEntity::ENTITY_EPISODE,
            self::ENTITY_TYPE_ENCOUNTER => LegalEntity::ENTITY_ENCOUNTER,
            self::ENTITY_TYPE_CLINICAL_IMPRESSION => LegalEntity::ENTITY_CLINICAL_IMPRESSION,
            self::ENTITY_TYPE_IMMUNIZATION => LegalEntity::ENTITY_IMMUNIZATION,
            self::ENTITY_TYPE_OBSERVATION => LegalEntity::ENTITY_OBSERVATION,
            self::ENTITY_TYPE_CONDITION => LegalEntity::ENTITY_CONDITION,
            self::ENTITY_TYPE_DIAGNOSTIC_REPORT => LegalEntity::ENTITY_DIAGNOSTIC_REPORT,
            default => throw new InvalidArgumentException('Unknown entity type: ' . $entityType),
        };
    }

    public function render(): View
    {
        return view('livewire.person.records.summary');
    }
}
