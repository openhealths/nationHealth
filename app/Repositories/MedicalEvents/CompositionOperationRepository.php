<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Enums\Composition\CompositionAsyncOperation;
use App\Enums\Composition\CompositionJobStatus;
use App\Enums\JobStatus;
use App\Enums\ResponseStatus;
use App\Models\EhealthJob;
use App\Models\MedicalEvents\Sql\Composition;
use App\Models\MedicalEvents\Sql\CompositionOperation;
use App\Models\Person\Person;
use App\Models\Preperson;

class CompositionOperationRepository extends BaseRepository
{
    public function __construct(CompositionOperation $model)
    {
        parent::__construct($model);
    }

    public function store(array $job, CompositionAsyncOperation $operation, Person|Preperson $patient, array $context): CompositionOperation
    {
        $requestData = $context['requestData'] ?? [];
        unset($context['requestData']);
        $record = $this->storageTransaction(function () use ($job, $operation, $patient, $context, $requestData): CompositionOperation {
            $record = CompositionOperation::firstOrNew(['remote_job_id' => $job['id']]);
            if ($record->exists) {
                return $record;
            }
            $ehealthJob = EhealthJob::create([
                'processing_method' => ResponseStatus::ASYNC->value,
                'status' => JobStatus::PENDING->value,
                'response_data' => $job,
                'request_data' => $requestData,
            ]);
            $record->fill(array_merge($context, [
                'ehealth_job_id' => $ehealthJob->id,
                'operation' => $operation,
                'person_id' => $patient instanceof Person ? $patient->id : null,
                'preperson_id' => $patient instanceof Preperson ? $patient->id : null,
            ]))->save();

            return $record->load('job');
        });
        $this->queue($record);

        return $record;
    }

    public function findForPatient(Person|Preperson $patient, string $jobId): ?CompositionOperation
    {
        return CompositionOperation::forPatient($patient)->whereRemoteJobId($jobId)->with(['job', 'composition'])->first();
    }

    public function pendingForPatient(Person|Preperson $patient): \Illuminate\Support\Collection
    {
        return CompositionOperation::forPatient($patient)->pending()->with(['job', 'composition'])->get();
    }

    public function findForStatus(int $id, \App\Models\User $user): CompositionOperation
    {
        $operation = CompositionOperation::with(['job', 'composition'])->findOrFail($id);
        abort_unless($user->getCompositionAuthorEmployees($operation->compositionType)->contains('uuid', $operation->authorUuid), 403);

        return $operation;
    }

    public function hasPendingForComposition(Composition $composition): bool
    {
        return CompositionOperation::whereCompositionId($composition->id)->pending()->exists();
    }

    public function findForWizard(Person|Preperson $patient, string $jobId, \App\Enums\Composition\CompositionType $type, \App\Models\User $user): ?CompositionOperation
    {
        $operation = CompositionOperation::whereRemoteJobId($jobId)->whereCompositionType($type->value)
            ->whereIn('author_uuid', $user->getCompositionAuthorEmployees($type)->pluck('uuid'))->with(['job', 'composition'])->first();
        if ($operation === null) {
            return null;
        }
        $belongsToPatient = $patient instanceof Person ? $operation->personId === $patient->id : $operation->prepersonId === $patient->id;
        $belongsToMother = $type === \App\Enums\Composition\CompositionType::NEWBORN && $patient instanceof Person
            && data_get($operation->job->request_data, 'form.personUuid') === $patient->uuid;

        return $belongsToPatient || $belongsToMother ? $operation : null;
    }

    public function queue(CompositionOperation $operation): void
    {
        if ($operation->status !== CompositionJobStatus::PENDING->value) {
            return;
        }
        $token = session()->get(config('ehealth.api.oauth.bearer_token'));
        $user = \Illuminate\Support\Facades\Auth::user();
        $legalEntity = legalEntity();
        if (!$token || !$user || !$legalEntity) {
            return;
        }
        \Illuminate\Support\Facades\Bus::batch([new \App\Jobs\CompositionAsyncProcessing($operation, $legalEntity)])
            ->withOption('legal_entity_id', $legalEntity->id)
            ->withOption('token', \Illuminate\Support\Facades\Crypt::encryptString($token))
            ->withOption('user', $user)
            ->name(\App\Jobs\CompositionAsyncProcessing::BATCH_NAME)->onQueue('sync')->dispatch();
    }

    public function processResult(CompositionOperation $operation, array $status, string $token): bool
    {
        if ($operation->status !== CompositionJobStatus::PENDING->value) {
            return true;
        }
        if ($status['status'] === CompositionJobStatus::FAILED->value) {
            $this->fail($operation, $status);

            return true;
        }
        $operation->job->update(['response_data' => $status]);
        if ($status['status'] !== CompositionJobStatus::DONE->value) {
            return false;
        }
        $patient = $operation->personId !== null ? Person::findOrFail($operation->personId) : Preperson::findOrFail($operation->prepersonId);
        $api = \App\Classes\eHealth\EHealth::composition()->withToken($token);
        $composition = $operation->composition;
        $uuid = $composition?->uuid ?? $status['compositionUuid'] ?? null;
        if ($uuid === null && $operation->operation === CompositionAsyncOperation::CREATE) {
            $results = $api->search(['subject' => $patient->uuid, 'encounter' => $operation->encounterUuid, 'type' => $operation->compositionType->value])->validate();
            $candidates = collect($results)->pluck('identifier.value')->filter()->unique();
            if ($candidates->count() === 1) {
                $uuid = $candidates->first();
            }
        }
        if (!$uuid || !$operation->episodeUuid) {
            return false;
        }
        $details = $api->getById($patient->uuid, $uuid, $operation->episodeUuid, $operation->encounterUuid)->validate();
        $expectedStatus = match ($operation->operation) {
            CompositionAsyncOperation::CREATE => \App\Enums\Composition\CompositionStatus::PRELIMINARY,
            CompositionAsyncOperation::SIGN, CompositionAsyncOperation::ERLN_RETRY => \App\Enums\Composition\CompositionStatus::FINAL,
            CompositionAsyncOperation::CANCEL => \App\Enums\Composition\CompositionStatus::ENTERED_IN_ERROR,
        };
        if (\App\Enums\Composition\CompositionStatus::fromEHealth($details['status'] ?? null) !== $expectedStatus) {
            return false;
        }
        $composition = Repository::composition()->store($details, $patient, $operation->episodeUuid);
        if ($composition === null) {
            return false;
        }
        if ($operation->operation === CompositionAsyncOperation::ERLN_RETRY) {
            $integration = $api->getIntegrationData($patient->uuid, $uuid, $operation->episodeUuid, $operation->encounterUuid)->validate();
            Repository::composition()->storeIntegration($composition, $integration);
        }
        $this->complete($operation, $composition);

        return true;
    }

    public function fail(CompositionOperation $operation, array $response): void
    {
        $operation->job->update(['status' => JobStatus::FAILED->value, 'response_data' => $response]);
    }

    public function complete(CompositionOperation $operation, ?Composition $composition = null): void
    {
        $this->storageTransaction(function () use ($operation, $composition): void {
            if ($composition !== null) {
                $operation->update(['composition_id' => $composition->id]);
            }
            $operation->job->update([
                'status' => JobStatus::COMPLETED->value,
                'response_data' => array_replace($operation->job->response_data ?? [], [
                    'status' => CompositionJobStatus::DONE->value, 'errors' => [],
                ]),
            ]);
        });
    }
}
