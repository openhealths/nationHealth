<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Classes\eHealth\EHealth;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\MedicalEvents\Sql\Episode;
use App\Repositories\MedicalEvents\Repository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Session;
use Throwable;

class PatientSummary extends BasePatientComponent
{
    public array $episodes;

    public array $diagnoses;

    public array $observations;

    /**
     * Sync patient episodes from eHealth API to database.
     *
     * @return void
     */
    public function syncEpisodes(): void
    {
        try {
            $response = EHealth::patient()->getShortEpisodes($this->uuid);
            $validatedData = $response->validate();

            try {
                Repository::episode()->sync($this->id, $validatedData);
                Session::flash('success', __('patients.messages.episodes_synced_successfully'));
            } catch (Throwable $exception) {
                $this->logDatabaseErrors($exception, 'Error while synchronizing episodes');
                Session::flash('error', __('messages.database_error'));

                return;
            }

            // Refresh episodes data for display
            $this->episodes = $validatedData;
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when syncing episodes');
        }
    }

    public function getEpisodes(): void
    {
        $this->episodes = Episode::with('period')->wherePersonId($this->id)->get()->toArray();
    }

    public function getEncounter(): void
    {
        try {
            $response = EHealth::patient()->getShortEpisodes($this->uuid);

            $this->episodes = $response->getData();
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when getting short episodes');

            return;
        }
    }

    public function getClinicalImpressions(): void
    {
        try {
            $response = EHealth::patient()->getShortEpisodes($this->uuid);

            $this->episodes = $response->getData();
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when getting short episodes');

            return;
        }
    }

    /**
     * Get patient diagnoses.
     *
     * @return void
     */
    public function getDiagnoses(): void
    {
        try {
            $response = EHealth::patient()->getActiveDiagnoses($this->uuid);

            $this->diagnoses = $response->getData();
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when getting active diagnoses');

            return;
        }
    }

    /**
     * Get patient observations.
     *
     * @return void
     */
    public function getObservations(): void
    {
        try {
            $response = EHealth::patient()->getObservations($this->uuid);

            $this->observations = $response->getData();
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error when getting observations');

            return;
        }
    }

    public function render(): View
    {
        return view('livewire.person.records.summary');
    }
}
