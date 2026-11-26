<?php

declare(strict_types=1);

namespace App\Listeners;

use Throwable;
use App\Enums\JobStatus;
use App\Jobs\DeclarationRegisterSync;
use App\Jobs\DeclarationsSync;
use App\Models\Declaration;
use Illuminate\Support\Facades\Auth;
use App\Events\EHealthUserLogin;
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
        // Skip if this is the first login
        if ($event->isFirstLogin) {
            return;
        }

        echo 'Regular login synchronization checking for ' . 'legalEntity:' . $event->legalEntity->id . PHP_EOL;

        // Find all failed batches for this legal entity and retry them
        $failedBatches = $this->findFailedBatchesByLegalEntity($event->legalEntity->id, 'ASC');

        if ($failedBatches->isEmpty()) {
            echo 'No failed batches found for legal entity: ' . $event->legalEntity->id . PHP_EOL;

            return;
        }

        setPermissionsTeamId($event->legalEntity->id);
        Auth::shouldUse($event->guard);
        $resumed = false;
        foreach ($failedBatches as $batch) {
            $name = $this->baseBatchName($batch->name);
            if ($name === DeclarationRegisterSync::BATCH_NAME || in_array($name, DeclarationsSync::FULL_SYNC_BATCHES, true)) {
                if (($name === DeclarationRegisterSync::BATCH_NAME) !== $event->user->usesFacilityDeclarationRegister()
                    || $event->user->cannot('sync', [Declaration::class, $event->legalEntity, $event->scopes])) {
                    continue;
                }
            }
            echo 'Found related batch: ' . $batch->name . ' id: ' . $batch->id . PHP_EOL;

            if ($batch->name === self::FIRST_LOGIN_BATCH_NAME) {
                $event->legalEntity?->setEntityStatus(JobStatus::PROCESSING);
            }

            $resumed = $this->restartBatch($batch, $event->user, $event->token, $event->legalEntity) || $resumed;
        }

        if ($resumed) {
            $event->user->notify(new SyncNotification('legal_entity', 'resumed'));
        }
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
        $errorMessage = "FirstLoginOwnerSyncronization failed for legal entity ID: {$event->legalEntity->id}";
        $errorDetails = "Error: {$exception->getMessage()}";

        // Log the error
        Log::error($errorMessage, [
            'legal_entity_id' => $event->legalEntity->id,
            'error_message' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
            'listener' => self::class,
        ]);

        // Output to console
        echo $errorMessage . PHP_EOL;
        echo $errorDetails . PHP_EOL;
        echo "Stack trace: " . $exception->getTraceAsString() . PHP_EOL;
    }

}
