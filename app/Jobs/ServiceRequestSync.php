<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\EHealthResponse;
use App\Core\EHealthJob;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Models\Person\Person;
use App\Repositories\MedicalEvents\Repository;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Foundation\Bus\Dispatchable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Patient registry pagination and full-resource imports share one tracked batch. */
class ServiceRequestSync extends EHealthJob
{
    use Dispatchable;

    public const string BATCH_NAME = 'ServiceRequestSync';

    public const string SCOPE_REQUIRED = 'service_request:read';

    public int $tries = 0;

    public int $maxExceptions = 3;

    private ?Person $person = null;

    public function __construct(public int $page = 1, public ?string $resourceUuid = null, public string $status = 'active')
    {
        parent::__construct(page: $page, standalone: true);
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

    /** Keep all statuses and detail imports in the patient batch without facility-sync status changes. */
    public function handle(): void
    {
        $batch = $this->batch();
        if ($batch === null || $batch->cancelled()) {
            return;
        }
        $this->token = Crypt::decryptString($batch->options['token']);
        try {
            $this->processResponse($this->sendRequest($this->token));
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

    protected function sendRequest(string $token): PromiseInterface|EHealthResponse
    {
        $person = $this->person();
        $api = EHealth::serviceRequest()->withToken($token);
        if ($this->resourceUuid === null) {
            // Each continuation owns its cursor and status; shared access filters must not override them.
            $query = array_replace($this->batch()->options['query'], ['page' => $this->page, 'status' => $this->status]);

            return $api->getBySearchParams($person->uuid, $query);
        }

        return $api->getById($person->uuid, $this->resourceUuid, preserveApiKeys: true);
    }

    protected function processResponse(?EHealthResponse $response): void
    {
        if ($response === null) {
            return;
        }
        if ($this->resourceUuid === null) {
            $jobs = self::pageJobs($response, $this->page, $this->status);
            if ($jobs !== []) {
                $this->batch()->add($jobs);
            }

            return;
        }
        $resource = $response->validate();
        $resource = $resource[0] ?? $resource;
        if (($resource['id'] ?? ($resource['uuid'] ?? null)) !== $this->resourceUuid) {
            throw ValidationException::withMessages(['resource' => __('referrals.messages.sync_failed')]);
        }
        Repository::serviceRequest()->importPatientResource($resource, $this->person());
    }

    protected function getAdditionalMiddleware(): array
    {
        return [];
    }

    private function person(): Person
    {
        return $this->person ??= Person::findOrFail($this->batch()->options['person_id']);
    }

    /** Registry callbacks finalize this batch; facility-wide retry batches would lose its query and tracking. */
    public function failed(?Throwable $exception): void
    {
        Log::channel('e_health_errors')->error('Patient referral synchronization failed', [
            'exceptionType' => $exception === null ? null : $exception::class,
            'batchId' => $this->batchId, 'page' => $this->page, 'status' => $this->status,
        ]);
        Cache::put('referral-sync-error:'.$this->batchId, match ($exception?->getCode()) {
            401 => 'sync_session_expired',
            403 => 'sync_access_denied',
            default => 'sync_failed',
        }, now()->addDay());
    }
}
