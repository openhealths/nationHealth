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
use App\Repositories\MedicalEvents\Repository;
use App\Traits\BatchLegalEntityQueries;
use App\Traits\HandlesSyncBatch;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Session;
use InvalidArgumentException;
use Throwable;

class PatientSummary extends BasePatientComponent
{
    use BatchLegalEntityQueries;
    use HandlesSyncBatch;

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

    protected function getSyncStatus(string $entityType): ?string
    {
        return $this->syncStatuses[$entityType] ?? null;
    }

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
            $this->handleResumeLogic(self::ENTITY_TYPE_EPISODE);

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
            $this->handleResumeLogic(self::ENTITY_TYPE_ENCOUNTER);

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
            $this->handleResumeLogic(self::ENTITY_TYPE_CLINICAL_IMPRESSION);

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
            $this->handleResumeLogic(self::ENTITY_TYPE_IMMUNIZATION);

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
            $this->handleResumeLogic(self::ENTITY_TYPE_OBSERVATION);

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
            $this->handleResumeLogic(self::ENTITY_TYPE_CONDITION);

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
            $this->handleResumeLogic(self::ENTITY_TYPE_DIAGNOSTIC_REPORT);

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
     * Get batch name for entity type.
     *
     * @param  string  $entityType
     * @return string
     */
    protected function getBatchName(string $entityType): string
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
    protected function getJobClass(string $entityType): string
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
    protected function getEntityConstant(string $entityType): string
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
