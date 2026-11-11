<?php

declare(strict_types=1);

namespace Tests\Feature\CarePlan;

use App\Classes\eHealth\Api\CarePlan as CarePlanApi;
use App\Classes\eHealth\Api\CarePlanActivity as ActivityApi;
use App\Classes\eHealth\EHealthResponse;
use App\Enums\Status;
use App\Enums\User\Role;
use App\Jobs\RemoteEHealthLinksProcessing;
use App\Livewire\Concerns\SubmitsClinicalOperations;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\Person\Person;
use App\Models\Relations\Party;
use App\Models\User;
use App\Notifications\RemoteEHealthLinksNotification;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use App\Repositories\Repository;
use App\Traits\SynchronizesCarePlans;
use Illuminate\Foundation\Testing\DatabaseTransactions;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CarePlanSyncTest extends TestCase
{
    use DatabaseTransactions;

    public function test_requisition_search_fetches_each_plan_and_activity_only_once(): void
    {
        [$person, $employee] = $this->makeSyncContext();
        $plans = [];
        $activities = [];
        for ($index = 0; $index < 2; $index++) {
            $plan = $this->fixture('plan');
            $plan['id'] = (string) Str::uuid();
            $plan['subject']['identifier']['value'] = $person->uuid;
            $plans[] = $plan;
            $activity = $this->fixture('activity');
            $activity['id'] = (string) Str::uuid();
            $activity['carePlan']['identifier']['value'] = $plan['id'];
            $activities[] = $activity;
        }
        $search = Mockery::mock(EHealthResponse::class);
        $search->shouldReceive('validate')->once()->andReturn(array_map(static fn (array $plan): array => [
            'id' => $plan['id'], 'subject' => $plan['subject'],
        ], $plans));
        $search->shouldReceive('getPaging')->once()->andReturn(['total_pages' => 1]);
        $planApi = Mockery::mock(CarePlanApi::class);
        $planApi->shouldReceive('getMany')->once()->with(['requisition' => 'search-number', 'page' => 1])->andReturn($search);
        $activityApi = Mockery::mock(ActivityApi::class);
        foreach ($plans as $index => $plan) {
            $details = Mockery::mock(EHealthResponse::class);
            $details->shouldReceive('validate')->once()->andReturn($plan);
            $planApi->shouldReceive('getDetails')->once()->with($person->uuid, $plan['id'])->andReturn($details);
            $summary = Mockery::mock(EHealthResponse::class);
            $summary->shouldReceive('validate')->once()->andReturn([['id' => $activities[$index]['id']]]);
            $summary->shouldReceive('getPaging')->once()->andReturn(['total_pages' => 1]);
            $activityApi->shouldReceive('getSummary')->once()->with($person->uuid, $plan['id'], ['page' => 1])->andReturn($summary);
            $activityDetails = Mockery::mock(EHealthResponse::class);
            $activityDetails->shouldReceive('validate')->once()->andReturn($activities[$index]);
            $activityApi->shouldReceive('getDetails')->once()->with($person->uuid, $plan['id'], $activities[$index]['id'])->andReturn($activityDetails);
        }
        $this->instance(CarePlanApi::class, $planApi);
        $this->instance(ActivityApi::class, $activityApi);

        (new CarePlanSynchronizationHarness())->synchronizeSearch(['requisition' => 'search-number'], $employee->legalEntityId);

        $this->assertSame(2, CarePlan::wherePersonId($person->id)->count());
        $this->assertSame(2, CarePlanActivity::whereIn('uuid', array_column($activities, 'id'))->count());
    }

    public function test_care_plan_and_activities_can_be_synced_and_mapped_to_structured_sql(): void
    {
        [$person, $employee] = $this->makeSyncContext();
        $plan = $this->fixture('plan');
        $plan['author']['identifier']['value'] = $employee->uuid;
        $activity = $this->fixture('activity');
        $api = $this->bindRemote($plan, [$activity], $person->uuid);

        $api->synchronizeResources([['id' => $plan['id']]], $person->id, $employee->legalEntityId);

        $stored = CarePlan::whereUuid($plan['id'])->firstOrFail();
        $storedActivity = CarePlanActivity::whereUuid($activity['id'])->firstOrFail();
        $this->assertSame($employee->id, $stored->authorId);
        $this->assertNotNull($stored->categoryId);
        $this->assertSame($plan['category'], Repository::carePlan()->getResource($stored->id)['category']);
        $this->assertSame($activity['detail']['kind'], $storedActivity->kind);
        $this->assertSame($activity['detail']['reasonCode'], Repository::carePlanActivity()->getResource($storedActivity->id)['detail']['reasonCode']);
        $this->assertNull($storedActivity->getRawOriginal('quantity'));
    }

    public function test_sync_preserves_unknown_remote_authors_without_attributing_them_to_the_caller(): void
    {
        [$person, $employee] = $this->makeSyncContext();
        $plan = $this->fixture('plan');
        $api = $this->bindRemote($plan, [], $person->uuid);

        $api->synchronizeResources([['id' => $plan['id']]], $person->id, $employee->legalEntityId);

        $stored = CarePlan::whereUuid($plan['id'])->firstOrFail();
        $this->assertNull($stored->authorId);
        $this->assertEquals($plan['author'], Repository::carePlan()->getResource($stored->id)['author']);
    }

    public function test_sync_does_not_substitute_the_signed_in_employee_for_a_remote_author(): void
    {
        [$person, $employee] = $this->makeSyncContext();
        $this->actingAs(User::findOrFail($employee->userId));
        $plan = $this->fixture('plan');
        $api = $this->bindRemote($plan, [], $person->uuid);

        $api->synchronizeResources([['id' => $plan['id']]], $person->id, $employee->legalEntityId);

        $stored = CarePlan::whereUuid($plan['id'])->firstOrFail();
        $this->assertNull($stored->authorId);
        $this->assertSame($plan['author']['identifier']['value'], Repository::carePlan()->getResource($stored->id)['author']['identifier']['value']);
    }

    public function test_pending_write_is_queued_without_changing_the_resource_and_blocks_resubmission(): void
    {
        [$person, $employee] = $this->makeSyncContext();
        $this->actingAs(User::findOrFail($employee->userId));
        Bus::fake();
        Notification::fake();
        $resource = $this->fixture('plan');
        $resource['status'] = 'active';
        $repository = Repository::carePlan();
        $plan = $repository->storeResource($resource, $person->id, $employee->legalEntityId);
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn(['links' => [['entity' => 'job', 'href' => '/Jobs/test-job']], 'status' => 'pending']);
        $harness = new CarePlanSubmissionHarness();

        $this->assertNull($harness->submitClinicalOperation($plan, ['status' => 'cancelled'], fn () => $response));
        $this->assertSame('active', $plan->fresh()->status);
        $this->assertTrue($repository->hasPendingSubmission($plan));
        $this->assertSame('/Jobs/test-job', $plan->ehealthLinks()->firstOrFail()->href);
        $this->assertFalse($harness->showSignatureModal);
        Bus::assertBatched(fn ($batch) => $batch->jobs->first() instanceof RemoteEHealthLinksProcessing);
        $this->assertNull($harness->submitClinicalOperation($plan, ['status' => 'cancelled'], fn () => throw new RuntimeException('A duplicate request must not be sent')));
    }

    public function test_processed_job_updates_status_and_remote_metadata_once(): void
    {
        [$person, $employee] = $this->makeSyncContext();
        $user = User::findOrFail($employee->userId);
        Notification::fake();
        $repository = Repository::carePlan();
        $plan = $repository->storeResource($this->fixture('plan'), $person->id, $employee->legalEntityId);
        $link = $repository->beginSubmission($plan, ['status' => 'new']);
        $result = ['status' => 'processed', 'response_data' => ['id' => $plan->uuid, 'status' => 'active', 'requisition' => 'processed-requisition']];

        RemoteEHealthLinksProcessing::finishSubmission($link, $result, $user);
        $this->assertSame('active', $plan->fresh()->status);
        $this->assertSame('processed-requisition', $plan->fresh()->requisition);
        $this->assertFalse($repository->hasPendingSubmission($plan));
        RemoteEHealthLinksProcessing::finishSubmission($link->fresh(), $result, $user);
        Notification::assertSentToTimes($user, RemoteEHealthLinksNotification::class, 1);
    }

    public function test_rejected_job_preserves_the_previous_resource_and_allows_retry(): void
    {
        [$person, $employee] = $this->makeSyncContext();
        $user = User::findOrFail($employee->userId);
        Notification::fake();
        $repository = Repository::carePlan();
        $plan = $repository->storeResource($this->fixture('plan'), $person->id, $employee->legalEntityId);
        $before = $repository->getResource($plan->id);
        $link = $repository->beginSubmission($plan, ['status' => 'cancelled']);

        RemoteEHealthLinksProcessing::finishSubmission($link, ['status' => 'failed', 'error' => ['message' => 'Synthetic rejection']], $user);
        $this->assertSame($before, $repository->getResource($plan->id));
        $this->assertFalse($repository->hasPendingSubmission($plan));
        $this->assertNotSame($link->id, $repository->beginSubmission($plan, ['status' => 'cancelled'])->id);
    }

    public function test_standard_period_sync_preserves_utc_fractional_seconds_and_clears_an_omitted_end(): void
    {
        [$person, $employee] = $this->makeSyncContext();
        $plan = Repository::carePlan()->storeResource($this->fixture('plan'), $person->id, $employee->legalEntityId);
        $repository = MedicalEventsRepository::period();
        $period = ['start' => '2026-11-10T12:34:56.123456+03:00', 'end' => '2026-11-11T13:14:15.654321+03:00'];
        $repository->sync($plan, $period, 'effectivePeriod');
        $this->assertSame(['start' => '2026-11-10T09:34:56.123456Z', 'end' => '2026-11-11T10:14:15.654321Z'], $repository->toResource($plan->effectivePeriod));
        $periodId = $plan->effectivePeriod->id;
        $repository->sync($plan, ['end' => '2026-11-12T15:00:00.999999Z'], 'effectivePeriod');
        $this->assertSame('2026-11-10T09:34:56.123456Z', $repository->toResource($plan->effectivePeriod)['start']);
        $this->assertSame('2026-11-12T15:00:00.999999Z', $repository->toResource($plan->effectivePeriod)['end']);
        $repository->sync($plan, ['start' => null, 'end' => $period['end']], 'effectivePeriod');
        $this->assertNull($repository->toResource($plan->effectivePeriod)['start']);
        $repository->sync($plan, ['start' => $period['start']], 'effectivePeriod');
        $this->assertSame($periodId, $plan->effectivePeriod->id);
        $this->assertNull($repository->toResource($plan->effectivePeriod)['end']);
        $repository->sync($plan, null, 'effectivePeriod');
        $this->assertNull($plan->effectivePeriod);
    }

    public function test_unknown_submission_response_does_not_report_success_or_allow_a_duplicate(): void
    {
        [$person, $employee] = $this->makeSyncContext();
        $this->actingAs(User::findOrFail($employee->userId));
        Bus::fake();
        Notification::fake();
        $repository = Repository::carePlan();
        $plan = $repository->storeResource($this->fixture('plan'), $person->id, $employee->legalEntityId);
        $before = $repository->getResource($plan->id);
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn(['status' => 'pending']);

        $this->assertNull((new CarePlanSubmissionHarness())->submitClinicalOperation($plan, ['status' => 'cancelled'], fn () => $response));
        $this->assertSame($before, $repository->getResource($plan->id));
        $this->assertTrue($repository->hasPendingSubmission($plan));
        $this->assertNull(session('success'));
        Bus::assertNothingBatched();
        Notification::assertNothingSent();
    }

    public function test_pending_plan_and_activity_cannot_be_edited_or_deleted(): void
    {
        [$person, $employee] = $this->makeSyncContext();
        $repository = Repository::carePlan();
        $activityRepository = Repository::carePlanActivity();
        $resource = $this->fixture('plan');
        $resource['status'] = 'draft';
        $plan = $repository->storeResource($resource, $person->id, $employee->legalEntityId);
        $resource = $this->fixture('activity');
        $resource['detail']['status'] = 'draft';
        $activity = $activityRepository->storeResource($resource, $plan);
        foreach ([$activity, $plan] as $record) {
            $repository->beginSubmission($record, ['status' => 'new']);
            $this->assertFalse($repository->deleteDraft($plan->id));
            try {
                $record instanceof CarePlan
                    ? $repository->saveForm(['title' => 'Changed while pending'], $person->id, $employee->legalEntityId, $record->id)
                    : $activityRepository->saveForm(['description' => 'Changed while pending'], $plan, $record->id);
                $this->fail('A pending submission must prevent editing.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('submission', $exception->errors());
            }
        }
        $this->assertFalse($repository->deleteDraft($plan->id));
        $this->assertFalse($activityRepository->deleteById($activity->id));
        $this->assertSame('draft', $plan->fresh()->status);
        $this->assertSame('draft', $activity->fresh()->status);
    }

    public function test_mismatched_processed_resource_does_not_finish_or_modify_the_submission(): void
    {
        [$person, $employee] = $this->makeSyncContext();
        $repository = Repository::carePlan();
        $plan = $repository->storeResource($this->fixture('plan'), $person->id, $employee->legalEntityId);
        $before = $repository->getResource($plan->id);
        $link = $repository->beginSubmission($plan, ['status' => 'cancelled']);
        try {
            $repository->finishSubmission($link, ['status' => 'processed', 'response_data' => ['id' => (string) Str::uuid(), 'status' => 'cancelled']]);
            $this->fail('A processed resource must match the submitted UUID.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('id', $exception->errors());
        }
        $this->assertSame($before, $repository->getResource($plan->id));
        $this->assertTrue($repository->hasPendingSubmission($plan));
    }

    public function test_processed_activity_changes_only_the_selected_resource_and_preserves_outcomes(): void
    {
        [$person, $employee] = $this->makeSyncContext();
        $repository = Repository::carePlan();
        $activityRepository = Repository::carePlanActivity();
        $plan = $repository->storeResource($this->fixture('plan'), $person->id, $employee->legalEntityId);
        $resource = $this->fixture('activity');
        $activity = $activityRepository->storeResource($resource, $plan);
        $resource['id'] = (string) Str::uuid();
        $otherActivity = $activityRepository->storeResource($resource, $plan);
        $before = $activityRepository->getResource($otherActivity->id);
        $outcomes = [['coding' => [['system' => 'eHealth/care_plan_activity_outcomes', 'code' => 'resolved']]]];
        $link = $repository->beginSubmission($activity, ['status' => 'completed', 'outcomeCodeableConcept' => $outcomes]);

        $repository->finishSubmission($link, ['status' => 'processed', 'response_data' => ['id' => $activity->uuid, 'detail' => ['status' => 'completed']]]);

        $confirmed = $activityRepository->getResource($activity->id);
        $this->assertSame('completed', $confirmed['detail']['status']);
        $this->assertSame($outcomes, $confirmed['outcomeCodeableConcept']);
        $this->assertArrayNotHasKey('outcomeCodeableConcept', $confirmed['detail']);
        $this->assertSame($before, $activityRepository->getResource($otherActivity->id));
    }

    public function test_processed_job_with_only_links_fetches_the_confirmed_resource_in_the_queue(): void
    {
        [$person, $employee] = $this->makeSyncContext();
        Notification::fake();
        $repository = Repository::carePlan();
        $resource = $this->fixture('plan');
        $plan = $repository->storeResource($resource, $person->id, $employee->legalEntityId);
        $link = $repository->beginSubmission($plan, ['status' => 'new']);
        $resource['requisition'] = 'confirmed-from-details';
        $details = Mockery::mock(EHealthResponse::class);
        $details->shouldReceive('validate')->once()->andReturn($resource);
        $api = Mockery::mock(CarePlanApi::class);
        $api->shouldReceive('withToken')->once()->with('synthetic-job-token')->andReturnSelf();
        $api->shouldReceive('getDetails')->once()->with($person->uuid, $plan->uuid)->andReturn($details);
        $this->instance(CarePlanApi::class, $api);
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn(['status' => 'processed', 'status_code' => 200, 'links' => [['entity' => 'care_plan', 'href' => '/api/patients/'.$person->uuid.'/care_plans/'.$plan->uuid]]]);
        $job = new CarePlanProcessingHarness($link, legalEntity(), standalone: true);
        $job->user = User::findOrFail($employee->userId);

        $job->processConfirmed($response);

        $this->assertSame('confirmed-from-details', $plan->fresh()->requisition);
        $this->assertFalse($repository->hasPendingSubmission($plan));
    }

    public function test_queued_activity_registration_imports_the_confirmed_parent_status(): void
    {
        [$person, $employee] = $this->makeSyncContext();
        Notification::fake();
        $repository = Repository::carePlan();
        $activityRepository = Repository::carePlanActivity();
        $resource = $this->fixture('plan');
        $resource['status'] = 'new';
        $plan = $repository->storeResource($resource, $person->id, $employee->legalEntityId);
        $activityResource = $this->fixture('activity');
        $activityResource['detail']['status'] = 'draft';
        $activity = $activityRepository->storeResource($activityResource, $plan);
        $link = $repository->beginSubmission($activity, ['status' => 'scheduled']);
        $resource['status'] = 'active';
        $details = Mockery::mock(EHealthResponse::class);
        $details->shouldReceive('validate')->once()->andReturn($resource);
        $api = Mockery::mock(CarePlanApi::class);
        $api->shouldReceive('withToken')->once()->with('synthetic-job-token')->andReturnSelf();
        $api->shouldReceive('getDetails')->once()->with($person->uuid, $plan->uuid)->andReturn($details);
        $this->instance(CarePlanApi::class, $api);
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn(['status' => 'processed', 'status_code' => 200, 'response_data' => ['id' => $activity->uuid, 'detail' => ['status' => 'scheduled']]]);
        $job = new CarePlanProcessingHarness($link, legalEntity(), standalone: true);
        $job->user = User::findOrFail($employee->userId);

        $job->processConfirmed($response);

        $this->assertSame('scheduled', $activity->fresh()->status);
        $this->assertSame('active', $plan->fresh()->status);
        $this->assertFalse($repository->hasPendingSubmission($activity));
    }

    private function bindRemote(array $plan, array $activities, string $patientUuid): CarePlanSynchronizationHarness
    {
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn($plan);
        $api = Mockery::mock(CarePlanApi::class)->makePartial();
        $api->shouldReceive('getDetails')->once()->with($patientUuid, $plan['id'])->andReturn($response);
        $activityApi = Mockery::mock(ActivityApi::class);
        $summary = Mockery::mock(EHealthResponse::class);
        $summary->shouldReceive('validate')->once()->andReturn(array_map(fn ($activity) => ['id' => $activity['id']], $activities));
        $summary->shouldReceive('getPaging')->once()->andReturn(['total_pages' => 1]);
        $activityApi->shouldReceive('getSummary')->once()->with($patientUuid, $plan['id'], ['page' => 1])->andReturn($summary);
        foreach ($activities as $activity) {
            $detail = Mockery::mock(EHealthResponse::class);
            $detail->shouldReceive('validate')->once()->andReturn($activity);
            $activityApi->shouldReceive('getDetails')->once()->with($patientUuid, $plan['id'], $activity['id'])->andReturn($detail);
        }
        $this->instance(CarePlanApi::class, $api);
        $this->instance(ActivityApi::class, $activityApi);

        return new CarePlanSynchronizationHarness();
    }

    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/CarePlan/'.$name.'.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{0: Person, 1: Employee}
     */
    private function makeSyncContext(): array
    {
        $typeId = DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        $legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);
        $this->instance('legalEntity', $legalEntity);

        $person = Person::create([
            'uuid' => (string) Str::uuid(),
            'gender' => 'MALE',
            'birth_date' => '1990-01-01',
            'patient_signed' => true,
            'process_disclosure_data_consent' => true,
            'legal_entity_id' => $legalEntity->id,
        ]);

        $party = Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Gregory',
            'last_name' => 'Doctor',
            'tax_id' => (string) random_int(1000000000, 9999999999),
            'birth_date' => '1970-01-01',
            'gender' => 'MALE',
        ]);

        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'sync-'.Str::random(8).'@example.com',
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Dr. House',
            'employee_type' => Role::DOCTOR->value,
            'status' => Status::APPROVED->value,
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => 'Doctor',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $user->id,
            'party_id' => $party->id,
        ]);

        return [$person, $employee];
    }

}

class CarePlanSynchronizationHarness
{
    use SynchronizesCarePlans {
        synchronizeResources as public;
        synchronizeSearch as public;
        fetchAllActivityResources as public;
    }
}

class CarePlanSubmissionHarness extends Component
{
    use SubmitsClinicalOperations {
        submitClinicalOperation as public;
    }

    public bool $showSignatureModal = true;
}

class CarePlanProcessingHarness extends RemoteEHealthLinksProcessing
{
    public function processConfirmed(EHealthResponse $response): void
    {
        $this->token = 'synthetic-job-token';
        $this->processResponse($response);
    }
}
