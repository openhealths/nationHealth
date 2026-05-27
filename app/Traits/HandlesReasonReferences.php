<?php

// TODO: remove this file — logic moved to MedicalEventSyncService

declare(strict_types=1);

namespace App\Traits;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Models\MedicalEvents\Sql\ClinicalImpression;
use App\Models\MedicalEvents\Sql\Condition;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Observation;
use App\Repositories\MedicalEvents\Repository;
use Illuminate\Support\Facades\Session;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
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
            $conditionData = EHealth::condition()->getById($this->patientUuid, $uuid)->validate();

            try {
                Repository::condition()->sync($this->personId, [$conditionData]);
            } catch (Throwable $exception) {
                $this->handleDatabaseErrors($exception, 'Error while creating condition');
                Session::flash('error', __('messages.database_error'));

                return;
            }
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while getting condition by ID');

            return;
        }
    }

    /**
     * Check is encounter exist, if not create it. Return created or existed ID of encounter.
     *
     * @param  string  $uuid
     * @return void
     */
    private function ensureEncounterExist(string $uuid): void
    {
        if (Encounter::whereUuid($uuid)->exists()) {
            return;
        }

        try {
            $encounterData = EHealth::encounter()->getById($this->patientUuid, $uuid)->validate();

            Repository::encounter()->sync($this->personId, [$encounterData]);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Failed while ensuring encounter existence');
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while storing encounter');
            Session::flash('error', __('messages.database_error'));

            return;
        }
    }

    /**
     * Checks if a clinical impression exists and creates it if necessary.
     *
     * @param  string  $uuid
     * @return void
     */
    private function ensureClinicalImpressionExists(string $uuid): void
    {
        if (ClinicalImpression::whereUuid($uuid)->exists()) {
            return;
        }

        try {
            $clinicalImpressionData = EHealth::clinicalImpression()->getById($this->patientUuid, $uuid)->validate();

            try {
                Repository::clinicalImpression()->sync($this->personId, [$clinicalImpressionData]);
            } catch (Throwable $exception) {
                $this->handleDatabaseErrors($exception, 'Error while storing clinical impression');
                Session::flash('error', __('messages.database_error'));

                return;
            }
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Failed while getting clinical impression by ID');

            return;
        }
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
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Failed while getting observation by ID');

            return;
        }

        try {
            Repository::observation()->store([Arr::toCamelCase($observationData)], $this->personId);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while storing observation');
            Session::flash('error', __('messages.database_error'));

            return;
        }
    }
}
