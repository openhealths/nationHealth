<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Classes\eHealth\EHealth;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\MedicalEvents\Sql\ClinicalImpression;
use App\Models\MedicalEvents\Sql\Condition;
use App\Models\MedicalEvents\Sql\DiagnosticReport;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\MedicalEvents\Sql\Immunization;
use App\Models\MedicalEvents\Sql\Observation;
use App\Repositories\MedicalEvents\Repository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Session;
use Throwable;

class PatientSummary extends BasePatientComponent
{
    public array $episodes;

    public array $encounters;

    public array $clinicalImpressions;

    public array $immunizations;

    public array $observations;

    public array $diagnoses;

    public array $conditions;

    public array $diagnosticReports;

    public array $allergyIntolerances;

    public array $riskAssessments;

    public array $devices;

    public array $medicationStatements;

    /**
     * Sync patient episodes from eHealth API to database.
     *
     * @return void
     */
    public function syncEpisodes(): void
    {
        try {
            $response = EHealth::episode()->getShortEpisodes($this->uuid);
            $validatedData = $response->validate();

            try {
                Repository::episode()->sync($this->id, $validatedData);
                Session::flash('success', __('patients.messages.episodes_synced_successfully'));
            } catch (Throwable $exception) {
                $this->logDatabaseErrors($exception, 'Error while synchronizing episodes');
                Session::flash('error', __('messages.database_error'));

                return;
            }

            // Refresh data for display
            $this->episodes = $validatedData;
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when syncing episodes');

            return;
        }
    }

    public function getEpisodes(): void
    {
        $this->episodes = Episode::with('period')->wherePersonId($this->id)->get()->toArray();
    }

    public function syncEncounters(): void
    {
        try {
            $response = EHealth::encounter()->getShortBySearchParams($this->uuid);
            $validatedData = $response->validate();

            try {
                Repository::encounter()->sync($this->id, $validatedData);
                Session::flash('success', __('patients.messages.encounters_synced_successfully'));
            } catch (Throwable $exception) {
                $this->logDatabaseErrors($exception, 'Error while synchronizing encounters');
                Session::flash('error', __('messages.database_error'));

                return;
            }

            // Refresh data for display
            $this->encounters = $validatedData;
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when syncing encounters');

            return;
        }
    }

    public function getEncounters(): void
    {
        $this->encounters = Encounter::wherePersonId($this->id)
            ->with(['class', 'episode.type.coding', 'type.coding', 'period', 'performerSpeciality.coding'])
            ->get()
            ->toArray();
    }

    public function syncClinicalImpressions(): void
    {
        try {
            $response = EHealth::clinicalImpression()->getSummary($this->uuid);
            $validatedData = $response->validate();

            try {
                Repository::clinicalImpression()->sync($this->id, $validatedData);
                Session::flash('success', __('patients.messages.clinical_impressions_synced_successfully'));
            } catch (Throwable $exception) {
                $this->logDatabaseErrors($exception, 'Error while synchronizing clinical impressions');
                Session::flash('error', __('messages.database_error'));

                return;
            }

            // Refresh data for display
            $this->clinicalImpressions = $validatedData;
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when getting clinical impressions');

            return;
        }
    }

    public function getClinicalImpressions(): void
    {
        $this->clinicalImpressions = ClinicalImpression::wherePersonId($this->id)
            ->withAllRelations()
            ->get()
            ->toArray();
    }

    public function syncImmunizations(): void
    {
        try {
            $response = EHealth::immunization()->getSummary($this->uuid);
            $validatedData = $response->validate();

            try {
                Repository::immunization()->sync($this->id, $validatedData);
                Session::flash('success', __('patients.messages.immunizations_synced_successfully'));
            } catch (Throwable $exception) {
                $this->logDatabaseErrors($exception, 'Error while synchronizing immunizations');
                Session::flash('error', __('messages.database_error'));

                return;
            }

            // Refresh data for display
            $this->immunizations = $validatedData;
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when getting immunizations');

            return;
        }
    }

    public function getImmunizations(): void
    {
        $this->immunizations = Immunization::wherePersonId($this->id)
            ->withAllRelations()
            ->get()
            ->toArray();
    }

    public function syncObservations(): void
    {
        try {
            $response = EHealth::observation()->getBySearchParams(
                $this->uuid,
                ['managing_organization_id' => legalEntity()->uuid]
            );
            $validatedData = $response->validate();

            try {
                Repository::observation()->sync($this->id, $validatedData);
                Session::flash('success', __('patients.messages.observations_synced_successfully'));
            } catch (Throwable $exception) {
                $this->logDatabaseErrors($exception, 'Error while synchronizing observations');
                Session::flash('error', __('messages.database_error'));

                return;
            }

            // Refresh data for display
            $this->observations = $validatedData;
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when getting observations');

            return;
        }
    }

    public function getObservations(): void
    {
        $this->observations = Observation::wherePersonId($this->id)
            ->withAllRelations()
            ->get()
            ->toArray();
    }

    public function syncConditions(): void
    {
        try {
            $response = EHealth::patient()->getConditions($this->uuid);
            $validatedData = $response->validate();
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when getting conditions');

            return;
        }
    }

    public function getConditions(): void
    {
        $this->conditions = Condition::wherePersonId($this->id)
            ->withAllRelations()
            ->get()
            ->toArray();
    }

    public function syncDiagnosticReports(): void
    {
        try {
            $response = EHealth::diagnosticReport()->getBySearchParams(
                $this->uuid,
                ['managing_organization_id' => legalEntity()->uuid]
            );
            $validatedData = $response->validate();

            try {
                Repository::diagnosticReport()->sync($this->id, $validatedData);
                Session::flash('success', __('patients.messages.diagnostic_reports_synced_successfully'));
            } catch (Throwable $exception) {
                $this->logDatabaseErrors($exception, 'Error while synchronizing diagnostic reports');
                Session::flash('error', __('messages.database_error'));
            }
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when getting diagnostic reports');

            return;
        }
    }

    public function getDiagnosticReports(): void
    {
        $this->diagnosticReports = DiagnosticReport::wherePersonId($this->id)
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

    public function render(): View
    {
        return view('livewire.person.records.summary');
    }
}
