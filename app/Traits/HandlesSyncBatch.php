<?php

declare(strict_types=1);

namespace App\Traits;

use App\Enums\JobStatus;
use App\Models\User;
use App\Notifications\SyncNotification;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
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
     * Returns true if a batch for this entity type is currently running.
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
     * Returns true and flashes an error if a sync is already running for the entity type.
     *
     * @param  string  $entityType
     * @return bool
     */
    protected function cannotStartSync(string $entityType): bool
    {
        if ($this->isSyncProcessing($entityType)) {
            Session::flash('error', __('patients.messages.' . $entityType . '_sync_already_running'));

            return true;
        }

        return false;
    }

    /**
     * Returns true if the entity sync is in a paused or failed state that should be resumed.
     *
     * @param  string  $entityType
     * @return bool
     */
    protected function shouldResumeSync(string $entityType): bool
    {
        $status = $this->getSyncStatus($entityType);

        return $status === JobStatus::PAUSED->value || $status === JobStatus::FAILED->value;
    }

    /**
     * Restarts the failed batch for the entity type and notifies the user.
     *
     * @param  string  $entityType
     * @return void
     */
    protected function handleResumeLogic(string $entityType): void
    {
        $user = Auth::user();
        $token = Session::get(config('ehealth.api.oauth.bearer_token'));

        $this->resumeSynchronization($entityType, $user, $token);
        Session::flash('success', __('patients.messages.' . $entityType . '_sync_resume_started'));
        $user->notify(new SyncNotification($entityType, 'resumed'));
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
            Session::flash('success', __('patients.messages.' . $entityType . 's_first_page_synced_successfully'));
        } catch (Throwable $exception) {
            Log::error('Failed to dispatch ' . ucfirst($entityType) . 'Sync batch', ['exception' => $exception]);
            $user->notify(new SyncNotification($entityType, 'failed'));
            Session::flash('error', __('patients.messages.' . $entityType . '_sync_background_dispatch_error'));
        }
    }

    /**
     * Finds the oldest failed batch for the entity type and restarts it.
     *
     * @param  string  $entityType
     * @param  User  $user
     * @param  string  $token
     * @return void
     */
    private function resumeSynchronization(string $entityType, User $user, string $token): void
    {
        $encryptedToken = Crypt::encryptString($token);
        $batchName = $this->getBatchName($entityType);
        $entityConstant = $this->getEntityConstant($entityType);

        $failedBatches = $this->findFailedBatchesByLegalEntity(legalEntity()->id, 'ASC');

        foreach ($failedBatches as $batch) {
            if ($batch->name === $batchName . '_' . $this->uuid) {
                Log::info('Resuming ' . ucfirst($entityType) . ' sync batch: ' . $batch->name . ' id: ' . $batch->id);

                legalEntity()->setEntityStatus(JobStatus::PROCESSING, $entityConstant);
                $this->onSyncStatusChanged($entityType, JobStatus::PROCESSING);
                $this->restartBatch($batch, $user, $encryptedToken, legalEntity());
                break;
            }
        }
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
                    'exception' => $exception,
                ]);
                $user->notify(new SyncNotification($entityType, 'failed'));
            })
            ->onQueue('sync')
            ->name($batchName . '_' . $this->uuid)
            ->dispatch();

        legalEntity()->setEntityStatus(JobStatus::PROCESSING, $entityConstant);
        $this->onSyncStatusChanged($entityType, JobStatus::PROCESSING);
    }
}
