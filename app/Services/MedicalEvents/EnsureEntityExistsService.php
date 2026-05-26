<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents;

use App\Classes\eHealth\EHealth;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\MedicalEvents\Sql\ClinicalImpression;
use App\Models\MedicalEvents\Sql\Condition;
use App\Models\MedicalEvents\Sql\DiagnosticReport;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\MedicalEvents\Sql\Observation;
use App\Models\MedicalEvents\Sql\Procedure;
use App\Repositories\MedicalEvents\Repository;
use App\Traits\LogsExceptions;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

class EnsureEntityExistsService
{
    use LogsExceptions;

    public function __construct(
        private readonly string $patientUuid,
        private readonly int $personId
    ) {
    }

    /**
     * Save condition/observation reason references locally if they don't exist in our database.
     *
     * @param  array  $procedure
     * @return void
     */
    public function processReasonReferences(array $procedure): void
    {
        if (!isset($procedure['reasonReferences'])) {
            return;
        }

        foreach ($procedure['reasonReferences'] as $reasonReference) {
            $code = $reasonReference['identifier']['type']['coding'][0]['code'];
            $uuid = $reasonReference['identifier']['value'];

            if ($code === 'condition') {
                $this->ensureConditionExists($uuid);
            } else {
                $this->ensureObservationExists($uuid);
            }
        }
    }

    /**
     * Save condition complication details locally if they don't exist in our database.
     *
     * @param  array  $procedure
     * @return void
     */
    public function processComplicationDetails(array $procedure): void
    {
        if (!isset($procedure['complicationDetails'])) {
            return;
        }

        foreach ($procedure['complicationDetails'] as $complicationDetail) {
            $this->ensureConditionExists($complicationDetail['identifier']['value']);
        }
    }

    /**
     * Save episode_of_care, procedure, diagnostic_report, encounter supporting info
     * locally if they don't exist in our database.
     *
     * @param  array  $clinicalImpression
     * @return void
     */
    public function processSupportingInfo(array $clinicalImpression): void
    {
        if (!isset($clinicalImpression['supportingInfo'])) {
            return;
        }

        foreach ($clinicalImpression['supportingInfo'] as $supportingInfo) {
            $code = $supportingInfo['identifier']['type']['coding'][0]['code'];
            $uuid = $supportingInfo['identifier']['value'];

            match ($code) {
                'episode_of_care' => $this->ensureEpisodeExists($uuid),
                'procedure' => $this->ensureProcedureExists($uuid),
                'diagnostic_report' => $this->ensureDiagnosticReportExists($uuid),
                'encounter' => $this->ensureEncounterExists($uuid),
                default => null
            };
        }
    }

    /**
     * Save condition and observation findings locally if they don't exist in our database.
     *
     * @param  array  $clinicalImpression
     * @return void
     */
    public function processFindings(array $clinicalImpression): void
    {
        if (!isset($clinicalImpression['findings'])) {
            return;
        }

        foreach ($clinicalImpression['findings'] as $finding) {
            $code = $finding['itemReference']['identifier']['type']['coding'][0]['code'];
            $uuid = $finding['itemReference']['identifier']['value'];

            if ($code === 'condition') {
                $this->ensureConditionExists($uuid);
            }

            if ($code === 'observation') {
                $this->ensureObservationExists($uuid);
            }
        }
    }

    /**
     * Save the referenced clinical impression locally if it doesn't exist in our database.
     *
     * @param  array  $clinicalImpression
     * @return void
     */
    public function processPrevious(array $clinicalImpression): void
    {
        if (!isset($clinicalImpression['previous'])) {
            return;
        }

        $this->ensureClinicalImpressionExists($clinicalImpression['previous']['identifier']['value']);
    }

    /**
     * Fetch episode from eHealth and store locally if it doesn't exist in our database.
     *
     * @param  string  $uuid
     * @return void
     */
    public function ensureEpisodeExists(string $uuid): void
    {
        if (Episode::whereUuid($uuid)->exists()) {
            return;
        }

        try {
            $episodeData = EHealth::episode()->getById($this->patientUuid, $uuid)->validate();
            Repository::episode()->syncFull($this->personId, [$episodeData]);
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Failed while getting episode by ID');
        } catch (Throwable $exception) {
            $this->logDatabaseErrors($exception, 'Failed to store episode');
        }
    }

    /**
     * Fetch procedure from eHealth and store locally if it doesn't exist in our database.
     *
     * @param  string  $uuid
     * @return void
     */
    public function ensureProcedureExists(string $uuid): void
    {
        if (Procedure::whereUuid($uuid)->exists()) {
            return;
        }

        try {
            $procedureData = EHealth::procedure()->getById($this->patientUuid, $uuid)->validate();
            Repository::procedure()->sync($this->personId, [$procedureData]);
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Failed while getting procedure by ID');
        } catch (Throwable $exception) {
            $this->logDatabaseErrors($exception, 'Failed to store procedure');
        }
    }

    /**
     * Fetch diagnostic report from eHealth and store locally if it doesn't exist in our database.
     *
     * @param  string  $uuid
     * @return void
     */
    public function ensureDiagnosticReportExists(string $uuid): void
    {
        if (DiagnosticReport::whereUuid($uuid)->exists()) {
            return;
        }

        try {
            $diagnosticReportData = EHealth::diagnosticReport()->getById($this->patientUuid, $uuid)->validate();
            Repository::diagnosticReport()->sync($this->personId, [$diagnosticReportData]);
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Failed while getting diagnostic report by ID');
        } catch (Throwable $exception) {
            $this->logDatabaseErrors($exception, 'Failed to store diagnostic report');
        }
    }

    /**
     * Fetch condition from eHealth and store locally if it doesn't exist in our database.
     *
     * @param  string  $uuid
     * @return void
     */
    public function ensureConditionExists(string $uuid): void
    {
        if (Condition::whereUuid($uuid)->exists()) {
            return;
        }

        try {
            $conditionData = EHealth::condition()->getById($this->patientUuid, $uuid)->validate();
            Repository::condition()->sync($this->personId, [$conditionData]);
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error while getting condition by ID');
        } catch (Throwable $exception) {
            $this->logDatabaseErrors($exception, 'Error while creating condition');
        }
    }

    /**
     * Fetch observation from eHealth and store locally if it doesn't exist in our database.
     *
     * @param  string  $uuid
     * @return void
     */
    public function ensureObservationExists(string $uuid): void
    {
        if (Observation::whereUuid($uuid)->exists()) {
            return;
        }

        try {
            $observationData = EHealth::observation()->getById($this->patientUuid, $uuid)->validate();
            Repository::observation()->sync($this->personId, [$observationData]);
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Failed while getting observation by ID');
        } catch (Throwable $exception) {
            $this->logDatabaseErrors($exception, 'Error while storing observation');
        }
    }

    /**
     * Fetch clinical impression from eHealth and store locally if it doesn't exist in our database.
     *
     * @param  string  $uuid
     * @return void
     */
    public function ensureClinicalImpressionExists(string $uuid): void
    {
        if (ClinicalImpression::whereUuid($uuid)->exists()) {
            return;
        }

        try {
            $clinicalImpressionData = EHealth::clinicalImpression()->getById($this->patientUuid, $uuid)->validate();
            Repository::clinicalImpression()->sync($this->personId, [$clinicalImpressionData]);
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Failed while getting clinical impression by ID');
        } catch (Throwable $exception) {
            $this->logDatabaseErrors($exception, 'Error while storing clinical impression');
        }
    }

    /**
     * Fetch encounter from eHealth and store locally if it doesn't exist in our database.
     *
     * @param  string  $uuid
     * @return void
     */
    public function ensureEncounterExists(string $uuid): void
    {
        if (Encounter::whereUuid($uuid)->exists()) {
            return;
        }

        try {
            $encounterData = EHealth::encounter()->getById($this->patientUuid, $uuid)->validate();
            Repository::encounter()->sync($this->personId, [$encounterData]);
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Failed while ensuring encounter existence');
        } catch (Throwable $exception) {
            $this->logDatabaseErrors($exception, 'Error while storing encounter');
        }
    }
}
