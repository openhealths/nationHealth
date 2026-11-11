<?php

declare(strict_types=1);

namespace Tests\Feature\CarePlan;

use App\Classes\eHealth\Api\CarePlan as CarePlanApi;

use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Jobs\CarePlanFullSync;
use App\Livewire\CarePlan\CarePlanIndex;
use App\Livewire\Person\Records\PatientCarePlans;
use App\Models\CarePlan;
use App\Models\Person\Person;
use App\Repositories\CarePlanRepository;
use App\Repositories\Repository;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;
use Tests\Feature\Composition\CompositionTestFixtures;
use Tests\Feature\Composition\UsesCompositionDictionaries;
use Tests\TestCase;

class CarePlanAsyncSyncTest extends TestCase
{
    use CompositionTestFixtures;
    use DatabaseTransactions;
    use UsesCompositionDictionaries;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('uk');
        Http::preventStrayRequests();
        Queue::fake();
        session()->put(config('ehealth.api.oauth.bearer_token'), 'synthetic-token');
    }

    public function test_patient_sync_saves_only_first_page_summaries_and_queues_details_and_page_two(): void
    {
        ['person' => $person, 'legalEntity' => $legalEntity] = $this->compositionFixture(scopes: ['care_plan:read']);
        $summary = $this->summary();
        $this->firstPage($person, [$summary], true);
        config()->set('queue.default', 'sync');

        $component = Livewire::test(PatientCarePlans::class, ['legalEntity' => $legalEntity, 'personId' => $person->id])
            ->call('sync')->assertSee(__('care-plan.sync_first_page'))->assertDontSee(__('care-plan.sync_success'))
            ->assertSet('carePlans', [])->assertNotDispatched('flashMessage');

        $plan = CarePlan::whereUuid($summary['id'])->firstOrFail();
        // A summary may be offered in related-plan filters without being a registry row.
        $component->assertDontSeeHtml('wire:key="care-plan-'.$plan->id.'"')
            ->set('filterCompleteness', 'all')->assertSeeHtml('wire:key="care-plan-'.$plan->id.'"');
        $this->assertNull($plan->detailsSyncedAt);
        $batch = Bus::findBatch($component->get('syncBatchId'));
        $this->assertSame($person->uuid, $batch->options['patient_uuid']);
        $this->assertSame($person->id, $batch->options['person_id']);
        $this->assertTrue($batch->options['patient_care_plan_registry']);
        $this->assertSame('database', $batch->options['connection']);
        $this->assertTrue($batch->allowsFailures());
        Queue::assertPushed(CarePlanFullSync::class, 2);
        Queue::assertPushed(CarePlanFullSync::class, fn ($job): bool => $job->resourceUuid === $summary['id'] && $job->patientContext['patient_uuid'] === $person->uuid);
        Queue::assertPushed(CarePlanFullSync::class, fn ($job): bool => $job->resourceUuid === null && (new ReflectionProperty($job, 'page'))->getValue($job) === 2);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $component->call('checkSyncStatus')->assertSet('syncBatchId', $batch->id);
        $this->assertSame([], array_values(array_filter(DB::getQueryLog(), static fn ($query): bool => str_contains($query['query'], 'from "care_plans"'))));
        DB::disableQueryLog();

        $component->call('sync')->assertSee(__('care-plan.sync_running'));
        Queue::assertPushed(CarePlanFullSync::class, 2);
        DB::table('job_batches')->where('id', $batch->id)->update(['pending_jobs' => 0, 'finished_at' => time()]);
        $component->call('checkSyncStatus')->assertSet('syncBatchId', null)->assertSee(__('care-plan.sync_success'))
            ->call('resetFilters')->assertDontSee(__('care-plan.sync_success'));
    }

    public function test_index_fetches_one_page_for_the_first_patient_and_queues_other_patients_with_their_context(): void
    {
        ['person' => $person, 'legalEntity' => $legalEntity] = $this->compositionFixture(scopes: ['care_plan:read']);
        $other = Person::create(['uuid' => (string) Str::uuid(), 'birth_date' => '2000-01-01', 'gender' => 'MALE']);
        foreach ([$person, $other] as $patient) {
            CarePlan::forceCreate(['uuid' => (string) Str::uuid(), 'personId' => $patient->id, 'legalEntityId' => $legalEntity->id, 'status' => 'active', 'title' => 'Existing plan']);
        }
        $summary = $this->summary();
        $this->firstPage($person, [$summary], true);

        $component = Livewire::test(CarePlanIndex::class)->call('sync')->assertSee(__('care-plan.sync_first_page'))
            ->assertDontSee(__('care-plan.sync_success'))->assertNotDispatched('flashMessage');

        $ids = $component->get('syncBatchIds');
        $this->assertCount(2, $ids);
        $batches = array_map(fn ($id) => Bus::findBatch($id), $ids);
        $this->assertSame([$person->uuid, $other->uuid], array_column(array_map(fn ($batch) => $batch->options, $batches), 'patient_uuid'));
        foreach ($batches as $batch) {
            $this->assertTrue($batch->options['patient_care_plan_registry']);
            $this->assertSame('database', $batch->options['connection']);
            $this->assertTrue($batch->allowsFailures());
        }
        Queue::assertPushed(CarePlanFullSync::class, 3);
        $component->call('sync')->assertSee(__('care-plan.sync_running'));
        Queue::assertPushed(CarePlanFullSync::class, 3);

        DB::table('job_batches')->whereIn('id', $ids)->update(['pending_jobs' => 0, 'finished_at' => time()]);
        $component->call('checkSyncStatus')->assertSet('syncBatchIds', [])->assertSee(__('care-plan.sync_success'));
    }

    public static function patientFailures(): array
    {
        return ['first patient API denial' => [true], 'middle patient lock conflict' => [false]];
    }

    #[DataProvider('patientFailures')]
    public function test_index_continues_after_a_patient_failure_and_never_reports_full_success(bool $firstPatientFails): void
    {
        ['person' => $person, 'legalEntity' => $legalEntity, 'user' => $user] = $this->compositionFixture(scopes: ['care_plan:read']);
        $patients = new Collection([$person]);
        for ($index = 0; $index < 2; $index++) {
            $patients->push(Person::create(['uuid' => (string) Str::uuid(), 'birth_date' => '2000-01-01', 'gender' => 'MALE']));
        }
        $repository = Mockery::mock(CarePlanRepository::class)->makePartial();
        $repository->shouldReceive('patientsForSync')->once()->with($legalEntity->id, $user)->andReturn($patients);
        $this->instance(CarePlanRepository::class, $repository);
        $lock = null;
        if ($firstPatientFails) {
            $api = Mockery::mock(CarePlanApi::class);
            $api->shouldReceive('withToken')->once()->with('synthetic-token')->andReturnSelf();
            $api->shouldReceive('getBySearchParams')->once()->with($person->uuid, ['page' => 1])
                ->andThrow(new EHealthResponseException(new Response(new PsrResponse(403, [], '{"error":{"message":"Forbidden"}}'))));
            $this->instance(CarePlanApi::class, $api);
        } else {
            $this->firstPage($person, [$this->summary()], false);
            $lock = Cache::lock('care-plan-sync:'.$legalEntity->id.':'.$patients[1]->uuid, 120);
            $this->assertTrue($lock->get());
        }

        try {
            $component = Livewire::test(CarePlanIndex::class)->call('sync')
                ->assertSet('syncInitiationFailed', true)->assertSee(__('care-plan.sync_failed'))
                ->assertDontSee(__('care-plan.sync_success'));
            $ids = $component->get('syncBatchIds');
            $this->assertCount(2, $ids);
            Queue::assertPushed(CarePlanFullSync::class, 2);
            Queue::assertPushed(CarePlanFullSync::class, fn ($job): bool => $job->patientContext['patient_uuid'] === $patients[2]->uuid);
            DB::table('job_batches')->whereIn('id', $ids)->update(['pending_jobs' => 0, 'finished_at' => time()]);
            $component->call('checkSyncStatus')->assertSet('syncBatchIds', [])
                ->assertSee(__('care-plan.sync_failed'))->assertDontSee(__('care-plan.sync_success'));
        } finally {
            $lock?->release();
        }
    }

    public static function unauthorizedUsers(): array
    {
        return ['missing scope' => [false], 'missing active employee' => [true]];
    }

    #[DataProvider('unauthorizedUsers')]
    public function test_index_sync_is_forbidden_without_scope_or_active_employee(bool $hasScope): void
    {
        ['user' => $user] = $this->compositionFixture(scopes: $hasScope ? ['care_plan:read'] : []);
        if ($hasScope) {
            $user->employees()->detach();
        }

        Livewire::test(CarePlanIndex::class)->call('sync')
            ->assertOk()
            ->assertSet('syncBatchIds', [])
            ->assertSee(__('care-plan.sync_access_denied'));

        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_empty_first_page_finishes_without_queuing_a_page_or_details(): void
    {
        ['person' => $person, 'legalEntity' => $legalEntity] = $this->compositionFixture(scopes: ['care_plan:read']);
        $this->firstPage($person, [], false);

        Livewire::test(PatientCarePlans::class, ['legalEntity' => $legalEntity, 'personId' => $person->id])
            ->call('sync')->assertSet('syncBatchId', null)->assertSee(__('care-plan.sync_success'));
        Queue::assertNothingPushed();
    }

    public function test_a_configured_asynchronous_connection_is_preserved(): void
    {
        ['person' => $person, 'legalEntity' => $legalEntity] = $this->compositionFixture(scopes: ['care_plan:read']);
        config()->set('queue.default', 'redis');
        $this->firstPage($person, [$this->summary()], false);

        $component = Livewire::test(PatientCarePlans::class, ['legalEntity' => $legalEntity, 'personId' => $person->id])->call('sync');

        $this->assertSame('redis', Bus::findBatch($component->get('syncBatchId'))->options['connection']);
        Queue::assertPushed(CarePlanFullSync::class, 1);
    }

    public function test_dispatch_failure_does_not_report_completion_or_leave_the_button_disabled(): void
    {
        ['person' => $person, 'legalEntity' => $legalEntity] = $this->compositionFixture(scopes: ['care_plan:read']);
        $this->firstPage($person, [$this->summary()], false);
        Bus::partialMock()->shouldReceive('batch')->once()->andThrow(new RuntimeException('Synthetic dispatch failure'));

        Livewire::test(PatientCarePlans::class, ['legalEntity' => $legalEntity, 'personId' => $person->id])
            ->call('sync')->assertSet('syncBatchId', null)->assertSee(__('care-plan.sync_failed'))
            ->assertDontSee(__('care-plan.sync_success'))->assertDontSee(__('care-plan.sync_first_page'));
    }

    private function summary(): array
    {
        return ['id' => (string) Str::uuid(), 'title' => 'First page summary', 'status' => 'active'];
    }

    #[DataProvider('partialFailureOrder')]
    public function test_a_failed_plan_does_not_cancel_other_imports_or_leave_the_sync_button_disabled(bool $failureFirst): void
    {
        ['person' => $person, 'legalEntity' => $legalEntity] = $this->compositionFixture(scopes: ['care_plan:read']);
        Notification::fake();
        $this->firstPage($person, [$this->summary(), $this->summary()], false);
        $component = Livewire::test(PatientCarePlans::class, ['legalEntity' => $legalEntity, 'personId' => $person->id])->call('sync');
        $batch = Bus::findBatch($component->get('syncBatchId'));
        if ($failureFirst) {
            $batch->recordFailedJob('failed-plan', new RuntimeException('Synthetic failure'));
        } else {
            $batch->recordSuccessfulJob('successful-plan');
        }
        $this->assertFalse($batch->fresh()->cancelled());
        $this->assertFalse($batch->fresh()->finished());
        $component->call('checkSyncStatus')->assertSet('syncBatchId', $batch->id);

        if ($failureFirst) {
            $batch->recordSuccessfulJob('successful-plan');
        } else {
            $batch->recordFailedJob('failed-plan', new RuntimeException('Synthetic failure'));
        }
        $this->assertTrue($batch->fresh()->finished());
        $component->call('checkSyncStatus')->assertSet('syncBatchId', null)
            ->assertSee(__('care-plan.sync_failed'))->assertDontSee(__('care-plan.sync_success'));
        $this->assertFalse($batch->fresh()->cancelled());
    }

    public static function partialFailureOrder(): array
    {
        return ['failure first' => [true], 'failure last' => [false]];
    }

    public function test_a_summary_does_not_replace_an_existing_complete_resource(): void
    {
        ['person' => $person, 'legalEntity' => $legalEntity] = $this->compositionFixture(scopes: ['care_plan:read']);
        $resource = json_decode(file_get_contents(base_path('tests/Fixtures/CarePlan/plan.json')), true, flags: JSON_THROW_ON_ERROR);
        $repository = Repository::carePlan();
        $plan = $repository->storeResource($resource, $person->id, $legalEntity->id);
        $repository->storeSummaries([['id' => $plan->uuid, 'title' => 'Incomplete summary', 'status' => 'completed']], $person->id, $legalEntity->id);

        $this->assertSame($resource['title'], $plan->fresh()->title);
        $this->assertSame('completed', $plan->fresh()->status);
        $this->assertSame($resource['category'], $repository->getResource($plan->fresh())['category']);
        $this->assertNotNull($plan->fresh()->detailsSyncedAt);
        $this->assertSame(1, CarePlan::wherePersonId($person->id)->whereUuid($plan->uuid)->count());
    }

    public function test_expired_session_does_not_call_ehealth_or_queue_jobs(): void
    {
        ['person' => $person, 'legalEntity' => $legalEntity] = $this->compositionFixture(scopes: ['care_plan:read']);
        session()->forget(config('ehealth.api.oauth.bearer_token'));

        Livewire::test(PatientCarePlans::class, ['legalEntity' => $legalEntity, 'personId' => $person->id])
            ->call('sync')->assertSet('syncBatchId', null)->assertSee(__('care-plan.sync_session_expired'));
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    private function firstPage(Person $person, array $summaries, bool $more): void
    {
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn($summaries);
        $response->shouldReceive('isNotLast')->once()->andReturn($more);
        $api = Mockery::mock(CarePlanApi::class);
        $api->shouldReceive('withToken')->once()->with('synthetic-token')->andReturnSelf();
        $api->shouldReceive('getBySearchParams')->once()->with($person->uuid, ['page' => 1])->andReturn($response);
        $api->shouldNotReceive('getDetails');
        $api->shouldNotReceive('getMany');
        $this->instance(CarePlanApi::class, $api);
    }
}
