<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\EHealthResponse;
use App\Core\EHealthJob;
use App\Enums\Composition\CompositionJobStatus;
use App\Enums\JobStatus;
use App\Exceptions\EHealth\EHealthException;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\CompositionOperation;
use App\Repositories\MedicalEvents\Repository;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Validation\ValidationException;

class CompositionAsyncProcessing extends EHealthJob
{
    public const string BATCH_NAME = 'CompositionAsyncProcessing';

    public const string SCOPE_REQUIRED = 'composition:read';

    public int $maxExceptions = 3;

    public function __construct(public CompositionOperation $operation, ?LegalEntity $legalEntity = null)
    {
        parent::__construct(legalEntity: $legalEntity, standalone: true);
    }

    public function retryUntil(): DateTimeInterface
    {
        return CarbonImmutable::now()->addDay();
    }

    protected function sendRequest(string $token): PromiseInterface|EHealthResponse|null
    {
        $this->operation->refresh();
        if ($this->operation->status !== CompositionJobStatus::PENDING->value) {
            return null;
        }

        try {
            return EHealth::composition()->withToken($token)->getAsyncJobStatus($this->operation->remoteJobId);
        } catch (EHealthException) {
            Repository::compositionOperation()->recordRetryError($this->operation, __('compositions.errors.job_status_unavailable'));
            $this->release(10);

            return null;
        }
    }

    protected function processResponse(?EHealthResponse $response): void
    {
        if ($response === null) {
            return;
        }
        try {
            $finished = Repository::compositionOperation()->processResult($this->operation, $response->validate(), $this->token);
        } catch (ValidationException) {
            Repository::compositionOperation()->recordRetryError($this->operation, __('compositions.errors.invalid_response'));
            $finished = false;
        }
        if (!$finished) {
            $this->release(min(5 * 2 ** min($this->attempts() - 1, 6), 300));
        }
    }

    protected function getAdditionalMiddleware(): array
    {
        return [new RateLimited('ehealth-remote-job-get'), (new WithoutOverlapping('composition-job:'.$this->operation->id))->releaseAfter(5)->expireAfter(120)];
    }

    protected function getNextEntityJob(): ?EHealthJob
    {
        return null;
    }

    protected function setEntityStatus(JobStatus $status): void
    {
        if (!in_array($status, [JobStatus::FAILED, JobStatus::PAUSED], true)) {
            return;
        }

        $this->operation->refresh();
        if ($this->operation->status !== CompositionJobStatus::PENDING->value) {
            return;
        }

        $response = $this->operation->job->response_data ?? [];
        $response['errors'] = ($response['errors'] ?? []) ?: [__('compositions.errors.async_processing_failed')];
        Repository::compositionOperation()->fail($this->operation, $response);
    }
}
