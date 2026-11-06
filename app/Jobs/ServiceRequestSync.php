<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Models\Person\Person;
use App\Repositories\MedicalEvents\Repository;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Patient registry pagination and full-resource imports share one tracked batch. */
class ServiceRequestSync implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const string BATCH_NAME = 'ServiceRequestSync';

    public int $maxExceptions = 3;

    public int $timeout = 60;

    public function __construct(public int $page = 1, public ?string $resourceUuid = null, public string $status = 'active')
    {
        $this->onQueue('sync');
    }

    public function backoff(): array
    {
        return [10, 30];
    }

    public function retryUntil(): DateTimeInterface
    {
        return CarbonImmutable::now()->addHour();
    }

    public static function pageJobs(EHealthResponse $response, int $page, string $status): array
    {
        $jobs = [];
        foreach ($response->validate() as $resource) {
            $uuid = $resource['id'] ?? ($resource['uuid'] ?? null);
            if ($uuid !== null) {
                $jobs[] = new self(resourceUuid: $uuid, status: $status);
            }
        }
        if ($response->isNotLast()) {
            $jobs[] = new self(page: $page + 1, status: $status);
        }

        return $jobs;
    }

    public function handle(): void
    {
        $batch = $this->batch();
        if ($batch === null || $batch->cancelled()) {
            return;
        }
        $person = Person::findOrFail($batch->options['person_id']);
        $api = EHealth::serviceRequest()->withToken(Crypt::decryptString($batch->options['token']));
        try {
            if ($this->resourceUuid === null) {
                $response = $api->getBySearchParams($person->uuid, $batch->options['query'] + ['page' => $this->page, 'status' => $this->status]);
                $jobs = self::pageJobs($response, $this->page, $this->status);
                if ($jobs !== []) {
                    $batch->add($jobs);
                }

                return;
            }
            $resource = $api->getById($person->uuid, $this->resourceUuid, preserveApiKeys: true)->validate();
            $resource = $resource[0] ?? $resource;
            if (($resource['id'] ?? ($resource['uuid'] ?? null)) !== $this->resourceUuid) {
                throw ValidationException::withMessages(['resource' => __('referrals.messages.sync_failed')]);
            }
            Repository::serviceRequest()->importPatientResource($resource, $person);
        } catch (EHealthResponseException $exception) {
            if ($exception->getCode() === 429) {
                $this->release(max(1, (int) $exception->response->header('Retry-After') ?: 60));

                return;
            }
            // A list can be readable while individual documents require a separate approval.
            if ($exception->getCode() === 403 && $this->resourceUuid !== null) {
                Cache::add('referral-sync-denied:'.$batch->id, 0, now()->addDay());
                Cache::increment('referral-sync-denied:'.$batch->id);

                return;
            }
            if (in_array($exception->getCode(), [401, 403], true)) {
                $this->fail($exception);

                return;
            }
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        Cache::put('referral-sync-error:'.$this->batchId, match ($exception?->getCode()) {
            401 => 'sync_session_expired',
            403 => 'sync_access_denied',
            default => 'sync_failed',
        }, now()->addDay());
    }
}
