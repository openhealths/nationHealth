<?php

declare(strict_types=1);

namespace App\Traits;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\MedicalEvents\Sql\Condition;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Observation;
use App\Repositories\MedicalEvents\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Session;
use Throwable;

trait HandlesReasonReferences
{
    /**
     * Handle details of procedure reason references.
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
            if ($reasonReference['identifier']['type']['coding'][0]['code'] === 'condition') {
                $this->ensureConditionExists($reasonReference['identifier']['value']);
            } else {
                $this->ensureObservationExists($reasonReference['identifier']['value']);
            }
        }
    }

    /**
     * Checks if a condition exists and creates it if necessary
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
            $conditionData = EHealth::condition()->getById($this->patientUuid, $uuid)->getData();

            try {
                Repository::condition()->store([Arr::toCamelCase($conditionData)], $this->personId);
            } catch (Throwable $exception) {
                $this->logDatabaseErrors($exception, 'Error while creating condition');
                Session::flash('error', __('messages.database_error'));

                return;
            }
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error while getting condition by ID');

            return;
        }
    }

    /**
     * Check is encounter exist, if not create it. Return created or existed ID of encounter.
     *
     * @param  string  $encounterUuid
     * @return int|null
     */
    private function ensureEncounterExist(string $encounterUuid): ?int
    {
        $encounterId = Encounter::whereUuid($encounterUuid)->value('id');

        if ($encounterId) {
            return $encounterId;
        }

        try {
            $encounterData = EHealth::encounter()->getById($this->patientUuid, $encounterUuid)->getData();

            return Repository::encounter()->store($encounterData, $this->personId);
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Failed while ensuring encounter existence');
        } catch (Throwable $exception) {
            $this->logDatabaseErrors($exception, 'Error while storing encounter');
            Session::flash('error', __('messages.database_error'));

            return null;
        }

        return null;
    }

    /**
     * Checks for the existence of an observation and creates it if necessary.
     *
     * @param  string  $uuid
     * @return void
     */
    private function ensureObservationExists(string $uuid): void
    {
        if (Observation::whereUuid($uuid)->exists()) {
            return;
        }

        try {
            $observationData = EHealth::observation()->getById($this->patientUuid, $uuid)->getData();
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Failed while getting observation by ID');

            return;
        }

        try {
            Repository::observation()->store([Arr::toCamelCase($observationData)], $this->personId);
        } catch (Throwable $exception) {
            $this->logDatabaseErrors($exception, 'Error while storing observation');
            Session::flash('error', __('messages.database_error'));

            return;
        }
    }
}
