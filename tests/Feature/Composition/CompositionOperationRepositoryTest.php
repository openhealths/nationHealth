<?php

declare(strict_types=1);

namespace Tests\Feature\Composition;

use App\Enums\Composition\CompositionAsyncOperation;
use App\Enums\Composition\CompositionType;
use App\Enums\JobStatus;
use App\Models\EhealthJob;
use App\Models\MedicalEvents\Sql\Composition;
use App\Models\MedicalEvents\Sql\CompositionOperation;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\CompositionOperationRepository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CompositionOperationRepositoryTest extends TestCase
{
    use RefreshCompositionDatabase;

    public function test_creation_job_survives_without_a_conclusion_and_is_completed_after_storage(): void
    {
        Http::preventStrayRequests();
        $person = $this->person();
        $repository = app(CompositionOperationRepository::class);
        $operation = $repository->store(['id' => 'create-job', 'status' => 'DONE'], CompositionAsyncOperation::CREATE, $person, [
            'composition_type' => CompositionType::NEWBORN, 'encounter_uuid' => (string) Str::uuid(),
            'episode_uuid' => (string) Str::uuid(), 'author_uuid' => (string) Str::uuid(),
        ]);
        $this->assertNull($operation->compositionId);
        $this->assertSame('PENDING', $operation->status, 'Remote completion must not skip local storage.');
        $this->assertSame(1, CompositionOperation::forPatient($person)->pending()->count());
        $composition = Composition::create(['uuid' => (string) Str::uuid(), 'status' => 'PRELIMINARY', 'person_id' => $person->id]);
        $repository->complete($operation, $composition);
        $this->assertSame($composition->id, $operation->fresh()->compositionId);
        $this->assertSame('DONE', $operation->fresh()->status);
        $this->assertSame(0, CompositionOperation::pending()->count());
        Http::assertNothingSent();
    }

    public function test_retry_keeps_the_failed_operation_and_isolated_patient_ownership(): void
    {
        $person = $this->person();
        $otherPerson = $this->person();
        $preperson = Preperson::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']);
        $repository = app(CompositionOperationRepository::class);
        $failed = $repository->store(['id' => 'failed-job'], CompositionAsyncOperation::ERLN_RETRY, $person, []);
        $repository->fail($failed, ['status' => 'FAILED', 'errors' => ['Rejected']]);
        $retry = $repository->store(['id' => 'retry-job'], CompositionAsyncOperation::ERLN_RETRY, $person, []);
        $repository->store(['id' => 'other-job'], CompositionAsyncOperation::CREATE, $otherPerson, []);
        $repository->store(['id' => 'preperson-job'], CompositionAsyncOperation::CREATE, $preperson, []);
        $this->assertSame(JobStatus::FAILED->value, $failed->fresh()->job->status);
        $this->assertSame('Rejected', $failed->fresh()->error);
        $this->assertSame([$retry->id], CompositionOperation::forPatient($person)->pending()->pluck('id')->all());
        $this->assertSame(1, CompositionOperation::forPatient($preperson)->count());
        $this->assertSame(4, EhealthJob::count());
    }

    public function test_recording_the_same_remote_job_twice_does_not_duplicate_technical_records(): void
    {
        $person = $this->person();
        $repository = app(CompositionOperationRepository::class);
        $first = $repository->store(['id' => 'same-job'], CompositionAsyncOperation::SIGN, $person, []);
        $second = $repository->store(['id' => 'same-job'], CompositionAsyncOperation::SIGN, $person, []);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, EhealthJob::count());
    }

    private function person(): Person
    {
        return Person::create(['uuid' => (string) Str::uuid(), 'birth_date' => '2000-01-01', 'gender' => 'MALE']);
    }
}
