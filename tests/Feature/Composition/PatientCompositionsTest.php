<?php

declare(strict_types=1);

namespace Tests\Feature\Composition;

use App\Enums\Composition\CompositionAsyncOperation;
use App\Enums\Composition\CompositionCategory;
use App\Enums\Composition\CompositionStatus;
use App\Enums\Composition\CompositionType;
use App\Livewire\Person\Records\PatientCompositions;
use App\Models\MedicalEvents\Sql\Composition;
use App\Models\Person\Person;
use App\Repositories\MedicalEvents\CompositionOperationRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The list side of TV 3.8: cancellation, ERLN retry and the async jobs both of them
 * schedule.
 *
 * eHealth answers cancelComposition and the ERLN resend with a job, not with a result,
 * so the conclusion is unchanged until that job reports DONE. These tests pin that the
 * local projection follows the job rather than the request.
 */
class PatientCompositionsTest extends TestCase
{
    use CompositionTestFixtures;
    use RefreshCompositionDatabase;

    /**
     * TV 3.8.2.15.4 — cancelling records the job and leaves the conclusion FINAL.
     */
    public function test_cancelling_records_the_job_and_does_not_change_the_status_yet(): void
    {
        ['employee' => $employee, 'legalEntity' => $legalEntity, 'person' => $person] = $this->compositionFixture(
            scopes: ['composition:read', 'composition:search', 'composition:cancel']
        );

        $this->fakeSignatureService('signed-cancellation');
        $this->fakeEHealth([
            '*/composition/*' => Http::response(['data' => ['id' => 'job-cancel', 'status' => 'PENDING']], 200),
            '*' => Http::response($this->eHealthBody([]), 200),
        ]);

        $composition = $this->storedComposition($person, $employee->uuid);

        // Livewire's test helper does not route-model-bind a Person mount parameter,
        // so seed the locked personId the same way production hydrate does.
        $component = Livewire::test(PatientCompositions::class, [
            'legalEntity' => $legalEntity,
            'personId' => $person->id,
        ])
            ->call('openCancellationModal', $composition->uuid)
            ->assertSet('showSignatureModal', true)
            ->set('form.reason', $this->firstCancellationReason())
            ->set('form.reasonText', 'Помилково створений висновок')
            ->set('form.knedp', 'ca-1')
            ->set('form.password', 'secret')
            ->set('form.keyContainerUpload', UploadedFile::fake()->create('key.dat', 8))
            ->call('cancelComposition');

        $component->assertSet('showSignatureModal', false);

        Http::assertSent(static fn ($request): bool => $request->data() === ['data' => 'signed-cancellation']);

        $composition->refresh();

        $this->assertSame('job-cancel', $composition->latestOperation->remoteJobId);
        $this->assertSame(CompositionAsyncOperation::CANCEL, $composition->latestOperation->operation);
        $this->assertSame(
            CompositionStatus::FINAL,
            $composition->status,
            'The conclusion stays final until eHealth reports the cancellation done.'
        );
    }

    /**
     * TV 3.8.2.15.4 — only a DONE job turns the conclusion into an erroneous entry.
     */
    public function test_polling_moves_a_cancelled_conclusion_only_once_the_job_is_done(): void
    {
        ['employee' => $employee, 'legalEntity' => $legalEntity, 'person' => $person] = $this->compositionFixture(
            scopes: ['composition:read', 'composition:search', 'composition:cancel']
        );

        $composition = $this->storedComposition($person, $employee->uuid, [
            'job_id' => 'job-cancel',
                        'operation' => CompositionAsyncOperation::CANCEL->value,
        ]);

        $this->fakeEHealth(['*' => Http::response(['data' => ['status' => 'PENDING']], 200)]);

        $component = Livewire::test(PatientCompositions::class, [
            'legalEntity' => $legalEntity,
            'personId' => $person->id,
        ])->call('pollAsyncJobs');

        $this->assertSame(CompositionStatus::FINAL, $composition->fresh()->status);

        $this->fakeEHealth(['*' => Http::response(['data' => ['status' => 'DONE']], 200)]);

        $component->call('pollAsyncJobs');

        $this->assertSame(CompositionStatus::ENTERED_IN_ERROR, $composition->fresh()->status);
        $this->assertSame('ENTERED_IN_ERROR', $composition->fresh()->toDetail()['status']);
    }

    /**
     * A failed job leaves the conclusion alone and keeps the reason for the list to show.
     */
    public function test_a_failed_job_is_reported_and_changes_nothing(): void
    {
        ['employee' => $employee, 'legalEntity' => $legalEntity, 'person' => $person] = $this->compositionFixture(
            scopes: ['composition:read', 'composition:search', 'composition:cancel']
        );

        $composition = $this->storedComposition($person, $employee->uuid, [
            'job_id' => 'job-cancel',
                        'operation' => CompositionAsyncOperation::CANCEL->value,
        ]);

        $this->fakeEHealth([
            '*' => Http::response([
                'data' => [
                    'status' => 'FAILED',
                    'response_data' => ['error' => ['message' => 'Термін скасування минув']],
                ],
            ], 200),
        ]);

        Livewire::test(PatientCompositions::class, [
            'legalEntity' => $legalEntity,
            'personId' => $person->id,
        ])->call('pollAsyncJobs');

        $composition->refresh();

        $this->assertSame(CompositionStatus::FINAL, $composition->status);
        $this->assertSame('FAILED', $composition->latestOperation->status);
        $this->assertNotNull($composition->latestOperation->error);
    }

    /**
     * TV 3.8.2.14 — the ERLN retry is asynchronous as well, so it is recorded the same way.
     */
    public function test_the_erln_retry_records_its_own_job(): void
    {
        ['employee' => $employee, 'legalEntity' => $legalEntity, 'person' => $person] = $this->compositionFixture(
            scopes: ['composition:read', 'composition:search', 'composition:write']
        );

        $composition = $this->storedComposition($person, $employee->uuid, ['erln_status' => 'ERROR']);

        $this->fakeEHealth([
            '*' => Http::response(['data' => ['id' => 'job-erln', 'status' => 'PENDING']], 200),
        ]);

        Livewire::test(PatientCompositions::class, [
            'legalEntity' => $legalEntity,
            'personId' => $person->id,
        ])
            ->call('openErlnResendModal', $composition->uuid)
            ->assertSet('showErlnResendModal', true)
            ->call('resendErln')
            ->assertSet('showErlnResendModal', false);

        $composition->refresh();

        $this->assertSame('job-erln', $composition->latestOperation->remoteJobId);
        $this->assertSame(CompositionAsyncOperation::ERLN_RETRY, $composition->latestOperation->operation);
    }

    /**
     * TV 3.8.1.9 / 3.8.2.11 — searching is authorised in its own right, and it asks
     * eHealth for the slice matching the page being viewed.
     */
    public function test_searching_is_authorised_and_pages_the_remote_result_set(): void
    {
        ['legalEntity' => $legalEntity, 'person' => $person] = $this->compositionFixture(
            scopes: ['composition:read', 'composition:search']
        );

        $remoteUuid = (string) Str::uuid();

        $this->fakeEHealth([
            '*' => Http::response($this->eHealthBody([[
                'identifier' => ['value' => $remoteUuid],
                'type' => ['coding' => [['system' => 'COMPOSITION_TYPES', 'code' => CompositionType::TEMP_DISABILITY->value]]],
                'status' => 'final',
                'title' => 'МВТН',
                'subject' => [
                    'value' => $person->uuid,
                    'type' => ['coding' => [['system' => 'eHealth/resources', 'code' => 'person']]],
                ],
            ]]), 200),
        ]);

        Livewire::test(PatientCompositions::class, [
            'legalEntity' => $legalEntity,
            'personId' => $person->id,
        ])
            ->call('search')
            ->assertOk();

        Http::assertSent(static fn ($request): bool => str_contains($request->url(), 'limit='));

        $this->assertNotNull(
            Composition::whereUuid($remoteUuid)->first(),
            'A searched conclusion is mirrored locally so the list has one record shape.'
        );
    }

    /**
     * A user without the search scope cannot pull conclusions in through the list either.
     */
    public function test_searching_without_the_scope_is_refused(): void
    {
        ['legalEntity' => $legalEntity, 'person' => $person] = $this->compositionFixture(
            scopes: ['composition:read']
        );

        $this->fakeEHealth(['*' => Http::response($this->eHealthBody([]), 200)]);

        Livewire::test(PatientCompositions::class, [
            'legalEntity' => $legalEntity,
            'personId' => $person->id,
        ])
            ->call('search')
            ->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function test_detail_view_reads_the_local_record_without_remote_context(): void
    {
        ['employee' => $employee, 'legalEntity' => $legalEntity, 'person' => $person] = $this->compositionFixture();
        $composition = $this->storedComposition($person, $employee->uuid, [
            'episode_of_care_id' => null,
            'title' => 'Local conclusion',
        ]);
        $this->fakeEHealth(['*' => Http::response(['data' => []])]);

        Livewire::test(PatientCompositions::class, ['legalEntity' => $legalEntity, 'personId' => $person->id])
            ->call('viewComposition', $composition->uuid)
            ->assertSet('showDetailModal', true)
            ->assertSet('compositionDetail.title', 'Local conclusion');

        Http::assertNothingSent();
    }

    public function test_a_patient_card_cannot_open_another_patients_conclusion(): void
    {
        ['employee' => $employee, 'legalEntity' => $legalEntity, 'person' => $person] = $this->compositionFixture();
        $anotherPerson = Person::create(['uuid' => (string) Str::uuid(), 'birth_date' => '2000-01-01', 'gender' => 'MALE']);
        $composition = $this->storedComposition($anotherPerson, $employee->uuid);

        Livewire::test(PatientCompositions::class, ['legalEntity' => $legalEntity, 'personId' => $person->id])
            ->call('viewComposition', $composition->uuid)
            ->assertSet('showDetailModal', false)
            ->assertSet('compositionDetail', null);
    }

    public function test_erln_refresh_failure_keeps_the_completed_job_available_for_retry(): void
    {
        ['employee' => $employee, 'legalEntity' => $legalEntity, 'person' => $person] = $this->compositionFixture();
        $composition = $this->storedComposition($person, $employee->uuid, [
            'job_id' => 'job-erln',
                        'operation' => CompositionAsyncOperation::ERLN_RETRY->value,
            'erln_status' => 'ERROR',
        ]);
        $this->fakeEHealth([
            '*/composition/job/*' => Http::response(['data' => ['status' => 'DONE']]),
            '*integrationData*' => Http::response([], 500),
        ]);
        $component = Livewire::test(PatientCompositions::class, ['legalEntity' => $legalEntity, 'personId' => $person->id]);
        $component->call('pollAsyncJobs');
        $this->assertSame('PENDING', $composition->fresh()->latestOperation->status);

        $this->fakeEHealth([
            '*/composition/job/*' => Http::response(['data' => ['status' => 'DONE']]),
            '*integrationData*' => Http::response(['data' => [[
                'component' => 'ERLN', 'type' => 'CREATE_ERLN_RECORD', 'integrationStatus' => 'SUCCESS',
                'details' => ['SL_NUM' => '12345'],
            ]]]),
        ]);
        $component->call('pollAsyncJobs');
        $this->assertSame('DONE', $composition->fresh()->latestOperation->status);
        $this->assertSame('SUCCESS', $composition->fresh()->erlnStatus);
        $this->assertSame('12345', $composition->fresh()->erlnRecordNumber);
    }

    public function test_search_synchronizes_all_pages_and_rendering_does_not_repeat_requests(): void
    {
        ['legalEntity' => $legalEntity, 'person' => $person] = $this->compositionFixture();
        config(['ehealth.api.page_size' => 1]);
        $ids = [(string) Str::uuid(), (string) Str::uuid()];
        $this->fakeEHealth(['*searchComposition*' => static function ($request) use ($ids, $person) {
            $offset = (int) ($request['offset'] ?? 0);

            return Http::response(['data' => isset($ids[$offset]) ? [[
                'identifier' => ['value' => $ids[$offset]],
                'status' => 'FINAL',
                'type' => ['coding' => [['system' => 'COMPOSITION_TYPES', 'code' => 'TEMP_DISABILITY']]],
                'subject' => ['value' => $person->uuid],
            ]] : []]);
        }]);

        Livewire::test(PatientCompositions::class, ['legalEntity' => $legalEntity, 'personId' => $person->id])
            ->call('search')->call('$refresh');

        $this->assertSame(2, Composition::forPatient($person)->count());
        $this->assertCount(3, Http::recorded());
    }

    public function test_the_list_finishes_a_creation_job_after_the_wizard_has_closed(): void
    {
        ['employee' => $employee, 'legalEntity' => $legalEntity, 'person' => $person] = $this->compositionFixture();
        $encounterUuid = (string) Str::uuid();
        $episodeUuid = (string) Str::uuid();
        $compositionUuid = (string) Str::uuid();
        $operation = app(CompositionOperationRepository::class)->store(
            ['id' => 'closed-wizard-job', 'status' => 'PENDING'],
            CompositionAsyncOperation::CREATE,
            $person,
            ['composition_type' => CompositionType::TEMP_DISABILITY, 'encounter_uuid' => $encounterUuid,
                'episode_uuid' => $episodeUuid, 'author_uuid' => $employee->uuid]
        );
        $this->fakeEHealth([
            '*/composition/job/*' => Http::response(['data' => ['status' => 'DONE',
                'links' => [['href' => "/composition/$compositionUuid"]]]]),
            '*' => Http::response(['data' => [
                'identifier' => ['value' => $compositionUuid], 'status' => 'PRELIMINARY', 'title' => 'Created remotely',
                'type' => ['coding' => [['system' => 'COMPOSITION_TYPES', 'code' => 'TEMP_DISABILITY']]],
                'author' => ['value' => $employee->uuid], 'encounter' => ['value' => $encounterUuid],
                'subject' => ['value' => $person->uuid],
            ]]),
        ]);
        $component = Livewire::test(PatientCompositions::class, ['legalEntity' => $legalEntity, 'personId' => $person->id]);
        $component->assertSet('hasPendingAsyncJobs', true)->call('pollAsyncJobs')->assertSet('hasPendingAsyncJobs', false);
        $composition = Composition::whereUuid($compositionUuid)->firstOrFail();
        $this->assertSame($person->id, $composition->personId);
        $this->assertSame($episodeUuid, $composition->episodeOfCareUuid);
        $this->assertSame('DONE', $operation->fresh()->status);
        $this->assertSame($composition->id, $operation->fresh()->compositionId);
    }

    private function storedComposition(Person $person, string $authorUuid, array $overrides = []): Composition
    {
        $jobId = $overrides['job_id'] ?? null;
        $operation = $overrides['operation'] ?? null;
        $erlnStatus = $overrides['erln_status'] ?? null;
        unset($overrides['job_id'], $overrides['operation'], $overrides['erln_status']);
        $type = CompositionType::from($overrides['type'] ?? CompositionType::TEMP_DISABILITY->value);
        unset($overrides['type'], $overrides['author_uuid'], $overrides['subject_uuid'], $overrides['encounter_uuid'], $overrides['episode_of_care_uuid'], $overrides['event_period_start'], $overrides['event_period_end'], $overrides['category']);

        $typeConcept = \App\Models\MedicalEvents\Sql\CodeableConcept::create(['text' => null]);
        $typeConcept->coding()->create([
            'system' => 'COMPOSITION_TYPES',
            'code' => $type->value,
        ]);

        $categoryConcept = \App\Models\MedicalEvents\Sql\CodeableConcept::create(['text' => null]);
        $categoryConcept->coding()->create([
            'system' => 'COMPOSITION_CATEGORIES',
            'code' => CompositionCategory::SICKNESS->value,
        ]);

        $author = \App\Models\MedicalEvents\Sql\Identifier::create(['value' => $authorUuid]);
        $subject = \App\Models\MedicalEvents\Sql\Identifier::create(['value' => $person->uuid]);
        $encounter = \App\Models\MedicalEvents\Sql\Identifier::create(['value' => (string) Str::uuid()]);
        $episode = \App\Models\MedicalEvents\Sql\Identifier::create(['value' => (string) Str::uuid()]);

        $composition = Composition::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'person_id' => $person->id,
            'status' => CompositionStatus::FINAL->value,
            'title' => 'МВТН',
            'type_id' => $typeConcept->id,
            'category_id' => $categoryConcept->id,
            'author_id' => $author->id,
            'subject_id' => $subject->id,
            'encounter_id' => $encounter->id,
            'episode_of_care_id' => $episode->id,
            'date' => '2026-09-01',
        ], $overrides));

        $composition->eventPeriod()->create([
            'start' => '2026-09-01',
            'end' => '2026-09-05',
        ]);

        if ($erlnStatus !== null) {
            $composition->integrations()->create([
                'component' => 'ERLN', 'type' => 'CREATE_ERLN_RECORD', 'integration_status' => $erlnStatus,
            ]);
        }
        if ($jobId !== null) {
            app(CompositionOperationRepository::class)->store(
                ['id' => $jobId, 'status' => 'PENDING'],
                CompositionAsyncOperation::from($operation),
                $person,
                ['composition_id' => $composition->id, 'composition_type' => $type,
                    'encounter_uuid' => $encounter->value, 'episode_uuid' => $episode->value, 'author_uuid' => $authorUuid]
            );
        }

        return $composition->refresh();
    }

    private function firstCancellationReason(): string
    {
        return (string) array_key_first(
            dictionary()->basics()
                ->byName(CompositionType::TEMP_DISABILITY->cancellationReasonDictionary())
                ->asCodeDescription()
                ->all()
        );
    }
}
