<?php

declare(strict_types=1);

namespace Tests\Feature\CarePlan;

use App\Console\Commands\BackfillCarePlanFhir;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use App\Repositories\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\Support\CarePlanWorkflowHarness;
use Tests\TestCase;

class CarePlanFhirPersistenceTest extends TestCase
{
    use DatabaseTransactions {
        beginDatabaseTransaction as beginIsolatedDatabaseTransaction;
    }

    public function beginDatabaseTransaction(): void
    {
        $this->assertSame('pgsql', config('database.default'));
        $this->assertSame('testing', DB::connection()->getDatabaseName());
        // A private transactional schema keeps the install/update contract independent
        // of the ordinary CI schema, without dropping or modifying existing tables.
        $schemaName = 'care_plan_contract_'.bin2hex(random_bytes(8));
        config(['database.connections.pgsql.search_path' => $schemaName]);
        DB::purge('pgsql');
        $this->beginIsolatedDatabaseTransaction();
        DB::statement('CREATE SCHEMA "'.$schemaName.'"');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', DB::connection()->getDatabaseName());
        $this->assertFalse(Schema::hasTable('care_plans'), 'The private contract schema must be empty. Existing tables are never dropped.');
        Http::preventStrayRequests();
        foreach (['persons', 'legal_entities', 'employees', 'encounters'] as $name) {
            Schema::create($name, static function (Blueprint $table) use ($name): void {
                $table->id();
                $table->uuid('uuid')->nullable();
                if ($name === 'employees') {
                    $table->unsignedBigInteger('legal_entity_id');
                }
            });
        }
        Schema::create('identifiers', static function (Blueprint $table): void {
            $table->id();
            $table->string('value');
            $table->string('display_value')->nullable();
            $table->timestamps();
        });
        Schema::create('codeable_concepts', static function (Blueprint $table): void {
            $table->id();
            $table->text('text')->nullable();
            $table->nullableMorphs('codeable_conceptable');
            $table->timestamps();
        });
        foreach (['2025_12_10_000052_create_codings_table.php', '2025_12_10_000066_create_periods_table.php', '2025_12_10_000072_create_quantities_table.php', '2026_04_16_000000_create_care_plans_full.php', '2026_06_10_170245_add_ehealth_jobs_table.php', '2026_06_12_094307_create_ehealth_links_table.php'] as $file) {
            (require database_path('migrations/install/'.$file))->up();
        }
        (require database_path('migrations/update/0_1/2026_10_19_081226_complete_care_plan_fhir_storage.php'))->up();
        DB::table('persons')->insert(['id' => 1]);
        DB::table('legal_entities')->insert(['id' => 1]);
    }

    public function test_full_resources_survive_sql_round_trip_and_repeat_import_without_duplicates(): void
    {
        $planResource = json_decode(file_get_contents(base_path('tests/Fixtures/CarePlan/plan.json')), true, flags: JSON_THROW_ON_ERROR);
        $activityResource = json_decode(file_get_contents(base_path('tests/Fixtures/CarePlan/activity.json')), true, flags: JSON_THROW_ON_ERROR);
        $activityResource['carePlan']['displayValue'] = 'Synthetic parent';
        $activityResource['carePlan']['identifier']['type']['coding'][] = ['system' => 'synthetic/parent', 'code' => 'SECOND'];
        $activityResource['detail']['reasonCode'][0]['coding'][] = ['system' => 'synthetic/second', 'code' => 'SECOND'];
        $planResource['subject'] = $planResource['author'];
        $plans = Repository::carePlan();
        $activities = Repository::carePlanActivity();
        $plan = $plans->storeResource($planResource, 1, 1);
        $activity = $activities->storeResource($activityResource, $plan);
        $counts = $this->primitiveCounts();

        $plans->storeResource($planResource, 1, 1);
        $activities->storeResource($activityResource, $plan);
        $actualPlan = $plans->getResource($plan->id);
        $actualActivity = $activities->getResource($activity->id);

        $this->assertSame($counts, $this->primitiveCounts());
        $this->assertSame($planResource['period'], $actualPlan['period']);
        $this->assertSame($planResource['category'], $actualPlan['category']);
        $this->assertEquals($planResource['subject'], $actualPlan['subject']);
        $this->assertEquals($planResource['supportingInfo'], $actualPlan['supportingInfo']);
        $this->assertEquals($activityResource['author'], $actualActivity['author']);
        $this->assertEquals($activityResource['carePlan'], $actualActivity['carePlan']);
        $this->assertSame($activityResource['detail']['reasonCode'], $actualActivity['detail']['reasonCode']);
        $this->assertSame($activityResource['detail']['goal'], $actualActivity['detail']['goal']);
        $this->assertSame(1.2345, $actualActivity['detail']['quantity']['value']);
        $this->assertEquals(0, $actualActivity['detail']['remainingQuantity']['value']);
        $this->assertFalse($actualActivity['detail']['doNotPerform']);
        $this->assertArrayNotHasKey('userSelected', $actualActivity['detail']['reasonCode'][0]['coding'][1]);
        $this->assertNull($plan->authorId, 'An unknown remote author is preserved as a reference, not replaced by another employee.');
        $this->assertSame($activityResource['detail']['scheduledPeriod'], $actualActivity['detail']['scheduledPeriod']);
    }

    public function test_clearing_a_collection_removes_its_links(): void
    {
        $resource = json_decode(file_get_contents(base_path('tests/Fixtures/CarePlan/plan.json')), true, flags: JSON_THROW_ON_ERROR);
        $repository = Repository::carePlan();
        $plan = $repository->storeResource($resource, 1, 1);
        $resource['supportingInfo'] = [];
        $repository->storeResource($resource, 1, 1);

        $this->assertSame([], $repository->getResource($plan->id)['supportingInfo']);
    }

    public function test_existing_activity_form_preserves_rich_references_authors_codes_and_precise_schedule(): void
    {
        $planResource = json_decode(file_get_contents(base_path('tests/Fixtures/CarePlan/plan.json')), true, flags: JSON_THROW_ON_ERROR);
        $resource = json_decode(file_get_contents(base_path('tests/Fixtures/CarePlan/activity.json')), true, flags: JSON_THROW_ON_ERROR);
        $resource['detail']['productReference']['identifier']['type']['coding'][0]['code'] = 'service_group';
        $resource['detail']['productReference']['displayValue'] = 'Synthetic group';
        $plan = Repository::carePlan()->storeResource($planResource, 1, 1);
        $repository = Repository::carePlanActivity();
        $activity = $repository->storeResource($resource, $plan);
        $projected = $activity;

        (new CarePlanWorkflowHarness())->saveActivityDraft([
            'quantity' => (string) $projected->quantity,
            'product_reference' => $projected->productReference,
            'program' => $projected->program,
            'goal' => $projected->goal,
            'reason_code' => $projected->reasonCode,
            'reason_reference' => $projected->reasonReference,
            'scheduled_period_start' => $projected->scheduledPeriodStart->format('Y-m-d'),
            'scheduled_period_end' => $projected->scheduledPeriodEnd->format('Y-m-d'),
        ], $plan, $activity->id);

        $actual = $repository->getResource($activity->id);
        $this->assertEquals($resource['detail']['productReference'], $actual['detail']['productReference']);
        $this->assertEquals($resource['detail']['reasonReference'], $actual['detail']['reasonReference']);
        $this->assertSame($resource['detail']['scheduledPeriod'], $actual['detail']['scheduledPeriod']);
        $this->assertSame($resource['detail']['reasonCode'], $actual['detail']['reasonCode']);
        $this->assertSame($resource['detail']['goal'], $actual['detail']['goal']);
        $this->assertEquals($resource['author'], $actual['author']);
        $this->assertNull(DB::table('care_plan_activities')->where('id', $activity->id)->value('quantity'));
    }

    public function test_date_only_form_is_bounded_by_the_exact_care_plan_instants(): void
    {
        config(['app.timezone' => 'Europe/Kyiv']);
        $resource = json_decode(file_get_contents(base_path('tests/Fixtures/CarePlan/plan.json')), true, flags: JSON_THROW_ON_ERROR);
        $resource['period'] = ['start' => '2026-10-19T10:30:12Z', 'end' => '2026-10-20T11:30:13Z'];
        $plan = Repository::carePlan()->storeResource($resource, 1, 1);
        $repository = Repository::carePlanActivity();
        $activity = (new CarePlanWorkflowHarness())->saveActivityDraft(['kind' => 'service_request', 'status' => 'draft', 'scheduled_period_start' => '2026-10-19', 'scheduled_period_end' => '2026-10-20'], $plan);

        $this->assertSame($resource['period'], $repository->getResource($activity->id)['detail']['scheduledPeriod']);
        $this->assertArrayNotHasKey('remainingQuantity', $repository->getResource($activity->id)['detail']);
    }

    public function test_activity_identity_cannot_be_updated_through_another_plan(): void
    {
        $resource = json_decode(file_get_contents(base_path('tests/Fixtures/CarePlan/plan.json')), true, flags: JSON_THROW_ON_ERROR);
        $plans = Repository::carePlan();
        $plan = $plans->storeResource($resource, 1, 1);
        $activityResource = json_decode(file_get_contents(base_path('tests/Fixtures/CarePlan/activity.json')), true, flags: JSON_THROW_ON_ERROR);
        $repository = Repository::carePlanActivity();
        $activity = $repository->storeResource($activityResource, $plan);
        $resource['id'] = '095945b0-9e8e-4bd1-9025-09d275c972ee';
        $otherPlan = $plans->storeResource($resource, 1, 1);
        $activityResource['carePlan']['identifier']['value'] = $otherPlan->uuid;

        $this->expectException(ModelNotFoundException::class);
        $repository->storeResource($activityResource, $otherPlan, $activity->id);
    }

    public function test_the_same_remote_plan_can_be_cached_separately_for_two_legal_entities(): void
    {
        DB::table('legal_entities')->insert(['id' => 2]);
        $resource = json_decode(file_get_contents(base_path('tests/Fixtures/CarePlan/plan.json')), true, flags: JSON_THROW_ON_ERROR);
        $repository = Repository::carePlan();
        $first = $repository->storeResource($resource, 1, 1);
        $second = $repository->storeResource($resource, 1, 2);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($resource['id'], $repository->getResource($second->id)['id']);
        $this->assertSame(2, DB::table('care_plans')->count());
    }

    public function test_an_unsupported_schedule_survives_a_description_edit_with_empty_date_controls(): void
    {
        $planResource = json_decode(file_get_contents(base_path('tests/Fixtures/CarePlan/plan.json')), true, flags: JSON_THROW_ON_ERROR);
        $resource = json_decode(file_get_contents(base_path('tests/Fixtures/CarePlan/activity.json')), true, flags: JSON_THROW_ON_ERROR);
        unset($resource['detail']['scheduledPeriod']);
        $resource['detail']['scheduledTiming'] = ['repeat' => ['frequency' => 2, 'period' => 1, 'periodUnit' => 'd']];
        $resource['detail']['status'] = 'draft';
        $plan = Repository::carePlan()->storeResource($planResource, 1, 1);
        $repository = Repository::carePlanActivity();
        $activity = $repository->storeResource($resource, $plan);

        (new CarePlanWorkflowHarness())->saveActivityDraft(['description' => 'Edited', 'scheduled_period_start' => '', 'scheduled_period_end' => ''], $plan, $activity->id);

        $actual = $repository->getResource($activity->id);
        $this->assertSame($resource['detail']['scheduledTiming'], $actual['detail']['scheduledTiming']);
        $this->assertArrayNotHasKey('scheduledPeriod', $actual['detail']);
        $this->assertSame('Edited', $actual['detail']['description']);
    }

    public function test_backfill_keeps_a_legacy_draft_encounter_link(): void
    {
        $encounterUuid = 'fe2fa6df-7204-40ea-86f7-26b4ed3e51e1';
        DB::table('encounters')->insert(['id' => 1, 'uuid' => $encounterUuid]);
        DB::table('employees')->insert(['id' => 1, 'uuid' => '47ee824a-eb47-48ef-8d30-1109e3c89f0c', 'legal_entity_id' => 1]);
        DB::table('care_plans')->insert(['id' => 10, 'person_id' => 1, 'legal_entity_id' => 1, 'author_id' => 1, 'encounter_id' => 1, 'title' => 'Draft', 'status' => 'draft', 'period_start' => '2026-10-19']);
        $repository = Repository::carePlan();

        $report = (new BackfillCarePlanFhir())->backfillResources(1, true);

        $this->assertSame([], $report['errors']);
        $this->assertSame($encounterUuid, data_get($repository->getResource(10), 'encounter.identifier.value'));
        $this->assertSame(1, DB::table('care_plans')->where('id', 10)->value('encounter_id'));
    }

    public function test_preflight_reads_codings_without_optional_metadata_columns(): void
    {
        $concept = MedicalEventsRepository::codeableConcept()->store(['coding' => [['system' => 'synthetic/category', 'code' => 'TEST']]]);
        DB::table('employees')->insert(['id' => 1, 'uuid' => '47ee824a-eb47-48ef-8d30-1109e3c89f0c', 'legal_entity_id' => 1]);
        DB::table('care_plans')->insert(['id' => 10, 'person_id' => 1, 'legal_entity_id' => 1, 'author_id' => 1, 'category_id' => $concept->id, 'title' => 'Legacy draft', 'status' => 'draft', 'period_start' => '2026-10-19']);
        foreach (['display', 'version', 'user_selected'] as $column) {
            $this->assertFalse(Schema::hasColumn('codings', $column));
        }
        Model::preventAccessingMissingAttributes();
        try {
            $report = (new BackfillCarePlanFhir())->backfillResources(1);
            $this->assertSame([], $report['errors']);
            $this->assertNull(DB::table('care_plans')->where('id', 10)->value('details_synced_at'));
        } finally {
            Model::preventAccessingMissingAttributes(false);
        }
    }

    public function test_over_precision_quantity_is_rejected_without_partial_persistence(): void
    {
        $planResource = json_decode(file_get_contents(base_path('tests/Fixtures/CarePlan/plan.json')), true, flags: JSON_THROW_ON_ERROR);
        $resource = json_decode(file_get_contents(base_path('tests/Fixtures/CarePlan/activity.json')), true, flags: JSON_THROW_ON_ERROR);
        $plan = Repository::carePlan()->storeResource($planResource, 1, 1);
        $counts = $this->primitiveCounts();
        $resource['detail']['quantity']['value'] = '1.12345678901';
        try {
            Repository::carePlanActivity()->storeResource($resource, $plan);
            $this->fail('An over-precision quantity must not be rounded by SQL.');
        } catch (InvalidArgumentException) {
            $this->assertSame($counts, $this->primitiveCounts());
            $this->assertSame(0, DB::table('care_plan_activities')->count());
        }
    }

    private function primitiveCounts(): array
    {
        $counts = [];
        foreach (['identifiers', 'codeable_concepts', 'codings', 'periods', 'quantities'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    public function test_preflight_reports_registered_legacy_records_without_changing_them(): void
    {
        DB::table('care_plans')->insert(['id' => 10, 'person_id' => 1, 'legal_entity_id' => 1, 'uuid' => '8d08ce22-82dd-4ac4-bdb9-432fd5aad5dd', 'title' => 'Legacy', 'status' => 'active', 'period_start' => '2026-10-19']);
        $before = DB::table('care_plans')->find(10);

        $report = (new BackfillCarePlanFhir())->backfillResources(1, true);

        $this->assertSame([['type' => 'carePlan', 'localId' => 10]], $report['needsRemoteSync']);
        $this->assertSame(0, $report['converted']);
        $this->assertEquals($before, DB::table('care_plans')->find(10));
    }

    public function test_draft_preflight_is_read_only_and_backfill_is_repeatable(): void
    {
        DB::table('employees')->insert(['id' => 1, 'uuid' => '47ee824a-eb47-48ef-8d30-1109e3c89f0c', 'legal_entity_id' => 1]);
        DB::table('care_plans')->insert(['id' => 10, 'person_id' => 1, 'legal_entity_id' => 1, 'author_id' => 1, 'title' => 'Draft', 'status' => 'draft', 'period_start' => '2026-10-19', 'category' => 'TEST', 'terms_of_service' => 'OUTPATIENT']);
        $repository = Repository::carePlan();

        $preview = (new BackfillCarePlanFhir())->backfillResources(1);

        $this->assertSame([], $preview['errors']);
        $this->assertNull(DB::table('care_plans')->where('id', 10)->value('details_synced_at'));
        $this->assertNull(DB::table('care_plans')->where('id', 10)->value('uuid'));
        $written = (new BackfillCarePlanFhir())->backfillResources(1, true);
        $resource = $repository->getResource(10);
        $counts = $this->primitiveCounts();
        $again = (new BackfillCarePlanFhir())->backfillResources(1, true);

        $this->assertSame(1, $written['converted']);
        $this->assertSame(0, $again['converted']);
        $this->assertSame($resource, $repository->getResource(10));
        $this->assertSame($counts, $this->primitiveCounts());
    }

    public function test_editing_second_goal_and_reason_preserves_first_concept_and_honors_removal(): void
    {
        $planResource = json_decode(file_get_contents(base_path('tests/Fixtures/CarePlan/plan.json')), true, flags: JSON_THROW_ON_ERROR);
        $resource = json_decode(file_get_contents(base_path('tests/Fixtures/CarePlan/activity.json')), true, flags: JSON_THROW_ON_ERROR);
        $resource['detail']['goal'][0]['text'] = 'First full goal';
        $resource['detail']['goal'][0]['coding'][] = ['system' => 'synthetic/other', 'code' => 'OTHER'];
        $resource['detail']['reasonCode'][] = ['coding' => [['system' => 'eHealth/ICD10_AM/condition_codes', 'code' => 'B00']]];
        $plan = Repository::carePlan()->storeResource($planResource, 1, 1);
        $repo = Repository::carePlanActivity();
        $activity = $repo->storeResource($resource, $plan);
        (new CarePlanWorkflowHarness())->saveActivityDraft(['goal' => ['SYNTHETIC_A', 'SYNTHETIC_C'], 'reasonCode' => ['A00', 'C00']], $plan, $activity->id);
        $actual = $repo->getResource($activity->id)['detail'];
        $this->assertSame($resource['detail']['goal'][0], $actual['goal'][0]);
        $this->assertSame('SYNTHETIC_C', $actual['goal'][1]['coding'][0]['code']);
        $this->assertSame($resource['detail']['reasonCode'][0], $actual['reasonCode'][0]);
        $this->assertSame('C00', $actual['reasonCode'][1]['coding'][0]['code']);
        (new CarePlanWorkflowHarness())->saveActivityDraft(['goal' => ['SYNTHETIC_A'], 'reasonCode' => []], $plan, $activity->id);
        $actual = $repo->getResource($activity->id)['detail'];
        $this->assertSame([$resource['detail']['goal'][0]], $actual['goal']);
        $this->assertSame([], $actual['reasonCode']);
    }

    public function test_unchanged_scalar_status_reason_does_not_block_a_description_edit(): void
    {
        $planResource = json_decode(file_get_contents(base_path('tests/Fixtures/CarePlan/plan.json')), true, flags: JSON_THROW_ON_ERROR);
        $resource = json_decode(file_get_contents(base_path('tests/Fixtures/CarePlan/activity.json')), true, flags: JSON_THROW_ON_ERROR);
        $resource['detail']['statusReason'] = ['coding' => [['system' => 'synthetic/reasons', 'code' => 'TEST']], 'text' => 'Full reason'];
        $plan = Repository::carePlan()->storeResource($planResource, 1, 1);
        $repo = Repository::carePlanActivity();
        $activity = $repo->storeResource($resource, $plan);
        (new CarePlanWorkflowHarness())->saveActivityDraft(['description' => 'Updated', 'statusReason' => 'TEST'], $plan, $activity->id);
        $this->assertSame($resource['detail']['statusReason'], $repo->getResource($activity->id)['detail']['statusReason']);
    }
    public function test_empty_kind_resolves_the_saved_concept_without_prior_eager_loading(): void
    {
        $plan = Repository::carePlan()->storeResource(json_decode(file_get_contents(base_path('tests/Fixtures/CarePlan/plan.json')), true), 1, 1);
        $resource = json_decode(file_get_contents(base_path('tests/Fixtures/CarePlan/activity.json')), true);
        $activity = Repository::carePlanActivity()->storeResource($resource, $plan);
        $concept = MedicalEventsRepository::codeableConcept()->store(['coding' => [['system' => 'eHealth/care_plan_activity_kind', 'code' => 'ServiceRequest']]]);
        $activity->forceFill(['kind' => '', 'kindId' => $concept->id])->save();
        $fresh = $activity->fresh();
        $this->assertFalse($fresh->relationLoaded('kindConcept'));
        $fresh->preventsLazyLoading = true;
        $this->assertSame('service_request', $fresh->resolvedKind());
        $this->assertSame('service_request', $fresh->resolvedKind());
    }

}
