<?php

declare(strict_types=1);

namespace Tests\Feature\Encounter;

use App\Classes\eHealth\Api\Patient\Condition as ConditionApi;
use App\Classes\eHealth\Api\Patient\Encounter as EncounterApi;
use App\Classes\eHealth\EHealthResponse;
use App\Enums\DeviceDispense\Status as DeviceDispenseStatus;
use App\Enums\Person\ConditionVerificationStatus;
use App\Enums\Person\EncounterStatus;
use App\Jobs\RemoteEHealthLinksProcessing;
use App\Models\EhealthLink;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Condition;
use App\Models\MedicalEvents\Sql\DeviceDispense;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Models\User;
use App\Repositories\MedicalEvents\EncounterRepository;
use App\Repositories\MedicalEvents\Repository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class EncounterCancellationSubmissionTest extends TestCase
{
    use DatabaseTransactions;

    #[DataProvider('pendingStatuses')]
    public function test_receipt_and_pending_job_leave_all_clinical_records_unchanged(string $status): void
    {
        [$patient, $encounter, $condition, $dispense] = $this->package();
        $submission = $this->submission($encounter);

        $this->assertFalse(Repository::encounter()->finishCancellation($submission, ['status' => $status]));

        $this->assertSame(EncounterStatus::FINISHED, $encounter->fresh()->status);
        $this->assertSame(ConditionVerificationStatus::CONFIRMED, $condition->fresh()->verificationStatus);
        $this->assertSame(DeviceDispenseStatus::COMPLETED, $dispense->fresh()->status);
        $this->assertSame('PENDING', $submission->fresh()->status);
        $this->assertSame($submission->id, Repository::encounter()->pendingCancellation($encounter)?->id);
        $this->assertSame([$submission->id], Repository::encounter()->pendingPatientCancellations($patient)->pluck('id')->all());
    }

    public static function pendingStatuses(): array
    {
        return [['pending'], ['processing'], ['accepted'], ['queued']];
    }

    public function test_confirmed_job_cancels_full_package_including_device_dispenses_exactly_once(): void
    {
        [, $encounter, $condition, $dispense] = $this->package();
        $submission = $this->submission($encounter);

        $this->assertTrue(Repository::encounter()->finishCancellation($submission, ['status' => 'processed', 'status_code' => 200]));
        $this->assertSame(EncounterStatus::ENTERED_IN_ERROR, $encounter->fresh()->status);
        $this->assertSame(ConditionVerificationStatus::ENTERED_IN_ERROR, $condition->fresh()->verificationStatus);
        $this->assertSame(DeviceDispenseStatus::ENTERED_IN_ERROR, $dispense->fresh()->status);
        $this->assertSame('Помилково внесено', $dispense->fresh()->explanatoryLetter);
        $this->assertSame('PROCESSED', $submission->fresh()->status);
        $this->assertNull(Repository::encounter()->pendingCancellation($encounter));

        $this->assertFalse(Repository::encounter()->finishCancellation($submission, ['status' => 'failed', 'error' => 'Late duplicate']));
        $this->assertSame('PROCESSED', $submission->fresh()->status);
        $this->assertSame(EncounterStatus::ENTERED_IN_ERROR, $encounter->fresh()->status);
    }

    #[DataProvider('failedResults')]
    public function test_failed_job_preserves_original_clinical_statuses(array $result): void
    {
        [, $encounter, $condition, $dispense] = $this->package();
        $submission = $this->submission($encounter);

        $this->assertTrue(Repository::encounter()->finishCancellation($submission, $result));
        $this->assertSame('FAILED', $submission->fresh()->status);
        $this->assertSame(EncounterStatus::FINISHED, $encounter->fresh()->status);
        $this->assertSame(ConditionVerificationStatus::CONFIRMED, $condition->fresh()->verificationStatus);
        $this->assertSame(DeviceDispenseStatus::COMPLETED, $dispense->fresh()->status);
    }

    public static function failedResults(): array
    {
        return [
            [['status' => 'failed', 'error' => ['message' => 'Denied']]],
            [['status' => 'processed', 'status_code' => 422, 'error' => ['message' => 'Invalid package']]],
            [['status' => 'processed', 'error' => 'Not accepted']],
        ];
    }

    public function test_malformed_result_keeps_duplicate_guard_and_clinical_state(): void
    {
        [, $encounter, $condition, $dispense] = $this->package();
        $submission = $this->submission($encounter);
        try {
            Repository::encounter()->finishCancellation($submission, ['status' => 'unknown']);
            $this->fail('Unknown job status was consumed.');
        } catch (ValidationException) {
            $this->assertSame('PENDING', $submission->fresh()->status);
            $this->assertSame(EncounterStatus::FINISHED, $encounter->fresh()->status);
            $this->assertSame(ConditionVerificationStatus::CONFIRMED, $condition->fresh()->verificationStatus);
            $this->assertSame(DeviceDispenseStatus::COMPLETED, $dispense->fresh()->status);
        }
    }

    public function test_selected_dispense_cancellation_preserves_encounter_and_other_records(): void
    {
        [, $encounter, $condition, $dispense] = $this->package();
        $submission = $this->submission($encounter, ['deviceDispenses' => [$dispense->uuid]]);

        Repository::encounter()->finishCancellation($submission, ['status' => 'processed']);

        $this->assertSame(EncounterStatus::FINISHED, $encounter->fresh()->status);
        $this->assertSame(ConditionVerificationStatus::CONFIRMED, $condition->fresh()->verificationStatus);
        $this->assertSame(DeviceDispenseStatus::ENTERED_IN_ERROR, $dispense->fresh()->status);
    }

    public function test_duplicate_guard_survives_new_model_instance_and_does_not_create_a_second_job(): void
    {
        [, $encounter] = $this->package();
        $first = $this->submission($encounter);
        $this->assertNull(Repository::encounter()->startCancellation($encounter->fresh(), $first->job->request_data));
        $this->assertSame(1, $encounter->ehealthLinks()->count());
        $this->assertSame('PENDING', $first->fresh()->status);
    }

    public function test_pending_queries_are_isolated_for_persons_and_prepersons(): void
    {
        [$person, $encounter] = $this->package();
        [$otherPerson, $otherEncounter] = $this->package();
        $preperson = Preperson::create(['uuid' => (string) Str::uuid(), 'first_name' => 'Невідомий', 'last_name' => 'Пацієнт', 'gender' => 'MALE']);
        $prepersonEncounter = $this->encounter($preperson);
        $first = $this->submission($encounter);
        $second = $this->submission($otherEncounter);
        $third = $this->submission($prepersonEncounter);

        $this->assertSame([$first->id], Repository::encounter()->pendingPatientCancellations($person)->pluck('id')->all());
        $this->assertSame([$second->id], Repository::encounter()->pendingPatientCancellations($otherPerson)->pluck('id')->all());
        $this->assertSame([$third->id], Repository::encounter()->pendingPatientCancellations($preperson)->pluck('id')->all());
    }

    public function test_background_completion_uses_the_same_package_transaction(): void
    {
        [, $encounter, $condition, $dispense] = $this->package();
        $submission = $this->submission($encounter);
        Http::preventStrayRequests();

        RemoteEHealthLinksProcessing::finishSubmission($submission, ['status' => 'processed'], new User());

        $this->assertSame(EncounterStatus::ENTERED_IN_ERROR, $encounter->fresh()->status);
        $this->assertSame(ConditionVerificationStatus::ENTERED_IN_ERROR, $condition->fresh()->verificationStatus);
        $this->assertSame(DeviceDispenseStatus::ENTERED_IN_ERROR, $dispense->fresh()->status);
        Http::assertNothingSent();
    }

    #[DataProvider('reconciliationStatuses')]
    public function test_lost_receipt_requires_confirmation_of_the_encounter_and_every_record(string $encounterStatus, string $dispenseStatus, bool $confirmed): void
    {
        [$patient, $encounter, $condition, $dispense] = $this->package();
        $submission = $this->submission($encounter);
        $resources = array_fill_keys([
            'condition', 'observation', 'immunization', 'diagnosticReport', 'procedure', 'clinicalImpression',
            'device', 'deviceAssociation', 'detectedIssue', 'specimen', 'deviceDispense',
        ], []);
        $resources['condition'] = [[
            'uuid' => $condition->uuid, 'verification_status' => 'entered_in_error',
            'context' => ['identifier' => ['value' => $encounter->uuid]],
        ]];
        $resources['deviceDispense'] = [['uuid' => $dispense->uuid, 'status' => $dispenseStatus]];
        $repository = $this->partialMock(EncounterRepository::class);
        $repository->shouldReceive('synchronizeCancellationPackage')->once()->withArgs(
            static fn (Encounter $actualEncounter, Person $actualPatient): bool => $actualEncounter->id === $encounter->id && $actualPatient->id === $patient->id
        )
            ->andReturn(['encounter' => ['uuid' => $encounter->uuid, 'status' => $encounterStatus], 'resources' => $resources]);

        $this->assertSame($confirmed, Repository::encounter()->reconcileCancellation($submission, $patient));
        $this->assertSame($confirmed ? 'PROCESSED' : 'PENDING', $submission->fresh()->status);
        $this->assertSame($confirmed ? EncounterStatus::ENTERED_IN_ERROR : EncounterStatus::FINISHED, $encounter->fresh()->status);
    }

    public static function reconciliationStatuses(): array
    {
        return [
            ['finished', 'completed', false],
            ['entered_in_error', 'completed', false],
            ['entered_in_error', 'entered_in_error', true],
        ];
    }

    public function test_reconciliation_cannot_read_or_change_another_patient(): void
    {
        [, $encounter] = $this->package();
        [$other] = $this->package();
        $submission = $this->submission($encounter);
        $this->partialMock(EncounterRepository::class)->shouldNotReceive('synchronizeCancellationPackage');
        $this->expectException(RuntimeException::class);
        Repository::encounter()->reconcileCancellation($submission, $other);
    }

    public function test_queue_exhaustion_preserves_the_unconfirmed_submission_guard(): void
    {
        [, $encounter] = $this->package();
        $submission = $this->submission($encounter);
        $job = new RemoteEHealthLinksProcessing($submission, new LegalEntity(), standalone: true);
        $job->failed(new RuntimeException('Connection lost'));

        $this->assertSame('PENDING', $submission->fresh()->status);
        $this->assertSame(EncounterStatus::FINISHED, $encounter->fresh()->status);
        $this->assertSame($submission->id, Repository::encounter()->pendingCancellation($encounter)?->id);
    }

    public function test_failed_later_page_cannot_synchronize_a_partial_encounter_package(): void
    {
        [$patient, $encounter, $condition] = $this->package();
        Http::preventStrayRequests();
        $detail = Mockery::mock(EHealthResponse::class);
        $detail->shouldReceive('validate')->once()->andReturn(['uuid' => $encounter->uuid, 'status' => 'entered_in_error']);
        $this->mock(EncounterApi::class)->shouldReceive('getById')->once()->with($patient->uuid, $encounter->uuid)->andReturn($detail);
        $first = Mockery::mock(EHealthResponse::class);
        $first->shouldReceive('validate')->once()->andReturn([
            ['uuid' => $condition->uuid, 'context' => ['identifier' => ['value' => $encounter->uuid]]],
        ]);
        $first->shouldReceive('getPaging')->once()->andReturn(['page_number' => 1, 'page_size' => 300, 'total_pages' => 2, 'total_entries' => 2]);
        $second = Mockery::mock(EHealthResponse::class);
        $second->shouldNotReceive('getData');
        $second->shouldReceive('validate')->once()->andThrow(ValidationException::withMessages(['id' => 'Malformed second page']));
        $api = $this->mock(ConditionApi::class);
        $api->shouldReceive('getBySearchParams')->once()->ordered()->andReturn($first);
        $api->shouldReceive('getBySearchParams')->once()->ordered()->andReturn($second);
        $this->partialMock(EncounterRepository::class)->shouldNotReceive('sync');

        try {
            Repository::encounter()->synchronizeCancellationPackage($encounter, $patient);
            $this->fail('A partial package was synchronized.');
        } catch (ValidationException) {
            $this->assertSame(EncounterStatus::FINISHED, $encounter->fresh()->status);
            $this->assertSame(ConditionVerificationStatus::CONFIRMED, $condition->fresh()->verificationStatus);
            $this->assertSame(0, $encounter->ehealthLinks()->count());
            Http::assertNothingSent();
        }
    }

    private function submission(Encounter $encounter, array $selected = []): EhealthLink
    {
        return Repository::encounter()->startCancellation($encounter, [
            'operation' => 'cancelEncounter', 'encounter' => ['status' => 'entered_in_error'],
            'selectedRecords' => $selected, 'explanatoryLetter' => 'Помилково внесено',
            'cancellationReasonConcept' => ['coding' => [['system' => 'eHealth/cancellation_reasons', 'code' => 'technical_error']]],
        ]);
    }

    private function package(): array
    {
        $patient = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']);
        $encounter = $this->encounter($patient);
        $context = Identifier::create(['value' => $encounter->uuid]);
        $condition = Condition::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $patient->id, 'clinical_status' => 'active',
            'verification_status' => 'confirmed', 'primary_source' => true, 'context_id' => $context->id, 'onset_date' => now(),
            'code_id' => Repository::codeableConcept()->store(['coding' => [['system' => 'eHealth/ICD10_AM/condition_codes', 'code' => 'E10.9']]])->id,
        ]);
        $dispense = DeviceDispense::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $patient->id, 'status' => 'completed', 'encounter_id' => $context->id,
        ]);

        return [$patient, $encounter, $condition, $dispense];
    }

    private function encounter(Person|Preperson $patient): Encounter
    {
        return Encounter::create([
            ...($patient instanceof Preperson ? ['preperson_id' => $patient->id] : ['person_id' => $patient->id]),
            'uuid' => (string) Str::uuid(), 'status' => 'finished',
            'episode_id' => Identifier::create(['value' => (string) Str::uuid()])->id,
            'class_id' => Repository::coding()->store(['system' => 'eHealth/encounter_classes', 'code' => 'AMB'])->id,
            'type_id' => Repository::codeableConcept()->store(['coding' => [['system' => 'eHealth/encounter_types', 'code' => 'consultation']]])->id,
        ]);
    }
}
