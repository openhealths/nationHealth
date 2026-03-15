<?php

declare(strict_types=1);

namespace App\Listeners\eHealth;

use App\Events\EHealthUserLogin;
use App\Jobs\EmployeeRequestsSyncAll;
use App\Notifications\SyncNotification;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class EmployeeRequestActualize
{
    private const string LOG_PREFIX = '[EmployeeGlobalSync]';

    public function handle(EHealthUserLogin $event): void
    {
        // Skip if this is the first login
        if ($event->isFirstLogin) {
            return;
        }


        $user = $event->user;
        $legalEntity = $event->legalEntity;

        if (!$user->can('employee_request:read')) {
            Log::info(self::LOG_PREFIX . " User lacks permissions. Skipping.");

            return;
        }

        $cacheKey = 'employee_request_sync_ran_for_' . $legalEntity->id . '_' . now()->toDateString();

        if (Cache::has($cacheKey)) {
            Log::info(self::LOG_PREFIX . " Sync already ran today. Skipping.");

            return;
        }

        Cache::put($cacheKey, true, now()->endOfDay());

        try {
            Log::info(self::LOG_PREFIX . " Dispatching global sync batch.");

            $encryptedToken = $event->token;

            Bus::batch(
                [
                    new EmployeeRequestsSyncAll($legalEntity),
                ]
            )
                ->name('Full Employee Requests Sync for LE: ' . $legalEntity->id)
                ->withOption('legal_entity_id', $legalEntity->id)
                ->withOption('token', $encryptedToken)
                ->withOption('user', $user)
                ->then(function () use ($user) {
                    $user->notify(new SyncNotification('employee_request_full_sync', 'completed'));
                })
                ->catch(function (Batch $batch, Throwable $e) use ($user) {
                    Log::error(self::LOG_PREFIX . " Batch failed: " . $e->getMessage());
                    $user->notify(new SyncNotification('employee_request_full_sync', 'failed'));
                })
                ->onQueue('sync')
                ->dispatch();

            $user->notify(new SyncNotification('employee_request_full_sync', 'started'));

        } catch (Throwable $e) {
            Log::error(self::LOG_PREFIX . " Failed to dispatch batch: " . $e->getMessage());
            Cache::forget($cacheKey);
        }
    }
}
