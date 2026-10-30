<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;
use App\Models\User;
use ReflectionClass;
use App\Enums\JobStatus;
use Illuminate\Bus\Batch;
use App\Traits\FormTrait;
use App\Jobs\CompleteSync;
use App\Models\LegalEntity;
use App\Repositories\Repository;
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
use App\Exceptions\EHealth\EHealthResponseException;
use App\Jobs\Middleware\UseEHealthPermissionContext;

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

    /** @var list<string> Token fingerprints rejected during this automatic retry sequence. */
    protected array $rejectedTokenHashes = [];

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

        return [new UseEHealthPermissionContext(), ...$this->getAdditionalMiddleware()];
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

        try {
            $response = $this->sendRequest($this->token);
        } catch (EHealthResponseException $exception) {
            if (!in_array($exception->getCode(), [401, 403], true) || $this->job === null) {
                throw $exception;
            }

            // A denied token cannot recover through backoff. Enter the shared failure lifecycle
            // immediately; temporary failures and rate limits retain the worker's delayed retries.
            $this->fail($exception);

            return;
        }

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

            $syncEntity = $this->batch()->options['sync_entity'] ?? null;

            if (
                $syncEntity !== static::ENTITY
                && ($nextJob::BATCH_NAME === CompleteSync::BATCH_NAME || $nextJob::ENTITY !== static::ENTITY)
            ) {
                echo "Job COMPLETED: " . static::BATCH_NAME . PHP_EOL;

                $this->setEntityStatus(JobStatus::COMPLETED);
            }

            Bus::batch([$nextJob])
                ->name($nextJob::BATCH_NAME)
                ->withOption('legal_entity_id', $this->legalEntity->id)
                ->withOption('token', Crypt::encryptString($this->token)) // Passing the same token to the next job
                ->withOption('user', $this->user) // Passing the same user to the next job
                ->withOption('sync_entity', $syncEntity)
                ->onQueue('sync')
                ->dispatch();
        }
    }

    // Handle job failure
    public function failed(?Throwable $exception): void
    {
        // It is need because if job is failed the middleware doesn't called
        $oldUser = $this->user ?? ($this->batch()->options['user'] ?? null);

        Log::channel('e_health_errors')->error('Sync job failed: ', [
            'EXCEPTION' => $exception::class,
            'message' => $exception->getMessage(),
            'attempts' => $this->attempts(),
            'batch_id' => $this->batch()?->id,
            'batch_name' => static::BATCH_NAME,
            'user_id' => $oldUser?->id,
        ]);

        echo "Job FAILED: " . static::BATCH_NAME . " Exception type: " . $exception::class . " Code: " . $exception->getCode() . " Error: " . $exception->getMessage() . PHP_EOL;

        // Set entity status based on error code
        if (!in_array($exception->getCode(), static::ERR_TO_RETRY, true)) {
            $this->setEntityStatus(JobStatus::FAILED);

            $oldUser?->notify(new SyncNotification('legal_entity', 'failed'));

            return;
        }

        $this->rejectedTokenHashes = $this->batch()?->options['rejected_token_hashes'] ?? [];
        if (in_array($exception->getCode(), [401, 403], true)) {
            $failedToken = Crypt::decryptString($this->batch()->options['token']);
            $this->rejectedTokenHashes[] = hash('sha256', $failedToken);
        }

        $this->user = $oldUser === null || !$this->shouldRetryWithAnotherUser($exception)
            ? null
            : $this->getUserWithActiveSession($oldUser);
        echo "Retrying with new user: " . ($this->user ? $this->user->id : 'No user found') . PHP_EOL;

        $this->token = $this->getToken();

        if ($this->user && $this->token !== '') {

            $reflection = new ReflectionClass(static::class);

            $args = [];

            // Use reflection to get the constructor parameters of the current job class
            // This need due to some of job's params doesn't exist in all job classes,
            // so we need to dynamically determine which parameters to pass when creating a new instance of the job for retry
            foreach ($reflection->getConstructor()?->getParameters() ?? [] as $param) {
                $name = $param->getName();

                if (property_exists($this, $name)) {
                    $args[$name] = $this->{$name};
                }
            }

            $retryJob = new static(...$args);
            $retryJob->setUserContext($this->user);

            $failedJobUuids = $this->batch()?->failedJobIds ?? [];
            $failedBatchId = $this->batch()?->id;

            // For first login jobs, prefix the retry batch name with "FirstLoginSync_". For others, just use "retry_"
            $retryName = ($this->isFirstLogin ? self::BATCH_NAME . '_' : '') . 'retry_' . static::BATCH_NAME;

            Bus::batch([$retryJob])
                ->name(name: $retryName)
                ->withOption('legal_entity_id', $this->legalEntity->id)
                ->withOption('token', Crypt::encryptString($this->token)) // Passing the same token to the next job
                ->withOption('user', $this->user)
                ->withOption('rejected_token_hashes', array_values(array_unique($this->rejectedTokenHashes)))
                ->withOption('sync_entity', $this->batch()?->options['sync_entity'] ?? null)
                ->withOption('patient_uuid', $this->batch()?->options['patient_uuid'] ?? null)
                ->withOption('person_id', $this->batch()?->options['person_id'] ?? null)
                ->withOption('preperson_id', $this->batch()?->options['preperson_id'] ?? null)
                ->withOption('has_patient_access', $this->batch()?->options['has_patient_access'] ?? false)
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
            $oldUser?->notify(new SyncNotification('legal_entity', 'paused'));
        }
    }

    /** Allow jobs to pause on a definitive denial while reusing the common failed() lifecycle. */
    protected function shouldRetryWithAnotherUser(?Throwable $exception): bool
    {
        return true;
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

        return $this->syncSessionToken($this->user);
    }

    private function syncSessionToken(User $user): string
    {
        $payload = Repository::user()->getSyncSessionPayload($user);
        $token = $payload[config('ehealth.api.oauth.bearer_token')] ?? null;
        $scopes = $payload[config('ehealth.api.oauth.token_scopes')] ?? [];
        $expiresAt = $payload['auth_token_expires_at'] ?? null;

        if (!is_string($token) || $token === '' || !is_array($scopes)
            || !$this->hasRequiredScopes($scopes)
            || ($payload['ehealth_legal_entity_uuid'] ?? null) !== $this->legalEntity->uuid
            || ($expiresAt !== null && (!is_numeric($expiresAt) || (int) $expiresAt <= now()->timestamp))
        ) {
            return '';
        }

        return $token;
    }

    /**
     * Get next entity job if needed.
     *
     * @return EHealthJob|null
     */
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

    /** Find a different employee whose bound session can execute the saved chain. */
    protected function getUserWithActiveSession(User $user): ?User
    {
        // The serialized job retains its legal entity, but Spatie reads its team from a separate,
        // process-wide resolver. A long-running worker may still hold the preceding job's team
        // (or no team). Set it before eager-loading permissions so their pivot is scoped to this LE.
        $previousTeamId = getPermissionsTeamId();
        setPermissionsTeamId($this->legalEntity->id);

        try {
            // The repository narrows the search to other employees with a current bound session.
            // Direct eHealth permissions are required for every saved step; role permissions alone
            // do not prove that OAuth granted these scopes to the candidate's actual access token.
            return Repository::user()->getSyncRetryCandidates($user, $this->legalEntity->id)
                ->first(function (User $candidate): bool {
                    $permissions = $candidate->permissions->where('guard_name', 'ehealth')->pluck('name')->all();

                    // Read the token and its scopes together from the current database binding.
                    // Reject missing/expired sessions, a token for another LE, or insufficient scopes.
                    // first() returns null when none qualify, without treating search(false) as key 0.
                    if (!$this->hasRequiredScopes($permissions)) {
                        return false;
                    }

                    $token = $this->syncSessionToken($candidate);

                    // Never rotate back to an API-rejected token, even if its local session is
                    // still active. A later login starts a new sequence with fresh credentials.
                    return $token !== ''
                        && !in_array(hash('sha256', $token), $this->rejectedTokenHashes, true);
                });
        } finally {
            // Do not leak this job's permission context into the caller or the worker's next job,
            // including when the query or session decoding fails with an exception.
            setPermissionsTeamId($previousTeamId);
        }
    }

    /** Check only saved steps; creating dynamic next jobs may have side effects. */
    public function hasRequiredScopes(array $scopes): bool
    {
        return ($this->requiredScope() === '' || in_array($this->requiredScope(), $scopes, true))
            && ($this->nextEntity?->hasRequiredScopes($scopes) ?? true);
    }

    public function setUserContext(User $user): void
    {
        $this->user = $user;
        $this->nextEntity?->setUserContext($user);
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

    /**
     * Get data from EHealth API.
     *
     * @param  string  $token
     * @return PromiseInterface|EHealthResponse|null
     */
    abstract protected function sendRequest(string $token): PromiseInterface|EHealthResponse|null;

    /**
     * Store or update data in the database.
     *
     * @param  EHealthResponse|null  $response
     * @return void
     */
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
