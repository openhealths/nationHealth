<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Classes\eHealth\EHealth;
use App\Enums\Composition\CompositionAsyncOperation;
use App\Enums\Composition\CompositionJobStatus;
use App\Enums\Composition\CompositionStatus;
use App\Enums\Composition\CompositionType;
use App\Enums\JobStatus;
use App\Enums\ResponseStatus;
use App\Exceptions\EHealth\EHealthException;
use App\Jobs\CompositionAsyncProcessing;
use App\Models\EhealthJob;
use App\Models\MedicalEvents\Sql\Composition;
use App\Models\MedicalEvents\Sql\CompositionOperation;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

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

    public function pendingForPatient(Person|Preperson $patient): Collection
    {
        return CompositionOperation::forPatient($patient)->pending()->with(['job', 'composition'])->get();
    }

    public function findForStatus(int $id, User $user): CompositionOperation
    {
        $operation = CompositionOperation::with(['job', 'composition'])->findOrFail($id);
        abort_unless($user->getCompositionAuthorEmployees($operation->compositionType)->contains('uuid', $operation->authorUuid), 403);

        return $operation;
    }

    public function hasPendingForComposition(Composition $composition): bool
    {
        return CompositionOperation::whereCompositionId($composition->id)->pending()->exists();
    }

    public function findForWizard(Person|Preperson $patient, string $jobId, CompositionType $type, User $user): ?CompositionOperation
    {
        $operation = CompositionOperation::whereRemoteJobId($jobId)->whereCompositionType($type->value)
            ->whereIn('author_uuid', $user->getCompositionAuthorEmployees($type)->pluck('uuid'))->with(['job', 'composition'])->first();
        if ($operation === null) {
            return null;
        }
        $belongsToPatient = $patient instanceof Person ? $operation->personId === $patient->id : $operation->prepersonId === $patient->id;
        $belongsToMother = $type === CompositionType::NEWBORN && $patient instanceof Person
            && data_get($operation->job->request_data, 'form.personUuid') === $patient->uuid;

        return $belongsToPatient || $belongsToMother ? $operation : null;
    }

    public function queue(CompositionOperation $operation): void
    {
        if ($operation->status !== CompositionJobStatus::PENDING->value) {
            return;
        }
        $token = session()->get(config('ehealth.api.oauth.bearer_token'));
        $user = Auth::user();
        $legalEntity = legalEntity();
        if (!$token || !$user || !$legalEntity) {
            return;
        }
        Bus::batch([new CompositionAsyncProcessing($operation, $legalEntity)])
            ->withOption('legal_entity_id', $legalEntity->id)
            ->withOption('token', Crypt::encryptString($token))
            ->withOption('user', $user)
            ->name(CompositionAsyncProcessing::BATCH_NAME)->onQueue('sync')->dispatch();
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
        try {
            $patient = $operation->personId !== null ? Person::findOrFail($operation->personId) : Preperson::findOrFail($operation->prepersonId);
            $api = EHealth::composition()->withToken($token);
            $composition = $operation->composition;
            $uuid = $composition?->uuid ?? ($status['compositionUuid'] ?? null);
            if ($uuid === null && $operation->operation === CompositionAsyncOperation::CREATE) {
                $results = $api->searchAll(['subject' => $patient->uuid, 'encounter' => $operation->encounterUuid, 'type' => $operation->compositionType->value]);
                foreach ($results as $result) {
                    Repository::composition()->assertDocumentContext($result, [
                        'subject.value' => $patient->uuid,
                        'encounter.value' => $operation->encounterUuid,
                        'type.coding.0.code' => $operation->compositionType->value,
                    ]);
                }
                $candidates = collect($results)->pluck('identifier.value')->filter()->unique();
                if ($candidates->count() === 1) {
                    $uuid = $candidates->first();
                }
            }
            if (!$uuid || !$operation->episodeUuid) {
                $this->recordRetryError($operation, __('compositions.errors.created_not_found'));

                return false;
            }
            $details = $api->getById($patient->uuid, $uuid, $operation->episodeUuid, $operation->encounterUuid)->validate();
            Repository::composition()->assertDocumentContext($details, [
                'identifier.value' => $uuid,
                'subject.value' => $patient->uuid,
                'author.value' => $operation->authorUuid,
                'encounter.value' => $operation->encounterUuid,
                'type.coding.0.code' => $operation->compositionType->value,
            ]);
            $expectedStatus = match ($operation->operation) {
                CompositionAsyncOperation::CREATE => CompositionStatus::PRELIMINARY,
                CompositionAsyncOperation::SIGN, CompositionAsyncOperation::ERLN_RETRY => CompositionStatus::FINAL,
                CompositionAsyncOperation::CANCEL => CompositionStatus::ENTERED_IN_ERROR,
            };
            if (CompositionStatus::fromEHealth($details['status'] ?? null) !== $expectedStatus) {
                $this->recordRetryError($operation, __('compositions.errors.created_unreadable'));

                return false;
            }
            $integration = $operation->operation === CompositionAsyncOperation::ERLN_RETRY
                ? $api->getIntegrationData($patient->uuid, $uuid, $operation->episodeUuid, $operation->encounterUuid)->validate()
                : null;
            $composition = Repository::composition()->store($details, $patient, $operation->episodeUuid);
            if ($composition === null) {
                return false;
            }
            if ($integration !== null) {
                Repository::composition()->storeIntegration($composition, $integration);
            }
        } catch (EHealthException | ValidationException) {
            // A remote job may be done before its validated document is available locally.
            $this->recordRetryError($operation, __('compositions.errors.created_unreadable'));

            return false;
        }
        $this->complete($operation, $composition);

        return true;
    }

    public function recordRetryError(CompositionOperation $operation, string $message): void
    {
        $operation->job->update(['response_data' => array_replace($operation->job->response_data ?? [], ['errors' => [$message]])]);
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
