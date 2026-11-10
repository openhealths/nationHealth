<?php

declare(strict_types=1);

namespace App\Traits;

use App\Classes\eHealth\EHealth;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Jobs\CarePlanFullSync;
use App\Models\Person\Person;
use App\Models\User;
use App\Notifications\SyncNotification;
use App\Notifications\RemoteEHealthLinksNotification;
use App\Repositories\Repository;
use Illuminate\Bus\Batch;
use Illuminate\Bus\BatchRepository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use RuntimeException;
use Throwable;

trait HandlesCarePlanSync
{
    use BatchLegalEntityQueries;

    protected function startCarePlanPatientSync(Person $person, bool $firstPageSynchronously): ?string
    {
        $legalEntity = legalEntity();
        $token = Session::get(config('ehealth.api.oauth.bearer_token'));
        $user = Auth::user();
        $name = CarePlanFullSync::BATCH_NAME.'_'.$person->uuid;
        $lock = Cache::lock('care-plan-sync:'.$legalEntity->id.':'.$person->uuid, 120);
        if (!$lock->get()) {
            throw new RuntimeException(__('care-plan.sync_running'));
        }

        try {
            $running = $this->findRunningBatchesByLegalEntity($legalEntity->id)->firstWhere('name', $name);
            if ($running !== null) {
                return $running->id;
            }
            $patientContext = [
                'patient_uuid' => $person->uuid,
                'person_id' => $person->id,
                'patient_care_plan_registry' => true,
            ];
            $jobs = [new CarePlanFullSync($legalEntity, standalone: true, patientContext: $patientContext)];
            if ($firstPageSynchronously) {
                $response = EHealth::carePlan()->withToken($token)->getBySearchParams($person->uuid, ['page' => 1]);
                $summaries = $response->validate();
                Repository::carePlan()->storeSummaries($summaries, $person->id, $legalEntity->id);
                $jobs = CarePlanFullSync::detailJobs($summaries, $legalEntity, $patientContext);
                if ($response->isNotLast()) {
                    $jobs[] = new CarePlanFullSync($legalEntity, page: 2, standalone: true, patientContext: $patientContext);
                }
            }
            if ($jobs === []) {
                return null;
            }
            $userId = $user->id;
            $connection = (string) config('queue.default');
            if (config('queue.connections.'.$connection.'.driver') === 'sync') {
                $connection = 'database';
            }
            $batch = Bus::batch($jobs)
                ->allowFailures()
                ->name($name)
                ->withOption('legal_entity_id', $legalEntity->id)
                ->withOption('patient_uuid', $person->uuid)
                ->withOption('person_id', $person->id)
                ->withOption('patient_care_plan_registry', true)
                ->withOption('token', Crypt::encryptString($token))
                ->withOption('user', $user)
                ->then(static function (Batch $batch) use ($userId): void {
                    $denied = (int) Cache::get('care-plan-sync-denied:'.$batch->id, 0);
                    User::find($userId)?->notify($denied > 0
                        ? new RemoteEHealthLinksNotification(__('care-plan.sync_partial_access', ['count' => $denied]), 'warning')
                        : new SyncNotification('care_plan', 'completed'));
                })
                ->catch(static function (Batch $batch, Throwable $exception) use ($userId): void {
                    Log::warning('Care Plan synchronization batch failed', ['batchId' => $batch->id, 'exceptionType' => $exception::class]);
                    $user = User::find($userId);
                    if ($exception instanceof EHealthResponseException && in_array($exception->getCode(), [401, 403], true)) {
                        $message = $exception->getCode() === 403 ? 'care-plan.sync_access_denied' : 'care-plan.sync_session_expired';
                        $user?->notify(new RemoteEHealthLinksNotification(__($message), 'error'));

                        return;
                    }
                    $user?->notify(new SyncNotification('care_plan', 'failed'));
                })
                ->finally(static function (Batch $batch): void {
                    // Laravel keeps failed jobs in pendingJobs even after every allowed job was attempted.
                    if ($batch->hasFailures()) {
                        app(BatchRepository::class)->markAsFinished($batch->id);
                    }
                })
                ->onConnection($connection)
                ->onQueue('sync')
                ->dispatch();

            return $batch->id;
        } finally {
            $lock->release();
        }
    }

    protected function carePlanSyncOutcome(array $batchIds): ?array
    {
        $success = true;
        $denied = 0;
        foreach ($batchIds as $batchId) {
            $batch = Bus::findBatch($batchId);
            if ($batch !== null && !$batch->finished() && !$batch->cancelled()) {
                return null;
            }
            $success = $success && $batch !== null && $batch->finished() && !$batch->hasFailures() && !$batch->cancelled();
            $denied += (int) Cache::get('care-plan-sync-denied:'.$batchId, 0);
        }

        return compact('success', 'denied');
    }

    protected function flashCarePlanSyncOutcome(array $outcome): void
    {
        if (!$outcome['success']) {
            Session::flash('error', __('care-plan.sync_failed'));
        } elseif ($outcome['denied'] > 0) {
            Session::flash('warning', __('care-plan.sync_partial_access', ['count' => $outcome['denied']]));
        } else {
            Session::flash('success', __('care-plan.sync_success'));
        }
    }
}
