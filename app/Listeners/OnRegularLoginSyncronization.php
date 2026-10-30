<?php

declare(strict_types=1);

namespace App\Listeners;

use Throwable;
use App\Enums\JobStatus;
use App\Events\EHealthUserLogin;
use App\Repositories\Repository;
use Illuminate\Bus\BatchRepository;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Crypt;
use App\Repositories\EHealthDatabaseBatchRepository;
use Illuminate\Support\Facades\Log;
use App\Notifications\SyncNotification;
use App\Traits\BatchLegalEntityQueries;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;

class OnRegularLoginSyncronization implements ShouldQueue
{
    use InteractsWithQueue;
    use BatchLegalEntityQueries;

    protected const string FIRST_LOGIN_BATCH_NAME = 'FirstLoginSync';

    public const int BATCHES_PER_RUN = 10;

    /**
     * This listener will be placed on the 'sync' queue
     *
     * @var string|null
     */
    public $queue = 'sync';

    /**
     * Handle the event.
     */
    public function handle(EHealthUserLogin $event): void
    {
        // A user's first local login can still resume an existing legal entity's failures.
        if ($event->failedSyncBatchIds === [] || !$this->hasCurrentSession($event)) {
            return;
        }

        echo 'Regular login synchronization checking for ' . 'legalEntity:' . $event->legalEntity->id . PHP_EOL;

        // Bound each delivery's work. Do not scan failures created by this login's own jobs.
        /** @var EHealthDatabaseBatchRepository $repository */
        $repository = app(BatchRepository::class);
        $failedBatches = $repository->getFailedSyncBatches(
            $event->legalEntity->id,
            array_slice($event->failedSyncBatchIds, 0, self::BATCHES_PER_RUN)
        );

        $resumed = $event->syncResumed;

        foreach ($failedBatches as $batch) {
            if (!$this->hasCurrentSession($event)) {
                return;
            }

            echo 'Found related batch: ' . $batch->name . ' id: ' . $batch->id . PHP_EOL;

            if (!$this->restartBatch($batch, $event->user, $event->token, $event->legalEntity, $event->scopes)) {
                continue;
            }

            $resumed = true;

            if ($batch->name === self::FIRST_LOGIN_BATCH_NAME) {
                $event->legalEntity?->setEntityStatus(JobStatus::PROCESSING);
            }
        }

        $remainingIds = array_slice($event->failedSyncBatchIds, self::BATCHES_PER_RUN);
        if ($remainingIds !== []) {
            // Reuse the queued listener with an immutable remainder. Database batch locking makes
            // redelivery safe; completed IDs are gone and cannot produce duplicate replacements.
            $continuation = clone $event;
            $continuation->failedSyncBatchIds = $remainingIds;
            $continuation->syncResumed = $resumed;
            dispatch((new CallQueuedListener(self::class, 'handle', [$continuation]))->onQueue($this->queue));
        } elseif ($resumed) {
            $event->user->notify(new SyncNotification('legal_entity', 'resumed'));
        }
    }

    private function hasCurrentSession(EHealthUserLogin $event): bool
    {
        return Repository::user()->hasSyncSessionToken(
            $event->user,
            $event->legalEntity,
            Crypt::decryptString($event->token),
            $event->sessionId
        );
    }

    /**
     * Handle a job failure.
     *
     * @param  EHealthUserLogin  $event
     * @param  Throwable  $exception
     * @return void
     */
    public function failed(EHealthUserLogin $event, Throwable $exception): void
    {
        $errorMessage = "OnRegularLoginSyncronization failed for legal entity ID: {$event->legalEntity->id}";
        $errorDetails = "Error: {$exception->getMessage()}";

        // Log the error
        Log::error($errorMessage, [
            'legalEntityId' => $event->legalEntity->id,
            'errorMessage' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
            'listener' => self::class,
        ]);

        // Output to console
        echo $errorMessage . PHP_EOL;
        echo $errorDetails . PHP_EOL;
        echo "Stack trace: " . $exception->getTraceAsString() . PHP_EOL;
    }

}
