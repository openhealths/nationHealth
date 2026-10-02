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
use Illuminate\Support\Facades\DB;

class CompositionOperationRepository
{
    public function store(array $job, CompositionAsyncOperation $operation, Person|Preperson $patient, array $context): CompositionOperation
    {
        return DB::transaction(function () use ($job, $operation, $patient, $context): CompositionOperation {
            $record = CompositionOperation::firstOrNew(['remote_job_id' => $job['id']]);
            if ($record->exists) {
                return $record;
            }
            $ehealthJob = EhealthJob::create([
                'processing_method' => ResponseStatus::ASYNC->value,
                'status' => JobStatus::PENDING->value,
                'response_data' => $job,
            ]);
            $record->fill(array_merge($context, [
                'ehealth_job_id' => $ehealthJob->id,
                'operation' => $operation,
                'person_id' => $patient instanceof Person ? $patient->id : null,
                'preperson_id' => $patient instanceof Preperson ? $patient->id : null,
            ]))->save();

            return $record->load('job');
        });
    }

    public function fail(CompositionOperation $operation, array $response): void
    {
        $operation->job->update(['status' => JobStatus::FAILED->value, 'response_data' => $response]);
    }

    public function complete(CompositionOperation $operation, ?Composition $composition = null): void
    {
        DB::transaction(function () use ($operation, $composition): void {
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
