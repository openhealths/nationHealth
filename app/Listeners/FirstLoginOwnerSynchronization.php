<?php

declare(strict_types=1);

namespace App\Listeners;

use Throwable;
use App\Enums\User\Role;
use App\Enums\JobStatus;
use App\Jobs\EmployeeSync;
use App\Jobs\DivisionSync;
use App\Jobs\EquipmentSync;
use App\Models\LegalEntity;
use App\Jobs\LegalEntitySync;
use App\Jobs\DeclarationsSync;
use App\Jobs\EmployeeRoleSync;
use App\Events\EHealthUserLogin;
use App\Jobs\PartyVerificationSync;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Auth;
use App\Jobs\EmployeeRequestsSyncAll;
use App\Notifications\SyncNotification;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;

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

        // Check if it's the first login to the Legal Entity
        if ($event->legalEntity->syncStatus) {
            return;
        }

        // TODO: remove it after testing
        echo 'First login synchronization started. ' . 'legalEntity:' . $event->legalEntity->id. PHP_EOL;

        $legalEntityType = $event->legalEntity->type->name;

        // Create a chain of jobs for synchronization

        // This is the last job in the chain
        $nextJob = new LegalEntitySync(
            legalEntity: $event->legalEntity,
            isFirstLogin: true
        );

        if ($legalEntityType !== LegalEntity::TYPE_PHARMACY && !$event->user->hasAllowedRole(Role::REORGANIZATION_OWNER)) {
            $nextJob = new EquipmentSync(
                legalEntity: $event->legalEntity,
                nextEntity: $nextJob,
                isFirstLogin: true
            );
        }

        if ($legalEntityType !== LegalEntity::TYPE_PHARMACY && $legalEntityType !== LegalEntity::TYPE_EMERGENCY) {
            $nextJob = new DeclarationsSync(
                legalEntity: $event->legalEntity,
                nextEntity: $nextJob,
                isFirstLogin: true
            );
        }

        $nextJob = new EmployeeRoleSync(
            legalEntity: $event->legalEntity,
            nextEntity: $nextJob,
            isFirstLogin: true
        );

        $nextJob = new EmployeeSync(
            legalEntity: $event->legalEntity,
            nextEntity: $nextJob,
            isFirstLogin: true
        );

        $nextJob = new PartyVerificationSync(
            legalEntity: $event->legalEntity,
            nextEntity: $nextJob,
            isFirstLogin: true
        );

        $nextJob = new EmployeeRequestsSyncAll(
            legalEntity: $event->legalEntity,
            nextEntity: $nextJob,
            isFirstLogin: true
        );

        $initialJob = new DivisionSync(
            legalEntity: $event->legalEntity,
            nextEntity: $nextJob,
            isFirstLogin: true
        );

        Bus::batch([$initialJob])
            ->name('FirstLoginSync')
            ->withOption('legal_entity_id', $event->legalEntity->id)
            ->withOption('token', $event->token) // Here token is encrypted
            ->withOption('user', $user)
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
