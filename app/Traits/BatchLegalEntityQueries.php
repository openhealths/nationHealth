<?php

declare(strict_types=1);

namespace App\Traits;

use App\Jobs\ContractRequestDetailsUpsert;
use App\Models\Contracts\ContractRequest;
use stdClass;
use Exception;
use App\Models\User;
use App\Core\EHealthJob;
use App\Enums\JobStatus;
use App\Models\LegalEntity;
use App\Models\Declaration;
use App\Jobs\ConfidantPersonSync;
use App\Models\Employee\Employee;
use App\Models\DeclarationRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use App\Jobs\EmployeeDetailsUpsert;
use Illuminate\Bus\BatchRepository;
use App\Jobs\DeclarationDetailsSync;
use App\Models\Employee\EmployeeRequest;
use App\Models\Relations\ConfidantPerson;
use App\Jobs\EmployeeRequestDetailsUpsert;
use App\Jobs\DeclarationRequestDetailsSync;

/**
 * Trait for querying batches by legal_entity_id
 *
 */
trait BatchLegalEntityQueries
{
    /**
     * Check if the entity in the synchronization process right now.
     * This determines if the entity synchronization status is valid for start/continue synchronization
     * If returns true, synchronization cannot proceed; otherwise, it can be start/continue.
     * If returns true the sync entity button's got the "disabled" state.
     *
     * @param string $entityStatus The synchronization status of the entity to check
     *
     * @return bool Returns true if the entity sync status is valid/successful for sync, false otherwise
     */
    protected function isEntitySyncIsInProgress(?string $entityStatus = null, bool $isLegalEntity = false): bool
    {
        return $isLegalEntity
            ?  $entityStatus !== JobStatus::COMPLETED->value
            :  (
               $entityStatus !== JobStatus::COMPLETED->value &&
               $entityStatus !== JobStatus::PAUSED->value &&
               $entityStatus !== JobStatus::FAILED->value &&
               !empty($entityStatus)
            );
    }

    /**
     * Find all batches for a specific legal entity
     *
     * @param int $legalEntityId
     * @param int $limit
     * @param string $orderBy
     * @return Collection<stdClass>
     */
    protected function findBatchesByLegalEntity(int $legalEntityId, int $limit = 50, string $orderBy = 'desc'): Collection
    {
        return DB::table('job_batches')
            ->where('legal_entity_id', $legalEntityId)
            ->orderBy('created_at', $orderBy)
            ->limit($limit)
            ->get();
    }

    /**
     * Find failed batches by legal entity ID
     *
     * @param int $legalEntityId
     * @param string $orderBy
     * @param int $limit
     *
     * @return Collection<stdClass>
     */
    protected function findFailedBatchesByLegalEntity(int $legalEntityId, string $orderBy = 'desc'): Collection
    {
        return DB::table('job_batches')
            ->where('legal_entity_id', $legalEntityId)
            ->where('failed_jobs', '>', 0)
            ->orderBy('cancelled_at', $orderBy)
            ->get();
    }

    /**
     * Retrieves a failed batch by its name.
     *
     * @param string $batchName The name of the batch to retrieve
     *
     * @return stdClass|null The failed batch object if found, null otherwise
     */
    protected function getFailedBatch(string $batchName): ?stdClass
    {
        $batches = $this->findFailedBatchesByLegalEntity(legalEntity()->id, 'ASC') ;

        if ($batches->isEmpty()) {
            return null;
        }

        $batches = $batches->filter(fn($batch) => $batch->name === $batchName);

        return $batches->isNotEmpty() ? $batches->first() : null;
    }

    /**
     * Restart a failed batch by its batch object
     *
     * @param stdClass $batch The failed batch object
     * @param User $user The user context for the new batch execution
     * @param string $token Encrypted authentication token for API requests
     * @param LegalEntity|null $legalEntity The legal entity context for the batch (optional)
     *
     * @return void
     */
    protected function restartBatch(stdClass $batch, User $user, string $token, ?LegalEntity $legalEntity = null): void
    {
        $legalEntity = $legalEntity ?? legalEntity();

        $pendingJobs = $this->extractPendingJobsFromBatches($batch);

        if (empty($pendingJobs)) {
            // Here do echo only into the job context where legalEntity() is not set
            if (!legalEntity()) {
                echo 'No pending jobs found in batch: ' . $batch->name . ' id: ' . $batch->id . PHP_EOL;
            }

            return;
        }

        $this->restartFailedBatch($user, $batch, $token, $legalEntity, $pendingJobs);
    }

    /**
     * Find running (not finished, not cancelled) batches by legal entity ID
     *
     * @param int $legalEntityId
     * @param int $limit
     *
     * @return Collection<stdClass>
     */
    protected function findRunningBatchesByLegalEntity(int $legalEntityId): Collection
    {
        return DB::table('job_batches')
            ->where('legal_entity_id', $legalEntityId)
            ->whereNull('finished_at')
            ->whereNull('cancelled_at')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Check if legal entity has any running batches
     *
     * @param int $legalEntityId
     * @return bool
     */
    protected function hasRunningBatchesForLegalEntity(int $legalEntityId): bool
    {
        return DB::table('job_batches')
            ->where('legal_entity_id', $legalEntityId)
            ->whereNull('finished_at')
            ->whereNull('cancelled_at')
            ->exists();
    }

    /**
     * Restart a failed batch by creating a new batch with pending jobs
     *
     * @param User $user The user context for the new batch execution
     * @param stdClass $batch The failed batch record from job_batches table
     * @param string $token Encrypted authentication token for API requests
     * @param LegalEntity $legalEntity The legal entity context for the batch
     * @param array $pendingJobs Array of job instances to be re-dispatched
     *
     * @return void
     *
     * @throws Exception If batch creation or deletion fails
     */
    protected function restartFailedBatch(User $user, stdClass $batch, string $token, LegalEntity $legalEntity, array $pendingJobs): void
    {
        $newBatch = Bus::batch($pendingJobs)
            ->name($batch->name)
            ->withOption('legal_entity_id', $legalEntity->id)
            ->withOption('token', $token) // Here token is encrypted
            ->withOption('user', $user)
            ->onQueue('sync')
            ->dispatch();

        // Here do echo only into the job context where legalEntity() is not set
        if (!legalEntity()) {
         echo 'Dispatched new batch: ' . $newBatch->name . ' id: ' . $newBatch->id . PHP_EOL;
        }

        // Delete the old failed batch to prevent clutter
        app(BatchRepository::class)->delete($batch->id);

        // Here do echo only into the job context where legalEntity() is not set
        if (!legalEntity()) {
            echo 'Deleted old failed batch: ' . $batch->name . ' id: ' . $batch->id . PHP_EOL;
        }
    }

    /**
     * Extract pending jobs from failed batch by recreating job instances from failed_jobs table
     *
     * @param stdClass $batch The batch record from job_batches table
     *
     * @return array Array of unserialized job instances ready for re-dispatch
     */
    protected function extractPendingJobsFromBatches(stdClass $batch): array
    {
        $pendingJobs = [];

        $failedJobsIds = json_decode($batch->failed_job_ids, true) ?? [];

        if (empty($failedJobsIds)) {
            return [];
        }

        $jobs = DB::table('failed_jobs')
            ->whereIn('uuid', $failedJobsIds)
            ->get();

        foreach ($jobs as $job) {
            if (!isset($job->payload)) {
                continue;
            }

            $payload = json_decode($job->payload, true);

            if (isset($payload['data']['command'])) {
                $pendingJobs[] = unserialize($payload['data']['command']);
            }

            // Remove the job from failed_jobs table to prevent reprocessing
            DB::table('failed_jobs')->where('id', $job->id)->delete();
        }

        return $pendingJobs;
    }


    /**
     * Creates a chain of EmployeeDetailsUpsert jobs for all employees with PARTIAL sync status.
     *
     * Jobs are created in reverse order, each next job receives the previous one as nextEntity.
     * Returns the first job in the chain (or null if there are no employees).
     * So the jobs will be executed in the original order one by one.
     *
     * @param LegalEntity $legalEntity
     * @param EHealthJob|null $nextEntity The job to be executed after the chain completes (or null)
     *
     * @return EHealthJob|null The first job in the EmployeeDetailsUpsert chain, or null if there are no employees
     */
    protected function getEmployeeDetailsStartJob(LegalEntity $legalEntity, ?EHealthJob $nextEntity): ?EHealthJob
    {
        $job = null;

        // The incoming $nextEntity will be executed after the whole chain
        $previousJob = $nextEntity;

        $models = Employee::with('party')
            ->filterByLegalEntityId($legalEntity->id)
            ->filterBySyncStatus(JobStatus::PARTIAL)
            ->get();

        foreach ($models->reverse() as $index => $model) {
            $job = new EmployeeDetailsUpsert(
                employee: $model,
                legalEntity: $legalEntity,
                nextEntity: $previousJob
            );

            $previousJob = $job;
        }

        // Here $job is the first job in the chain (or null if no employees)
        return $job ?? $previousJob;
    }

    /**
     * Creates a chain of EmployeeRequestDetailsUpsert jobs for all employee_requests with PARTIAL sync status.
     *
     * Jobs are created in reverse order, each next job receives the previous one as nextEntity.
     * Returns the first job in the chain (or null if there are no employee_requests).
     * So the jobs will be executed in the original order one by one.
     *
     * @param LegalEntity $legalEntity
     * @param EHealthJob|null $nextEntity The job to be executed after the chain completes (or null)
     *
     * @return EHealthJob|null The first job in the EmployeeRequestDetailsUpsert chain, or null if there are no employee_requests
     */
    protected function getEmployeeRequestDetailsStartJob(LegalEntity $legalEntity, ?EHealthJob $nextEntity): ?EHealthJob
    {
        $job = null;

        // The incoming $nextEntity will be executed after the whole chain
        $previousJob = $nextEntity;

        $models = EmployeeRequest::filterByLegalEntityId($legalEntity->id)
            ->filterBySyncStatus(JobStatus::PARTIAL)
            ->get();

        foreach ($models->reverse() as $index => $model) {
            $job = new EmployeeRequestDetailsUpsert(
                employeeRequest: $model,
                legalEntity: $legalEntity,
                nextEntity: $previousJob
            );

            $previousJob = $job;
        }

        // Here $job is the first job in the chain (or null if no employees)
        return $job ?? $previousJob;
    }

    /**
     * Creates a chain of DeclarationDetailsSync jobs for all declarations with PARTIAL sync status.
     *
     * Jobs are created in reverse order, each next job receives the previous one as nextEntity.
     * Returns the first job in the chain (or null if there are no employees).
     * So the jobs will be executed in the original order one by one.
     *
     * @param LegalEntity $legalEntity
     * @param EHealthJob|null $nextEntity The job to be executed after the chain completes (or null)
     *
     * @return EHealthJob|null The first job in the EmployeeDetailsUpsert chain, or null if there are no employees
     */
    protected function getDeclarationDataStartJob(LegalEntity $legalEntity, ?EHealthJob $nextEntity): ?EHealthJob
    {
        $job = null;

        // The incoming $nextEntity will be executed after the whole chain
        $previousJob = $nextEntity;

        $models = Declaration::with('person')
            ->filterByLegalEntityId(legalEntityId: $legalEntity->id)
            ->filterBySyncStatus(status: JobStatus::PARTIAL)
            ->get();

        foreach ($models->reverse() as $index => $model) {
            $job = new DeclarationDetailsSync(
                declaration: $model,
                legalEntity: $legalEntity,
                nextEntity: $previousJob
            );

            $previousJob = $job;
        }

        // Here $job is the first job in the chain (or null if no declarations)
        return $job ?? $previousJob;
    }

    /**
     * Creates a chain of DeclarationRequestsDetailsSync jobs for all declarationRequests with PARTIAL sync status.
     *
     * Jobs are created in reverse order, each next job receives the previous one as nextEntity.
     * Returns the first job in the chain (or null if there are no declarationRequests).
     * So the jobs will be executed in the original order one by one.
     *
     * @param LegalEntity $legalEntity
     * @param EHealthJob|null $nextEntity The job to be executed after the chain completes (or null)
     *
     * @return EHealthJob|null The first job in the EmployeeDetailsUpsert chain, or null if there are no employees
     */
    protected function getDeclarationRequestsStartJob(LegalEntity $legalEntity, ?EHealthJob $nextEntity): ?EHealthJob
    {
        $job = null;

        // The incoming $nextEntity will be executed after the whole chain
        $previousJob = $nextEntity ?? $this->getDeclarationDataStartJob($legalEntity, $nextEntity);

        $models = DeclarationRequest::with(['employee', 'division', 'person'])
            ->filterByLegalEntityId(legalEntityId: $legalEntity->id)
            ->filterBySyncStatus(status: JobStatus::PARTIAL)
            ->get();

        foreach ($models->reverse() as $index => $model) {
            $job = new DeclarationRequestDetailsSync(
                declarationRequest: $model,
                legalEntity: $legalEntity,
                nextEntity: $previousJob
            );

            $previousJob = $job;
        }

        // Here $job is the first job in the chain (or null if no declarationRequests)
        return $job ?? $previousJob;
    }

    /**
     * Creates a chain of ConfidantPersonSync jobs for all confidant_persons with PARTIAL sync status.
     *
     * Jobs are created in reverse order, each next job receives the previous one as nextEntity.
     * Returns the first job in the chain (or null if there are no confidant_persons).
     * So the jobs will be executed in the original order one by one.
     *
     * @param LegalEntity $legalEntity
     * @param EHealthJob|null $nextEntity The job to be executed after the chain completes (or null)
     *
     * @return EHealthJob|null The first job in the EmployeeDetailsUpsert chain, or null if there are no employees
     */
    protected function getConfidantPersonStartJob(LegalEntity $legalEntity, ?EHealthJob $nextEntity): ?EHealthJob
    {
        $job = null;

        // The incoming $nextEntity will be executed after the whole chain
        $previousJob = $nextEntity;

        $models = ConfidantPerson::with(['person', 'subjectPerson'])->filterByLegalEntityId(legalEntityId: $legalEntity->id)
            ->filterBySyncStatus(status: JobStatus::PARTIAL)
            ->get();

        foreach ($models->reverse() as $index => $model) {
            $job = new ConfidantPersonSync(
                confidantPerson: $model,
                legalEntity: $legalEntity,
                nextEntity: $previousJob
            );

            $previousJob = $job;
        }

        // Here $job is the first job in the chain (or null if no employees)
        return $job ?? $previousJob;
    }

    /**
     * Generates a chain of jobs to fetch details for all PARTIAL contract requests.
     */
    public function getContractRequestDetailsStartJob(LegalEntity $legalEntityModel, ?EHealthJob $nextEntityModel = null): ?EHealthJob
    {
        $partialRequestsList = ContractRequest::where('contractor_legal_entity_id', $legalEntityModel->uuid)
            ->where('sync_status', JobStatus::PARTIAL->value)
            ->get();

        if ($partialRequestsList->isEmpty()) {
            return $nextEntityModel;
        }

        // Build the chain of jobs from the bottom up
        $chainedJob = $nextEntityModel;

        foreach ($partialRequestsList as $requestModel) {
            $chainedJob = new ContractRequestDetailsUpsert(
                contractRequestModel: $requestModel,
                legalEntity: $legalEntityModel,
                nextEntity: $chainedJob
            );
        }

        return $chainedJob;
    }
}
