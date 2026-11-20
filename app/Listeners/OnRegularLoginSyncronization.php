<?php

declare(strict_types=1);

namespace App\Listeners;

use Throwable;
use App\Classes\eHealth\EHealth;
use App\Enums\JobStatus;
use App\Events\EHealthUserLogin;
use App\Jobs\EmployeeSync;
use App\Repositories\Repository;
use Illuminate\Support\Facades\Crypt;
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
        // Refresh existing positions even when there are no pending employee requests or failed batches.
        if (in_array(EmployeeSync::SCOPE_REQUIRED, $event->scopes, true)) {
            try {
                $this->syncEmployeeStatuses($event);
            } catch (Throwable $exception) {
                // A failed refresh must also leave login usable with a synchronous queue driver.
                report($exception);
            }
        }

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

        foreach ($failedBatches as $batch) {
            echo 'Found related batch: ' . $batch->name . ' id: ' . $batch->id . PHP_EOL;

            if ($batch->name === self::FIRST_LOGIN_BATCH_NAME) {
                $event->legalEntity?->setEntityStatus(JobStatus::PROCESSING);
            }

            $this->restartBatch($batch, $event->user, $event->token, $event->legalEntity);
        }

        $event->user->notify(new SyncNotification('legal_entity', 'resumed'));
    }

    protected function syncEmployeeStatuses(EHealthUserLogin $event): void
    {
        $party = $event->user->party;
        if (!$party?->uuid) {
            return;
        }

        $employees = $party->employees()->where('legal_entity_id', $event->legalEntity->id)->get()->keyBy('uuid');
        if ($employees->isEmpty()) {
            return;
        }

        $client = EHealth::employee()->withToken(Crypt::decryptString($event->token));
        $filters = ['legal_entity_id' => $event->legalEntity->uuid, 'party_id' => $party->uuid];
        $page = 1;
        do {
            $response = $client->getMany($filters, $page++);
            foreach ($response->validate() as $attributes) {
                $employee = $employees->get($attributes['uuid']);
                if ($employee && $attributes['party']['uuid'] === $party->uuid) {
                    Repository::employee()->syncStatus($employee, $attributes);
                }
            }
        } while ($response->isNotLast());
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
