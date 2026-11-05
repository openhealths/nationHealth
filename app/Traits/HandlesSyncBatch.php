<?php

declare(strict_types=1);

namespace App\Traits;

use App\Enums\JobStatus;
use App\Models\Declaration;
use App\Models\MedicalEvents\Sql\Approval;
use App\Models\User;
use App\Notifications\SyncNotification;
use Illuminate\Bus\Batch;
use Illuminate\Bus\BatchRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use stdClass;
use Throwable;

/**
 * Provides reusable sync batch.
 *
 * Requires the using class to also use BatchLegalEntityQueries and to have
 * $uuid and $personId properties (from BasePatientComponent).
 */
trait HandlesSyncBatch
{
    /**
     * Returns the current sync status string for the given entity type.
     *
     * @param  string  $entityType
     * @return string|null
     */
    abstract protected function getSyncStatus(string $entityType): ?string;

    /**
     * Returns the batch name constant for the given entity type.
     *
     * @param  string  $entityType
     * @return string
     */
    abstract protected function getBatchName(string $entityType): string;

    /**
     * Returns the fully-qualified job class for the given entity type.
     *
     * @param  string  $entityType
     * @return string
     */
    abstract protected function getJobClass(string $entityType): string;

    /**
     * Returns the LegalEntity entity constant for the given entity type.
     *
     * @param  string  $entityType
     * @return string
     */
    abstract protected function getEntityConstant(string $entityType): string;

    /**
     * Hook called when the sync status transitions; override to update local component state.
     *
     * @param  string  $entityType
     * @param  JobStatus  $status
     * @return void
     */
    protected function onSyncStatusChanged(string $entityType, JobStatus $status): void
    {
    }

    /**
     * Whether the user has access to all the patient's records being synced: an active declaration (ABAC rule 1) or an
     * active approval on the patient's data (ABAC rule 4). Override when another rule opens all the records of the type.
     *
     * @param  User  $user
     * @return bool
     */
    protected function hasPatientAccessForSync(User $user): bool
    {
        return Declaration::accessGrantedTo($user, $this->patient(), legalEntity())->exists()
            || Approval::accessGrantedTo($user, $this->patient(), legalEntity())->exists();
    }

    /**
     * Returns the language file holding the sync messages for the given entity type.
     *
     * @param  string  $entityType
     * @return string
     */
    protected function syncLangGroup(string $entityType): string
    {
        return match ($entityType) {
            'diagnostic_report' => 'diagnostic-reports',
            'procedure' => 'procedures',
            'observation' => 'observations',
            'condition' => 'conditions',
            'immunization' => 'immunizations',
            'clinical_impression' => 'clinical-impressions',
            'device' => 'devices',
            'specimen' => 'specimens',
            'detected_issue' => 'detected-issues',
            'device_association' => 'device-associations',
            'device_dispense' => 'device-dispenses',
            'encounter' => 'encounters',
            'episode' => 'episodes',
            default => 'patients'
        };
    }

    /**
     * Returns true if a batch for this entity type is currently running.
     *
     * @param  string  $entityType
     * @param  bool  $fresh  Read the running batches again instead of the result cached during the request
     * @return bool
     */
    protected function isSyncProcessing(string $entityType, bool $fresh = false): bool
    {
        $batchName = $this->getBatchName($entityType);
        $runningBatches = $fresh
            ? $this->findRunningBatchesByLegalEntity(legalEntity()->id)
            : once(fn () => $this->findRunningBatchesByLegalEntity(legalEntity()->id));

        return $runningBatches->contains(fn (stdClass $batch): bool => $this->isPatientBatch($batch, $batchName));
    }

    /**
     * Returns true and flashes an error if a sync is already running for the entity type.
     *
     * @param  string  $entityType
     * @param  bool  $fresh  Read the running batches again instead of the result cached during the request
     * @return bool
     */
    protected function cannotStartSync(string $entityType, bool $fresh = false): bool
    {
        if ($this->isSyncProcessing($entityType, $fresh)) {
            Session::flash('error', __($this->syncLangGroup($entityType) . '.messages.sync_already_running'));

            return true;
        }

        return false;
    }

    /**
     * Returns true if the caller must not start a full sync: the entity sync of this patient is already running
     * (e.g. a concurrent request has just restarted its failed batch), or it is paused or failed and has a failed batch
     * to resume. Handle it with handleResumeLogic().
     *
     * The status is stored per legal entity, so it may be left by the sync of another patient.
     *
     * @param  string  $entityType
     * @return bool
     */
    protected function shouldResumeSync(string $entityType): bool
    {
        if ($this->isSyncProcessing($entityType, fresh: true)) {
            return true;
        }

        $status = $this->getSyncStatus($entityType);

        return ($status === JobStatus::PAUSED->value || $status === JobStatus::FAILED->value)
            && $this->findFailedBatchForPatient($entityType) !== null;
    }

    /**
     * Finds the oldest failed batch of the entity type that belongs to the current patient.
     *
     * @param  string  $entityType
     * @return stdClass|null
     */
    private function findFailedBatchForPatient(string $entityType): ?stdClass
    {
        return $this->findFailedBatchesForPatient($entityType)->first();
    }

    /**
     * Finds all failed batches of the entity type that belong to the current patient, the oldest first.
     *
     * @param  string  $entityType
     * @return Collection<int, stdClass>
     */
    private function findFailedBatchesForPatient(string $entityType): Collection
    {
        $batchName = $this->getBatchName($entityType);

        return $this->findFailedBatchesByLegalEntity(legalEntity()->id, 'ASC')
            ->filter(fn (stdClass $batch): bool => $this->isPatientBatch($batch, $batchName))
            ->values();
    }

    /**
     * Determines whether the batch is the sync of the entity type for the current patient, either failed or running.
     *
     * A retried batch is named "retry_<name>" without the uuid, so it is matched by its patient_uuid option.
     *
     * @param  stdClass  $batch
     * @param  string  $batchName
     * @return bool
     */
    private function isPatientBatch(stdClass $batch, string $batchName): bool
    {
        if ($batch->name === $batchName . '_' . $this->uuid) {
            return true;
        }

        return $this->baseBatchName($batch->name) === $batchName
            && $batch->name !== $batchName
            && (app(BatchRepository::class)->find($batch->id)?->options['patient_uuid'] ?? null) === $this->uuid;
    }

    /**
     * Restarts the failed batch for the entity type and notifies the user.
     *
     * @param  string  $entityType
     * @return bool True when the caller must not run a full sync: the batch was restarted, or the sync is already
     *              running or being resumed by a concurrent request
     */
    protected function handleResumeLogic(string $entityType): bool
    {
        // Different failed batches of the same patient must not be resumed concurrently.
        $lock = Cache::lock('patient-sync-resume:' . legalEntity()->id . ':' . $this->uuid . ':' . $entityType, 120);

        if (!$lock->get()) {
            Session::flash('error', __($this->syncLangGroup($entityType) . '.messages.sync_already_running'));

            return true;
        }

        try {
            $user = Auth::user();
            $token = Session::get(config('ehealth.api.oauth.bearer_token'));

            if (!$this->resumeSynchronization($entityType, $user, $token)) {
                // The failed batch may already be restarted by a concurrent request, which isn't a reason to start a full sync
                return $this->cannotStartSync($entityType, fresh: true);
            }

            Session::flash('success', __($this->syncLangGroup($entityType) . '.messages.sync_resume_started'));
            $user->notify(new SyncNotification($entityType, 'resumed'));

            return true;
        } finally {
            $lock->release();
        }
    }

    /**
     * Dispatches background jobs for pages 2+ and notifies the user of progress.
     *
     * @param  string  $entityType
     * @return void
     */
    protected function dispatchRemainingPages(string $entityType): void
    {
        $user = Auth::user();
        $token = Session::get(config('ehealth.api.oauth.bearer_token'));

        try {
            $user->notify(new SyncNotification($entityType, 'started'));
            $this->dispatchNextJobs($entityType, $user, $token);
            Session::flash('success', __($this->syncLangGroup($entityType) . '.messages.first_page_synced_successfully'));
        } catch (Throwable $exception) {
            Log::error('Failed to dispatch ' . ucfirst($entityType) . 'Sync batch', ['exception' => $exception]);
            $user->notify(new SyncNotification($entityType, 'failed'));
            Session::flash('error', __($this->syncLangGroup($entityType) . '.messages.sync_background_dispatch_error'));
        }
    }

    /**
     * Restarts the oldest failed batch of the entity type for the current patient that still has jobs to restart.
     *
     * @param  string  $entityType
     * @param  User  $user
     * @param  string  $token
     * @return bool True if the failed batch was restarted
     */
    private function resumeSynchronization(string $entityType, User $user, string $token): bool
    {
        $encryptedToken = Crypt::encryptString($token);
        $entityConstant = $this->getEntityConstant($entityType);

        // An older batch may have no failed jobs left to restart, so try the next ones
        foreach ($this->findFailedBatchesForPatient($entityType) as $batch) {
            if ($this->isSyncProcessing($entityType, fresh: true)) {
                return false;
            }

            Log::info('Resuming ' . ucfirst($entityType) . ' sync batch: ' . $batch->name . ' id: ' . $batch->id);

            $isRestarted = $this->restartBatch(
                $batch,
                $user,
                $encryptedToken,
                legalEntity(),
                fn () => legalEntity()->setEntityStatus(JobStatus::PROCESSING, $entityConstant)
            );

            if ($isRestarted) {
                $this->onSyncStatusChanged($entityType, JobStatus::PROCESSING);

                return true;
            }
        }

        return false;
    }

    /**
     * Dispatches the next-page sync job batch and updates legal entity status.
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

        // Decided once for the whole batch, so every page is fetched with the same filter
        $hasPatientAccess = $this->hasPatientAccessForSync($user);

        // The callbacks are stored with the batch and restored on every read of it, so they keep only plain values
        $userId = $user->id;
        $patientUuid = $this->uuid;

        Bus::batch([new $jobClass(legalEntity(), page: 2)])
            ->withOption('legal_entity_id', legalEntity()->id)
            ->withOption('token', Crypt::encryptString($token))
            ->withOption('user', $user)
            ->withOption('patient_uuid', $this->uuid)
            ->withOption('person_id', $this->personId)
            ->withOption('preperson_id', $this->prepersonId)
            ->withOption('has_patient_access', $hasPatientAccess)
            ->then(static fn () => User::find($userId)?->notify(new SyncNotification($entityType, 'completed')))
            ->catch(static function (Batch $batch, Throwable $exception) use ($userId, $entityType, $patientUuid): void {
                Log::error(ucfirst($entityType) . ' sync batch failed.', [
                    'batch_id' => $batch->id,
                    'patient_uuid' => $patientUuid,
                    'exception' => $exception
                ]);
                User::find($userId)?->notify(new SyncNotification($entityType, 'failed'));
            })
            ->onQueue('sync')
            ->name($batchName . '_' . $this->uuid)
            ->dispatch();

        legalEntity()->setEntityStatus(JobStatus::PROCESSING, $entityConstant);
        $this->onSyncStatusChanged($entityType, JobStatus::PROCESSING);
    }
}
