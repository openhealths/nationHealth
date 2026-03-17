<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;
use App\Models\User;
use App\Enums\JobStatus;
use Illuminate\Bus\Batch;
use App\Traits\FormTrait;
use App\Jobs\CompleteSync;
use App\Models\LegalEntity;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Bus;
use Illuminate\Bus\BatchRepository;
use Illuminate\Support\Facades\Crypt;
use App\Traits\BatchLegalEntityQueries;
use App\Notifications\SyncNotification;
use Illuminate\Queue\InteractsWithQueue;
use App\Classes\eHealth\EHealthResponse;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Queue\ShouldQueue;

abstract class EHealthJob implements ShouldQueue
{
    use Queueable;
    use Batchable;
    use FormTrait;
    use InteractsWithQueue;
    use BatchLegalEntityQueries;

    public const string BATCH_NAME = 'FirstLoginSync';

    public const string SCOPE_REQUIRED = '';

    /**
     * The type/name of entity this job is related to.
     * Used for setting the status of the legal entity's sync process.
     *
     * @const string Empty string constant representing the legal entity itself
     */
    public const string ENTITY = '';

    /** @var int Rate limit delay in seconds (50 requests per minute = 1 request every 1.2s, using 2s for safety) */
    protected const int RATE_LIMIT_DELAY = 2;

    protected const array ERR_TO_RETRY = [401, 403, 429];

    /**
     * Authentication token for EHealth API requests
     *
     * @var string
     */
    protected string $token = '';

    /**
     * The user associated with the job, if has an active session
     *
     * @var User|null
     */
    public ?User $user = null;

    /**
     * Amount of times to attempt the job if it fails.
     *
     * @var int
     */
    public int $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public int $timeout = 60;

    /**
     * Amount of time (in seconds) to wait before retrying the job if it fails.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [3, 10, 30];
    }

    /**
     * Get the middleware the job should pass through.
     *
     * @return array
     */
    public function middleware(): array
    {
        // Ensure the job has a user with an active session
        $this->user ??= $this->batch()->options['user'];

        return $this->getAdditionalMiddleware();
    }

    public function __construct(
        public ?LegalEntity $legalEntity = null,
        protected ?EHealthJob $nextEntity = null,
        protected bool $isFirstLogin = false,
        protected int $page = 1,
        public bool $standalone = false
    ) {
        $this->onQueue('sync');
    }

    public function handle(): void
    {
        echo "Processing Job: " . static::BATCH_NAME . " Page: " . $this->page . ' is First Login: ' . ($this->isFirstLogin ? 'Yes' : 'No') . PHP_EOL;
        echo "Start from user: " . ($this->user ? $this->user->id : 'No user found') . PHP_EOL;
        echo "Legal Entity ID: " . ($this->legalEntity ? $this->legalEntity->id : 'No legal entity found') . PHP_EOL;

        if (static::BATCH_NAME !== CompleteSync::BATCH_NAME) {
            $this->setEntityStatus(JobStatus::PROCESSING);
        }

        $this->token = Crypt::decryptString($this->batch()->options['token'] ?? '');

        $response = $this->sendRequest($this->token);

        $this->processResponse($response);

        $rdata = $response?->json();

        echo "Response PAGE: " . (data_get($rdata, 'paging.page_number') ?? 'N/A') . " of " . (data_get($rdata, 'paging.total_pages') ?? 'N/A') . PHP_EOL;

        // Check if there are more pages to process
        if ($response?->isNotLast()) {
            echo "Scheduling next page job: " . static::BATCH_NAME . " Page: " . $this->page . " Next Page: " . ($this->page + 1) . PHP_EOL;
            $this->batch()
                ?->add(new static(legalEntity: $this->legalEntity, page: $this->page + 1, isFirstLogin: $this->isFirstLogin, nextEntity: $this->nextEntity, standalone: $this->standalone)
                    ->delay(now()->addSeconds(self::RATE_LIMIT_DELAY)));

            return;
        }

        $nextJob = $this->getNextEntityJob();

        if ($nextJob !== null) {
            echo "Scheduling next job: " . $nextJob::BATCH_NAME . " from " . static::BATCH_NAME.  PHP_EOL;

            if ($nextJob::BATCH_NAME === CompleteSync::BATCH_NAME || $nextJob::BATCH_NAME !== static::BATCH_NAME) {
                echo "Job COMPLETED: " . static::BATCH_NAME . PHP_EOL;

                $this->setEntityStatus(JobStatus::COMPLETED);
            }

            Bus::batch([$nextJob])
                ->name($nextJob::BATCH_NAME)
                ->withOption('legal_entity_id', $this->legalEntity->id)
                ->withOption('token', Crypt::encryptString($this->token)) // Passing the same token to the next job
                ->withOption('user', $this->user) // Passing the same user to the next job
                ->onQueue('sync')
                ->dispatch();
        }
    }

    // Handle job failure
    public function failed(?Throwable $exception): void
    {
        // It is need beacuse if job is failed the middleware doesn't called
        $olduser = $this->user ?? ($this->batch()->options['user'] ?? null);

        Log::channel('e_health_errors')->error('Sync job failed: ', [
            'EXCEPTION' => $exception::class,
            'message' => $exception->getMessage(),
            'attempts' => $this->attempts(),
            'batch_id' => $this->batch()?->id,
            'batch_name' => static::BATCH_NAME,
            'user_id' => $olduser?->id,
        ]);

        echo "Job FAILED: " . static::BATCH_NAME . " Exception type: " . $exception::class . " Code: " . $exception->getCode() . " Error: " . $exception->getMessage() . PHP_EOL;

        // Set entity status based on error code
        if (!in_array($exception->getCode(), static::ERR_TO_RETRY, true)) {
            $this->setEntityStatus(JobStatus::FAILED);

            $olduser?->notify(new SyncNotification('legal_entity', 'failed'));

            return;
        }

        $this->user = $this->getUserWithActiveSession($olduser);
        echo "Retrying with new user: " . ($this->user ? $this->user->id : 'No user found') . PHP_EOL;

        if ($this->user) {
            $this->token = $this->getToken();

            // Prepare the current job for retry
            $retryJob = new static(
                legalEntity: $this->legalEntity,
                page: $this->page,
                isFirstLogin: $this->isFirstLogin,
                nextEntity: $this->nextEntity
            );

            $failedJobUuids = $this->batch()?->failedJobIds ?? [];
            $failedBatchId = $this->batch()?->id;

            // For first login jobs, prefix the retry batch name with "FirstLoginSync_". For others, just use "retry_"
            $retryName = ($this->isFirstLogin ? self::BATCH_NAME . '_' : '') . 'retry_' . static::BATCH_NAME;

            Bus::batch([$retryJob])
                ->name(name: $retryName)
                ->withOption('legal_entity_id', $this->legalEntity->id)
                ->withOption('token', Crypt::encryptString($this->token)) // Passing the same token to the next job
                ->withOption('user', $this->user)
                ->onQueue('sync')
                ->finally(function (Batch $batch) use ($failedJobUuids) {
                    if (!empty($failedJobUuids)) {
                        // Clean up failed jobs from previous batch to avoid clutter
                        DB::table('failed_jobs')->whereIn('uuid', $failedJobUuids)->delete();

                        echo "Cleaned up failed jobs from previous batch: " . implode(', ', $failedJobUuids) . PHP_EOL;
                    }
                })->dispatch();

            app(BatchRepository::class)->delete($failedBatchId);
        } else {
            $this->setEntityStatus(JobStatus::PAUSED);

            echo "Job PAUSED due to error code: " . $exception->getCode() . PHP_EOL;

            // Notifications should be last in the failed() method beacuse it stopped job working
            // TODO: find out why notification stopped job's working
            $olduser->notify(new SyncNotification('legal_entity', 'paused'));
        }
    }

    /**
     * Get authentication token with deferred initialization
     *
     * @return string
     */
    protected function getToken(): string
    {
        if (!$this->user) {
            return '';
        }

        $session = DB::table('sessions')->where('user_id', $this->user->id)->first();

        if (!$session) {
            return '';
        }

        $payload = unserialize(base64_decode($session->payload));

        $token = $payload['auth_token'] ?? '';

        return $token;
    }

    // Get next entity job if needed
    protected function getNextEntityJob(): ?EHealthJob
    {
        return $this->nextEntity;
    }

    /**
     * Sets the job entity synchronization status.
     *
     * @param  JobStatus  $status  The new status to be set for the job entity
     * @return void
     */
    protected function setEntityStatus(JobStatus $status): void
    {
        $entity = static::ENTITY;

        $this->legalEntity->setEntityStatus($status, $entity);

        if ($this->isFirstLogin && ($status === JobStatus::PAUSED || $status === JobStatus::FAILED)) {
            // If first login then legal entity status should be paused/failed if any of the entities failed/paused
            $this->legalEntity->setEntityStatus(status: $status);
        }
    }

    /**
     * Send a synchronization notification to the user for a specific entity and action.
     *
     * @param  string|null  $entityType  The type of entity being synchronized (e.g., 'legal_entity', 'division', etc.)
     * @param  string  $action  The action performed (e.g., 'started', 'completed', 'failed', 'paused')
     * @return void
     */
    protected function sendEntityNotification(?string $entityType, string $action): void
    {
        $this->user->notify(new SyncNotification($entityType, $action));
    }

    /**
     * Get user with active session for the legal entity
     *
     * This method performs a complex database query to find users that meet two specific criteria:
     * 1. User must not be the current user (to avoid reusing the same user)
     * 2. User must be an employee of the specified legal entity
     * 3. User must have an active session (record exists in sessions table)
     *
     * The query uses Eloquent's whereHas() and whereExists() methods for performance optimization:
     * - whereHas('employees'): Uses LEFT JOIN to check user-employee relationship through employees table
     * - whereExists(): Uses EXISTS subquery to check for session records without joining session data
     *
     * @return User|null
     */
    protected function getUserWithActiveSession(User $user): ?User
    {
        $legalEntity = $this->legalEntity;

        setPermissionsTeamId($legalEntity->id);

        // Execute complex query to find users matching three criteria
        $users = User::with('roles', 'permissions')
            // FIRST CRITERIA: Exclude current user's party from search (if it still logined but does not have valid token)
            ->whereNot('users.party_id', $user->party_id ?? 0)
            // SECOND CRITERIA: User must be employee of this legal entity
            // This uses nested whereHas to check User -> Employee relationship
            // SQL equivalent: EXISTS (SELECT 1 FROM employees WHERE employees.user_id = users.id AND employees.legal_entity_id = ?)
            ->whereHas('employees', fn ($query) => $query->where('legal_entity_id', $legalEntity->id))
            // THIRD CRITERIA: User must have active session
            // This creates an EXISTS subquery to check sessions table
            // Using SELECT 1 for performance - we only need to verify existence, not retrieve data
            // SQL equivalent: EXISTS (SELECT 1 FROM sessions WHERE sessions.user_id = users.id)
            ->whereExists(
                fn ($query) => $query->select(DB::raw(1))
                    ->from('sessions')
                    // whereColumn creates correlation between main query and subquery
                    // This ensures we check sessions for the specific user from outer query
                    ->whereColumn('sessions.user_id', 'users.id')
            )
            ->get();

        echo "Found " . $users->count() . " users with active sessions for legal entity {$legalEntity->id}" . PHP_EOL;

        // $users here is a Collection of User models. $userKey is the key/index of the first user that has the required scope/permission
        $userKey = $users->search(fn (User $user) => $user->hasPermissionTo($this->requiredScope(), 'ehealth'));

        // If no user found with required scope, return null
        return $users->get($userKey);
    }

    /**
     * Get the required scope for the job.
     *
     * This method returns the scope required for the job to execute API requests or perform actions.
     * Child classes should override the SCOPE_REQUIRED constant to specify their own scope.
     *
     * @return string The required scope for the job.
     */
    protected function requiredScope(): string
    {
        return static::SCOPE_REQUIRED;
    }

    // Get data from EHealth API
    abstract protected function sendRequest(string $token): PromiseInterface|EHealthResponse|null;

    // Store or update data in the database
    abstract protected function processResponse(?EHealthResponse $response): void;

    /**
     * Get additional middleware specific to the job implementation.
     *
     * This method must be implemented by child classes to define job-specific middleware
     * that will be executed AFTER the base EnsureUserHasActiveSession middleware.
     *
     * Common middleware examples:
     * - RateLimited('rate-limiter-name') for API rate limiting
     * - Custom validation middleware
     * - Logging middleware
     *
     * @return array Array of middleware instances
     *
     * @example
     * protected function getAdditionalMiddleware(): array
     * {
     *     return [
     *         new RateLimited('ehealth-api-calls'),
     *         new CustomLoggingMiddleware(),
     *     ];
     * }
     */
    abstract protected function getAdditionalMiddleware(): array;
}
