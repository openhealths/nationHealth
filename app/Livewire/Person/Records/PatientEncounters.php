<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\JobStatus;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Jobs\EncounterFullSync;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\Person\Person;
use App\Notifications\SyncNotification;
use App\Repositories\MedicalEvents\Repository;
use App\Traits\BatchLegalEntityQueries;
use Illuminate\Bus\Batch;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;
use Throwable;

class PatientEncounters extends BasePatientComponent
{
    use BatchLegalEntityQueries;

    public array $encounters = [];

    public array $episodes = [];
    public array $originEpisodes = [];
    public array $incomingReferrals = [];

    public string $syncStatus = '';

    public string $filterStartDateRange = '';

    public string $filterEndDateRange = '';

    public string $filterEpisode = '';

    public string $filterIncomingReferral = '';

    public string $filterOriginEpisode = '';

    public bool $showAdditionalParams = false;

    public array $dictionaryNames = [
        'eHealth/encounter_classes',
        'eHealth/encounter_types',
        'SPECIALITY_TYPE'
    ];

    protected function initializeComponent(): void
    {
        $this->getDictionary();

        $status = legalEntity()->getEntityStatus(LegalEntity::ENTITY_ENCOUNTER);
        $this->syncStatus = $status instanceof JobStatus ? $status->value : ($status ?? '');

        $person = Person::whereId($this->personId)
            ->with(['episodes:person_id,uuid,name', 'encounters.incomingReferral', 'encounters.originEpisode'])
            ->first();

        $this->episodes = $person->episodes->toArray();

        $this->incomingReferrals = $person->encounters
            ->pluck('incomingReferral')
            ->filter()
            ->map(fn (Identifier $referral) => [
                'uuid' => $referral->value,
                'displayValue' => $referral->displayValue
            ])
            ->unique('uuid')
            ->values()
            ->toArray();

        $this->originEpisodes = $person->encounters
            ->pluck('originEpisode')
            ->filter()
            ->map(fn (Identifier $referral) => [
                'uuid' => $referral->value,
                'displayValue' => $referral->displayValue
            ])
            ->unique('uuid')
            ->values()
            ->toArray();
    }

    public function sync(): void
    {
        $batchName = EncounterFullSync::BATCH_NAME . '_' . $this->uuid;

        if ($this->findRunningBatchesByLegalEntity(legalEntity()->id)->where('name', $batchName)->isNotEmpty()) {
            Session::flash('error', __('patients.messages.encounter_sync_already_running'));

            return;
        }

        if ($this->syncStatus === JobStatus::PAUSED->value || $this->syncStatus === JobStatus::FAILED->value) {
            $this->resumeSync($batchName);

            return;
        }

        try {
            $response = EHealth::encounter()->getBySearchParams(
                $this->uuid,
                ['managing_organization_id' => legalEntity()->uuid]
            );
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
            $this->dispatchRemainingPages($batchName);
        } else {
            legalEntity()->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_ENCOUNTER);
            Session::flash('success', __('patients.messages.encounters_synced_successfully'));
        }

        $this->encounters = Arr::toCamelCase($this->formatDatesForDisplay($validatedData));
    }

    public function search(): void
    {
        // todo: add period params after change in frontend
        $params = array_filter([
            'managing_organization_id' => legalEntity()->uuid,
            'episode_id' => $this->filterEpisode ?: null,
            'incoming_referral_id' => $this->filterIncomingReferral ?: null,
            'origin_episode_id' => $this->filterOriginEpisode ?: null
        ]);

        try {
            $response = EHealth::encounter()->getBySearchParams($this->uuid, $params);
            $this->encounters = Arr::toCamelCase($this->formatDatesForDisplay($response->validate()));
        } catch (ConnectionException|EHealthValidationException|EHealthResponseException $exception) {
            $this->handleEHealthExceptions($exception, 'Error while searching encounters');
        }
    }

    public function resetFilters(): void
    {
        $this->reset([
            'filterStartDateRange',
            'filterEndDateRange',
            'filterEpisode',
            'filterIncomingReferral',
            'filterOriginEpisode'
        ]);
    }

    private function resumeSync(string $batchName): void
    {
        $user = Auth::user();
        $token = Session::get(config('ehealth.api.oauth.bearer_token'));

        $failedBatch = $this->getFailedBatch($batchName);

        if ($failedBatch) {
            legalEntity()->setEntityStatus(JobStatus::PROCESSING, LegalEntity::ENTITY_ENCOUNTER);
            $this->syncStatus = JobStatus::PROCESSING->value;
            $this->restartBatch($failedBatch, $user, Crypt::encryptString($token), legalEntity());
        }

        Session::flash('success', __('patients.messages.encounter_sync_resume_started'));
        $user->notify(new SyncNotification('encounter', 'resumed'));
    }

    private function dispatchRemainingPages(string $batchName): void
    {
        $user = Auth::user();
        $token = Session::get(config('ehealth.api.oauth.bearer_token'));

        try {
            $user->notify(new SyncNotification('encounter', 'started'));

            Bus::batch([new EncounterFullSync(legalEntity(), page: 2)])
                ->withOption('legal_entity_id', legalEntity()->id)
                ->withOption('token', Crypt::encryptString($token))
                ->withOption('user', $user)
                ->withOption('patient_uuid', $this->uuid)
                ->withOption('person_id', $this->personId)
                ->then(fn () => $user->notify(new SyncNotification('encounter', 'completed')))
                ->catch(function (Batch $batch, Throwable $exception) use ($user) {
                    Log::error('EncounterSync batch failed.', [
                        'batch_id' => $batch->id,
                        'patient_uuid' => $this->uuid,
                        'exception' => $exception,
                    ]);
                    $user->notify(new SyncNotification('encounter', 'failed'));
                })
                ->onQueue('sync')
                ->name($batchName)
                ->dispatch();

            legalEntity()->setEntityStatus(JobStatus::PROCESSING, LegalEntity::ENTITY_ENCOUNTER);
            $this->syncStatus = JobStatus::PROCESSING->value;
            Session::flash('success', __('patients.messages.encounters_first_page_synced_successfully'));
        } catch (Throwable $exception) {
            Log::error('Failed to dispatch EncounterSync batch', ['exception' => $exception]);
            $user->notify(new SyncNotification('encounter', 'failed'));
            Session::flash('error', __('patients.messages.encounter_sync_background_dispatch_error'));
        }
    }

    public function render(): View
    {
        return view('livewire.person.records.encounters');
    }
}
