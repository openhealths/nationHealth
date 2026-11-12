<?php

declare(strict_types=1);

namespace App\Repositories\MedicalEvents;

use App\Enums\Composition\CompositionAsyncOperation;
use App\Enums\Composition\CompositionJobStatus;
use App\Enums\Composition\CompositionType;
use App\Enums\JobStatus;
use App\Enums\ResponseStatus;
use App\Models\EhealthJob;
use App\Models\MedicalEvents\Sql\Composition;
use App\Models\MedicalEvents\Sql\CompositionOperation;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Models\User;
use App\Repositories\MedicalEvents\BaseRepository;
use Illuminate\Support\Collection;

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

    public function trackedForPatient(Person|Preperson $patient, array $ids): Collection
    {
        return CompositionOperation::forPatient($patient)->whereIn('id', $ids)->with(['job', 'composition'])->get();
    }

    public function findForStatus(int $id, User $user): CompositionOperation
    {
        $operation = CompositionOperation::with(['job', 'composition'])->findOrFail($id);
        abort_unless($user->getCompositionAuthorEmployees($operation->compositionType)->contains('uuid', $operation->authorUuid), 403);

        return $operation;
    }

    public function hasPendingForComposition(Composition $composition, ?CompositionAsyncOperation $operation = null): bool
    {
        return CompositionOperation::whereCompositionId($composition->id)->pending()
            ->when($operation !== null, fn ($query) => $query->whereOperation($operation->value))->exists();
    }

    public function pendingCreationForContext(Person|Preperson $patient, string $encounterUuid, CompositionType $type, string $authorUuid): ?CompositionOperation
    {
        return CompositionOperation::forPatient($patient)->pending()
            ->whereOperation(CompositionAsyncOperation::CREATE->value)
            ->whereEncounterUuid($encounterUuid)
            ->whereCompositionType($type->value)
            ->whereAuthorUuid($authorUuid)
            ->with(['job', 'composition'])->first();
    }

    public function findForWizard(Person|Preperson $patient, string $jobId, CompositionType $type, User $user): ?CompositionOperation
    {
        $operation = CompositionOperation::whereRemoteJobId($jobId)->whereCompositionType($type->value)
            ->whereIn('operation', [CompositionAsyncOperation::CREATE->value, CompositionAsyncOperation::SIGN->value])
            ->whereIn('author_uuid', $user->getCompositionAuthorEmployees($type)->pluck('uuid'))->with(['job', 'composition'])->first();
        if ($operation === null) {
            return null;
        }
        $belongsToPatient = $patient instanceof Person ? $operation->personId === $patient->id : $operation->prepersonId === $patient->id;
        $belongsToMother = $type === CompositionType::NEWBORN && $patient instanceof Person
            && data_get($operation->job->request_data, 'form.personUuid') === $patient->uuid;

        return $belongsToPatient || $belongsToMother ? $operation : null;
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

    public function recordResponse(CompositionOperation $operation, array $response): void
    {
        $operation->job->update(['response_data' => $response]);
    }
}
