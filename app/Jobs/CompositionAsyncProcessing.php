<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\EHealthResponse;
use App\Core\EHealthJob;
use App\Enums\Composition\CompositionAsyncOperation;
use App\Enums\Composition\CompositionJobStatus;
use App\Enums\Composition\CompositionStatus;
use App\Enums\JobStatus;
use App\Exceptions\EHealth\EHealthException;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\CompositionIntegration;
use App\Models\MedicalEvents\Sql\CompositionOperation;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\Repository;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
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
            $finished = $this->processResult($response->validate());
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

        // Transport, token and worker failures leave the remote outcome unknown.
        // Keep the same durable request retryable instead of permitting a duplicate POST.
        Repository::compositionOperation()->recordRetryError($this->operation, __('compositions.errors.async_processing_failed'));
    }

    /** Dispatch with the same encrypted token and user context as the existing eHealth batch lifecycle. */
    public function dispatchProcessing(): bool
    {
        $operation = $this->operation;
        if ($operation->status !== CompositionJobStatus::PENDING->value) {
            return true;
        }
        $token = session()->get(config('ehealth.api.oauth.bearer_token'));
        $user = Auth::user();
        $legalEntity = legalEntity();
        if (!$token || !$user || !$legalEntity) {
            Repository::compositionOperation()->recordRetryError($operation, __('compositions.errors.job_status_unavailable'));

            return false;
        }
        Bus::batch([$this])
            ->withOption('legal_entity_id', $legalEntity->id)
            ->withOption('token', Crypt::encryptString($token))
            ->withOption('user', $user)
            ->name(CompositionAsyncProcessing::BATCH_NAME)->onQueue('sync')->dispatch();

        return true;
    }

    /** A DONE response is confirmed only after its matching document has the expected state. */
    protected function processResult(array $status): bool
    {
        $operation = $this->operation;
        $token = $this->token;
        if ($operation->status !== CompositionJobStatus::PENDING->value) {
            return true;
        }
        if (($status['id'] ?? null) !== null && $status['id'] !== $operation->remoteJobId) {
            Repository::compositionOperation()->recordRetryError($operation, __('compositions.errors.invalid_response'));

            return false;
        }
        if ($status['status'] === CompositionJobStatus::FAILED->value) {
            Repository::compositionOperation()->fail($operation, $status);

            return true;
        }
        Repository::compositionOperation()->recordResponse($operation, $status);
        if ($status['status'] !== CompositionJobStatus::DONE->value) {
            return false;
        }
        try {
            $patient = $operation->personId !== null ? Person::findOrFail($operation->personId) : Preperson::findOrFail($operation->prepersonId);
            $api = EHealth::composition()->withToken($token);
            $composition = $operation->composition;
            $uuid = $composition?->uuid ?? ($status['compositionUuid'] ?? null);
            if ($uuid === null && $operation->operation === CompositionAsyncOperation::CREATE) {
                $results = $api->searchAll(['subject' => $patient->uuid, 'encounter' => $operation->encounterUuid, 'type' => $operation->compositionType->value]);
                // The validated search can omit subject/author; verify the full candidate below.
                $candidates = collect($results)->pluck('identifier.value')->filter()->unique();
                if ($candidates->count() === 1) {
                    $uuid = $candidates->first();
                }
            }
            if (!$uuid || !$operation->episodeUuid) {
                Repository::compositionOperation()->recordRetryError($operation, __('compositions.errors.created_not_found'));

                return false;
            }
            $details = $api->getById($patient->uuid, $uuid, $operation->episodeUuid, $operation->encounterUuid)->validate();
            $api->assertDocumentContext($details, [
                'identifier.value' => $uuid,
                'subject.value' => $patient->uuid,
                'author.value' => $operation->authorUuid,
                'encounter.value' => $operation->encounterUuid,
                'type.coding.0.code' => $operation->compositionType->value,
            ]);
            if (array_key_exists('episodeOfCare', $details)) {
                $api->assertDocumentContext($details, ['episodeOfCare.value' => $operation->episodeUuid]);
            }
            $expectedStatus = match ($operation->operation) {
                CompositionAsyncOperation::CREATE => CompositionStatus::PRELIMINARY,
                CompositionAsyncOperation::SIGN, CompositionAsyncOperation::ERLN_RETRY => CompositionStatus::FINAL,
                CompositionAsyncOperation::CANCEL => CompositionStatus::ENTERED_IN_ERROR,
            };
            if (CompositionStatus::tryFrom(str_replace('-', '_', strtoupper($details['status'] ?? ''))) !== $expectedStatus) {
                Repository::compositionOperation()->recordRetryError($operation, __('compositions.errors.created_unreadable'));

                return false;
            }
            $integration = $operation->operation === CompositionAsyncOperation::ERLN_RETRY
                ? $api->getIntegrationData($patient->uuid, $uuid, $operation->episodeUuid, $operation->encounterUuid)->validate()
                : null;
            if ($integration !== null && !collect($integration)->contains(static fn (array $item): bool =>
                $item['component'] === CompositionIntegration::ERLN_COMPONENT && $item['type'] === CompositionIntegration::ERLN_CREATION)) {
                Repository::compositionOperation()->recordRetryError($operation, __('compositions.errors.created_unreadable'));

                return false;
            }
            $composition = Repository::composition()->store($details, $patient, $operation->episodeUuid);
            if ($composition === null) {
                return false;
            }
            if ($integration !== null) {
                Repository::composition()->storeIntegration($composition, $integration);
            }
        } catch (EHealthException | ValidationException) {
            // A remote job may be done before its validated document is available locally.
            Repository::compositionOperation()->recordRetryError($operation, __('compositions.errors.created_unreadable'));

            return false;
        }
        Repository::compositionOperation()->complete($operation, $composition);

        return true;
    }
}
