<?php

declare(strict_types=1);

namespace App\Listeners;

use Throwable;
use App\Enums\Status;
use App\Enums\JobStatus;
use App\Events\EHealthUserLogin;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use App\Jobs\LegalEntityDetailsSync;
use Illuminate\Support\Facades\Auth;
use App\Notifications\SyncNotification;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Listeners\Synchronization\FirstLoginSyncChain;

class FirstLoginOwnerSynchronization implements ShouldQueue
{
    use InteractsWithQueue;

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
        // This need to be user with roles and permissions loaded
        setPermissionsTeamId($event->legalEntity->id);

        Auth::shouldUse($event->guard);

        $user = $event->user->load('roles', 'permissions', 'party');

        $needsDetailsSync = $event->legalEntity->status === Status::NEW->value;

        // A NEW entity needs a full synchronization after creating or reconnecting a connection.
        if ($event->legalEntity->syncStatus
            && (!$needsDetailsSync || $event->legalEntity->syncStatus !== JobStatus::COMPLETED->value)
        ) {
            return;
        }

        // TODO: remove it after testing
        echo 'First login synchronization started. ' . 'legalEntity:' . $event->legalEntity->id. PHP_EOL;

        $initialJob = $needsDetailsSync
            ? new LegalEntityDetailsSync(
                legalEntity: $event->legalEntity,
                isFirstLogin: true,
                scopes: $event->scopes
            )
            : app(FirstLoginSyncChain::class)->build($event->legalEntity, $event->scopes, $user);

        Bus::batch([$initialJob])
            ->name('FirstLoginSync')
            ->withOption('legal_entity_id', $event->legalEntity->id)
            ->withOption('token', $event->token) // Here token is encrypted
            ->withOption('user', $user)
            ->withOption('sync_entity', '') // Complete the overall sync only after the final job.
            ->onQueue('sync')
            ->dispatch();

        $event->legalEntity->setEntityStatus(JobStatus::PROCESSING);

        $user->notify(new SyncNotification('legal_entity', 'started'));
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
