<?php

declare(strict_types=1);

namespace Tests\Feature\CarePlan;

use App\Classes\eHealth\Api\CarePlan as CarePlanApi;
use App\Classes\eHealth\Api\CarePlanActivity as ActivityApi;
use App\Classes\eHealth\EHealthResponse;
use App\Core\Arr;
use App\Core\EHealthJob;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Jobs\CarePlanFullSync;
use App\Livewire\Concerns\ReadsClinicalSnapshots;
use App\Models\CarePlan;
use App\Models\LegalEntity;
use App\Repositories\CarePlanActivityRepository;
use App\Repositories\CarePlanRepository;
use App\Services\MedicalEvents\EHealthJobResolver;
use App\Traits\SynchronizesCarePlans;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Mockery;
use RuntimeException;
use Tests\Feature\CarePlan\CarePlanFhirSynchronizationHarness;
use Tests\Support\CarePlanWorkflowHarness;
use Tests\TestCase;

class CarePlanFhirApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ehealth.api.domain', 'https://ehealth.invalid');
        Http::preventStrayRequests();
    }

    public function test_create_content_uses_the_endpoint_shape_and_excludes_server_fields(): void
    {
        $plan = $this->fixture('plan');
        $plan['status'] = 'draft';
        $plan['requisition'] = 'server-number';
        $actual = (new CarePlanWorkflowHarness())->buildCreateCarePlanContent($plan);

        $this->assertSame('new', $actual['status']);
        $this->assertSame('order', $actual['intent']);
        $this->assertSame($plan['period']['start'], $actual['period']['start']);
        $this->assertCount(2, $actual['supporting_info']);
        $this->assertArrayNotHasKey('requisition', $actual);
        $this->assertArrayNotHasKey('care_manager', $actual);
    }

    public function test_activity_create_keeps_false_and_fractional_numbers_but_not_remote_remainder(): void
    {
        $activity = $this->fixture('activity');
        $actual = (new CarePlanWorkflowHarness())->buildCreateCarePlanActivityContent($activity);

        $this->assertSame(1.2345, $actual['detail']['quantity']['value']);
        $this->assertFalse($actual['detail']['do_not_perform']);
        $this->assertArrayNotHasKey('remaining_quantity', $actual['detail']);
        $this->assertArrayNotHasKey('remaining_quantity_type', $actual['detail']);
        $this->assertCount(2, $actual['author']);
        $this->assertSame('scheduled', $actual['detail']['status']);
    }

    public function test_device_activity_sign_content_uses_a_json_integer_without_changing_other_quantities(): void
    {
        $activity = $this->fixture('activity');
        $activity['detail']['kind'] = 'device_request';
        $activity['detail']['quantity'] = ['value' => 1.0, 'system' => 'device_unit', 'code' => 'piece'];
        $activity['detail']['dailyAmount']['value'] = 0.5;
        $actual = (new CarePlanWorkflowHarness())->buildCreateCarePlanActivityContent($activity);
        $decoded = json_decode(json_encode($actual, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $decoded['detail']['quantity']['value']);
        $this->assertSame(0.5, $decoded['detail']['daily_amount']['value']);
        $this->assertSame(1.0, $activity['detail']['quantity']['value']);
    }

    public function test_device_activity_fractional_quantity_is_rejected_before_signing_instead_of_rounded(): void
    {
        $activity = $this->fixture('activity');
        $activity['detail']['kind'] = 'device_request';
        $activity['detail']['quantity']['value'] = 1.5;
        try {
            (new CarePlanWorkflowHarness())->buildCreateCarePlanActivityContent($activity);
            $this->fail('Fractional device quantities must not be truncated.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quantity.value', $exception->errors());
        }
    }

    public function test_cancel_preserves_the_raw_snapshot_except_the_documented_changes(): void
    {
        $snapshot = Arr::toSnakeCase($this->fixture('activity'));
        $snapshot['inserted_at'] = '2026-10-19T10:20:30.123Z';
        $snapshot['remote_extension'] = ['false' => false, 'zero' => 0, 'empty' => []];
        $reason = ['coding' => [['system' => 'eHealth/care_plan_activity_cancel_reasons', 'code' => 'TEST']]];
        $actual = (new CarePlanWorkflowHarness())->buildCancelCarePlanActivityContent($snapshot, $reason);
        $this->assertSame($reason, $actual['detail']['status_reason']);
        unset($actual['detail']['status_reason']);
        $this->assertSame($snapshot, $actual);
        $this->assertArrayNotHasKey('status_reason', $snapshot['detail']);

        $plan = Arr::toSnakeCase($this->fixture('plan'));
        $plan['activities'] = [$snapshot];
        $actualPlan = (new CarePlanWorkflowHarness())->buildCancelCarePlanContent($plan, $reason);
        unset($plan['activities']);
        $plan['status_reason'] = $reason;
        $this->assertSame($plan, $actualPlan);
    }

    public function test_complete_is_an_unsigned_action_body_without_snapshot_fields(): void
    {
        $reason = ['coding' => [['system' => 'eHealth/care_plan_activity_complete_reasons', 'code' => 'TEST']]];
        $outcomes = [['coding' => [['system' => 'eHealth/care_plan_activity_outcomes', 'code' => 'TEST']]]];

        $this->assertSame(['detail' => ['status_reason' => $reason], 'outcome_codeable_concept' => $outcomes], (new CarePlanWorkflowHarness())->buildCompleteCarePlanActivityContent($reason, $outcomes));
    }

    public function test_signed_content_is_submitted_unchanged_without_polling_the_job(): void
    {
        Http::fake(['*' => Http::response(['data' => ['job_id' => 'job-test']])]);
        $resolver = Mockery::mock(EHealthJobResolver::class);
        $resolver->shouldNotReceive('resolve');
        $this->instance(EHealthJobResolver::class, $resolver);

        $result = $this->fakeApi(CarePlanApi::class)->create('patient-test', ['signed_data' => 'immutable-signed-content', 'signed_data_encoding' => 'base64'])->getData();

        $this->assertSame('job-test', $result['job_id']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['signed_data'] === 'immutable-signed-content' && $request['signed_data_encoding'] === 'base64');
    }

    public function test_all_activity_pages_and_details_are_loaded(): void
    {
        $fixture = $this->fixture('activity');
        $secondId = '52d4aff8-eb7b-4977-ac72-845de91c4fe0';
        Http::fake(function (Request $request) use ($fixture, $secondId) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/activities')) {
                parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
                $id = (int) $query['page'] === 1 ? $fixture['id'] : $secondId;

                return Http::response(['data' => [Arr::toSnakeCase(array_replace($fixture, ['id' => $id]))], 'paging' => ['total_pages' => 2]]);
            }
            $resource = $fixture;
            $resource['id'] = basename($path);

            return Http::response(['data' => Arr::toSnakeCase($resource)]);
        });

        $this->instance(ActivityApi::class, $this->fakeApi(ActivityApi::class));
        $actual = (new CarePlanFhirSynchronizationHarness())->fetchAllActivityResources('patient-test', $fixture['carePlan']['identifier']['value']);

        $this->assertSame([$fixture['id'], $secondId], array_column($actual, 'id'));
        $this->assertSame(1.2345, $actual[1]['detail']['quantity']['value']);
        Http::assertSentCount(4);
    }

    public function test_failed_later_page_does_not_persist_a_partial_collection(): void
    {
        $fixture = $this->fixture('activity');
        Http::fake(function (Request $request) use ($fixture) {
            if (str_contains($request->url(), 'page=2')) {
                return Http::response(['error' => ['message' => 'Synthetic outage']], 503);
            }
            if (str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/activities')) {
                return Http::response(['data' => [Arr::toSnakeCase($fixture)], 'paging' => ['total_pages' => 2]]);
            }

            return Http::response(['data' => Arr::toSnakeCase($fixture)]);
        });
        $repository = Mockery::mock(CarePlanActivityRepository::class);
        $repository->shouldNotReceive('storeResources');
        $this->instance(CarePlanActivityRepository::class, $repository);
        $plan = new CarePlan(['uuid' => $fixture['carePlan']['identifier']['value']]);
        $this->expectException(EHealthResponseException::class);

        $this->instance(ActivityApi::class, $this->fakeApi(ActivityApi::class));
        (new CarePlanFhirSynchronizationHarness())->fetchAllActivityResources('patient-test', $plan->uuid);
    }

    public function test_cancel_fetch_failure_is_propagated_instead_of_rebuilding_a_local_payload(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Not found']], 404)]);
        $this->expectException(EHealthResponseException::class);

        $this->instance(ActivityApi::class, $this->fakeApi(ActivityApi::class));
        (new CarePlanFhirSynchronizationHarness())->fetchActivityCancelSnapshot('patient-test', 'plan-test', 'activity-test');
    }

    private function fixture(string $name): array
    {
        $resource = json_decode(file_get_contents(base_path('tests/Fixtures/CarePlan/'.$name.'.json')), true, flags: JSON_THROW_ON_ERROR);
        $reference = static fn (string $type, string $value): array => ['identifier' => ['type' => ['coding' => [['system' => 'eHealth/resources', 'code' => $type]]], 'value' => $value]];
        $resource['insertedAt'] = $resource['updatedAt'] = '2026-10-19T10:20:30Z';
        $resource['insertedBy'] = $resource['updatedBy'] = '47ee824a-eb47-48ef-8d30-1109e3c89f0c';
        if ($name === 'activity') {
            $resource['detail']['program'] = $reference('medical_program', '4eddd5ce-c39a-40c2-a2c7-5ee5e7e820ee');
        } else {
            unset($resource['period']['end']);
            $resource['requisition'] = '1111-2222-3333-4444';
            $resource['subject'] = $reference('person', '4eddd5ce-c39a-40c2-a2c7-5ee5e7e820ee');
            $resource['managingOrganization'] = $reference('legal_entity', '346aad58-6164-4e32-98b5-d178cbf850db') + ['displayValue' => 'Synthetic clinic'];
            $resource['statusHistory'] = [['status' => 'new', 'insertedAt' => $resource['insertedAt'], 'insertedBy' => $resource['insertedBy']]];
        }

        return $resource;
    }

    public function test_list_validation_returns_normalized_fields_without_discarding_metadata(): void
    {
        Http::fake(['*' => Http::response(['data' => [Arr::toSnakeCase($this->fixture('activity'))]])]);

        $items = $this->fakeApi(ActivityApi::class)->getSummary('patient-test', 'plan-test')->validate();

        $this->assertArrayHasKey('doNotPerform', $items[0]['detail']);
        $this->assertFalse($items[0]['detail']['doNotPerform']);
        $this->assertCount(2, $items[0]['author']);
    }

    public function test_plan_list_validates_the_normalized_managing_organization(): void
    {
        $plan = $this->fixture('plan');
        $plan['managingOrganization'] = [
            'identifier' => ['type' => ['coding' => [['system' => 'eHealth/resources', 'code' => 'legal_entity']]], 'value' => '346aad58-6164-4e32-98b5-d178cbf850db'],
            'displayValue' => 'Synthetic clinic',
        ];
        Http::fake(['*' => Http::response(['data' => [Arr::toSnakeCase($plan)]])]);

        $items = $this->fakeApi(CarePlanApi::class)->getSummary('patient-test')->validate();

        $this->assertSame($plan['managingOrganization'], $items[0]['managingOrganization']);
        $this->assertSame($plan['period'], $items[0]['period']);
    }

    public function test_sync_job_passes_its_patient_and_token_to_the_integration(): void
    {
        $plan = $this->fixture('plan');
        $data = [['id' => $plan['id']]];
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn($data);
        $api = Mockery::mock(CarePlanApi::class);
        $api->shouldReceive('withToken')->once()->with('queue-token')->andReturnSelf();
        $details = Mockery::mock(EHealthResponse::class);
        $details->shouldReceive('validate')->once()->andReturn($plan);
        $api->shouldReceive('getDetails')->once()->with('patient-test', $plan['id'])->andReturn($details);
        $activities = Mockery::mock(ActivityApi::class);
        $activities->shouldReceive('withToken')->once()->with('queue-token')->andReturnSelf();
        $empty = Mockery::mock(EHealthResponse::class);
        $empty->shouldReceive('validate')->once()->andReturn([]);
        $empty->shouldReceive('getPaging')->once()->andReturn(['total_pages' => 1]);
        $activities->shouldReceive('getSummary')->once()->with('patient-test', $plan['id'], ['page' => 1])->andReturn($empty);
        $this->instance(ActivityApi::class, $activities);
        $repository = Mockery::mock(CarePlanRepository::class);
        $repository->shouldReceive('resolvePatientContext')->once()->andReturn(['id' => 1, 'uuid' => 'patient-test']);
        $repository->shouldReceive('storeAggregate')->once()->with($plan, [], 1, 2);
        $this->instance(CarePlanRepository::class, $repository);
        $this->instance(CarePlanApi::class, $api);
        $legalEntity = new LegalEntity();
        $legalEntity->id = 2;
        $job = new class ($legalEntity) extends CarePlanFullSync
        {
            public function runResponse(EHealthResponse $response): void
            {
                $this->personId = 1;
                $this->patientUuid = 'patient-test';
                $this->token = 'queue-token';
                $this->processResponse($response);
            }
        };

        $job->runResponse($response);
        $this->assertSame(2, $job->legalEntity->id);
    }

    public function test_standalone_sync_queues_each_plan_before_loading_its_details(): void
    {
        $ids = [$this->fixture('plan')['id'], '7a78997f-3b7b-4b7b-b1b1-40dd57ad982a'];
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn(array_map(static fn ($id): array => ['id' => $id], $ids));
        $repository = Mockery::mock(CarePlanRepository::class);
        $repository->shouldReceive('storeSummaries')->once()->with(array_map(static fn ($id): array => ['id' => $id], $ids), 1, 2);
        $this->instance(CarePlanRepository::class, $repository);
        $api = Mockery::mock(CarePlanApi::class);
        $api->shouldNotReceive('getDetails');
        $this->instance(CarePlanApi::class, $api);
        $legalEntity = new LegalEntity();
        $legalEntity->id = 2;
        $job = new class ($legalEntity, standalone: true) extends CarePlanFullSync
        {
            public function runResponse(EHealthResponse $response): void
            {
                $this->personId = 1;
                $this->processResponse($response);
            }

            public function nextJob(): ?EHealthJob
            {
                return $this->getNextEntityJob();
            }
        };
        [$job, $batch] = $job->withFakeBatch();
        $batch->options['patient_care_plan_registry'] = true;
        $job->runResponse($response);
        $this->assertCount(2, $batch->added);
        $this->assertSame($ids, array_map(static fn ($child): ?string => $child->resourceUuid, $batch->added));
        $this->assertTrue($batch->added[0]->standalone);
        $this->assertNull($job->nextJob());
        $job->failed(new RuntimeException('Synthetic failure'));
    }

    private function fakeApi(string $class): CarePlanApi|ActivityApi
    {
        $factory = Http::getFacadeRoot();
        $api = new $class($factory);
        $stubs = (function () {
            return $this->stubCallbacks;
        })->call($factory);
        $api->stub($stubs)->preventStrayRequests();

        return $api;
    }

    public function test_requisition_search_collects_every_page_before_importing_resources(): void
    {
        $ids = [$this->fixture('plan')['id'], '7a78997f-3b7b-4b7b-b1b1-40dd57ad982a'];
        $api = Mockery::mock(CarePlanApi::class)->makePartial();
        foreach ($ids as $index => $id) {
            $response = Mockery::mock(EHealthResponse::class);
            $response->shouldReceive('validate')->once()->andReturn([['id' => $id]]);
            $response->shouldReceive('getPaging')->once()->andReturn(['total_pages' => 2]);
            $api->shouldReceive('getMany')->once()->with(['requisition' => 'TEST', 'page' => $index + 1])->andReturn($response);
        }
        $this->instance(CarePlanApi::class, $api);
        $harness = Mockery::mock(CarePlanFhirSynchronizationHarness::class)->makePartial();
        $harness->shouldReceive('synchronizeResources')->once()->with([['id' => $ids[0]], ['id' => $ids[1]]], null, 1);

        $harness->synchronizeSearch(['requisition' => 'TEST'], 1);
    }

}

class CarePlanFhirSynchronizationHarness
{
    use ReadsClinicalSnapshots {
        fetchActivityCancelSnapshot as public;
    }

    use SynchronizesCarePlans {
        synchronizeResources as public;
        synchronizeSearch as public;
        fetchAllActivityResources as public;
    }
}
