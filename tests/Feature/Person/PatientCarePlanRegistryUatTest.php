<?php

declare(strict_types=1);

namespace Tests\Feature\Person;

use App\Classes\eHealth\Api\CarePlan as CarePlanApi;
use App\Classes\eHealth\EHealthResponse;
use App\Enums\User\Role;
use App\Jobs\CarePlanFullSync;
use App\Livewire\CarePlan\CarePlanIndex;
use App\Livewire\Person\Records\PatientCarePlans;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\LegalEntity;
use App\Models\Person\Person;
use App\Repositories\Repository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Composition\CompositionTestFixtures;
use Tests\Feature\Composition\UsesCompositionDictionaries;
use Tests\TestCase;

class PatientCarePlanRegistryUatTest extends TestCase
{
    use CompositionTestFixtures;
    use DatabaseTransactions {
        beginDatabaseTransaction as beginIsolatedTransaction;
    }
    use UsesCompositionDictionaries;

    public function beginDatabaseTransaction(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('testing', DB::connection()->getDatabaseName());
        $this->beginIsolatedTransaction();
    }

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('uk');
        Http::preventStrayRequests();
    }

    #[DataProvider('clinicContexts')]
    public function test_legacy_care_plans_render_in_both_registries_without_disabling_clinical_guards(string $entityType, Role $role): void
    {
        ['person' => $person, 'legalEntity' => $legalEntity] = $this->compositionFixture(entityType: $entityType, role: $role, scopes: ['care_plan:read', 'care_plan:write']);
        $legacy = CarePlan::forceCreate([
            'personId' => $person->id, 'legalEntityId' => $legalEntity->id,
            'uuid' => (string) Str::uuid(), 'title' => 'Legacy registered plan', 'status' => 'active',
        ]);
        $draft = CarePlan::forceCreate([
            'personId' => $person->id, 'legalEntityId' => $legalEntity->id,
            'title' => 'Legacy local draft', 'status' => 'draft',
        ]);
        $resource = json_decode(file_get_contents(base_path('tests/Fixtures/CarePlan/plan.json')), true, flags: JSON_THROW_ON_ERROR);
        $resource['title'] = 'Canonical plan';
        $repository = Repository::carePlan();
        $canonical = $repository->storeResource($resource, $person->id, $legalEntity->id);
        $ids = [$legacy->id, $draft->id, $canonical->id];

        $this->assertEqualsCanonicalizing($ids, $repository->getByPersonId($person->id, legalEntityId: $legalEntity->id)->modelKeys());
        $this->assertEqualsCanonicalizing($ids, $repository->listing($legalEntity->id, [])->modelKeys());
        $this->assertEqualsCanonicalizing($ids, $repository->getByLegalEntity($legalEntity->id)->modelKeys());

        foreach ([PatientCarePlans::class, CarePlanIndex::class] as $componentClass) {
            $component = Livewire::test($componentClass, ['legalEntity' => $legalEntity, 'personId' => $person->id])
                ->assertOk()->assertDontSeeHtml('wire:key="care-plan-'.$legacy->id.'"')->assertSee('Legacy local draft')->assertSee('Canonical plan')
                ->set('filterCompleteness', 'all')->assertSeeHtml('wire:key="care-plan-'.$legacy->id.'"')->assertSee('Legacy registered plan')
                ->assertSee(__('care-plan.registry_incomplete'))->assertSee(__('care-plan.draft_backfill_required'))
                ->assertSee(route('care-plans.show', [$legalEntity, $legacy->id]), false)
                ->assertDontSee(route('care-plans.edit', [$legalEntity, $draft->id]))
                ->assertSee(route('care-plans.show', [$legalEntity, $canonical->id]), false);
            if ($componentClass === PatientCarePlans::class) {
                $component->set('filterName', 'Canonical')->call('loadCarePlans')->assertDontSeeHtml('wire:key="care-plan-'.$legacy->id.'"')
                    ->call('resetFilters')->assertDontSeeHtml('wire:key="care-plan-'.$legacy->id.'"')->assertSee('Legacy local draft');
            }
        }

        $this->assertNotSame(1, $legacy->fresh()->detailsSyncedAt);
        Http::assertNothingSent();
        $this->expectException(InvalidArgumentException::class);
        $repository->getResource($legacy->id);
    }

    public function test_canonical_plan_with_legacy_activity_offers_read_only_details(): void
    {
        ['person' => $person, 'legalEntity' => $legalEntity] = $this->compositionFixture(scopes: ['care_plan:read']);
        $repository = Repository::carePlan();
        $resource = json_decode(file_get_contents(base_path('tests/Fixtures/CarePlan/plan.json')), true, flags: JSON_THROW_ON_ERROR);
        $resource['title'] = 'Partially imported plan';
        $plan = $repository->storeResource($resource, $person->id, $legalEntity->id);
        CarePlanActivity::forceCreate(['carePlanId' => $plan->id, 'uuid' => (string) Str::uuid(), 'status' => 'scheduled', 'kind' => 'medication_request']);

        Livewire::test(PatientCarePlans::class, ['legalEntity' => $legalEntity, 'personId' => $person->id])
            ->assertOk()->assertDontSee('Partially imported plan')
            ->set('filterCompleteness', 'all')->assertSee('Partially imported plan')->assertSee(__('care-plan.registry_incomplete'))
            ->assertSee(route('care-plans.show', [$legalEntity, $plan->id]), false)
            ->set('showAdditionalParams', true)
            ->assertSee(__('care-plan.registry_incomplete'))
            ->assertSee(route('care-plans.show', [$legalEntity, $plan->id]), false);

        $this->assertTrue($repository->findById($plan->id)->requiresFhirImport());
        $activity = CarePlanActivity::whereCarePlanId($plan->id)->firstOrFail();
        $this->expectException(InvalidArgumentException::class);
        Repository::carePlanActivity()->getResource($activity->id);
    }

    public function test_full_import_unlocks_the_existing_legacy_plan_without_creating_a_duplicate(): void
    {
        ['person' => $person, 'legalEntity' => $legalEntity] = $this->compositionFixture(scopes: ['care_plan:read']);
        $resource = json_decode(file_get_contents(base_path('tests/Fixtures/CarePlan/plan.json')), true, flags: JSON_THROW_ON_ERROR);
        $legacy = CarePlan::forceCreate([
            'personId' => $person->id, 'legalEntityId' => $legalEntity->id,
            'uuid' => $resource['id'], 'title' => 'Legacy before import', 'status' => 'active',
        ]);
        $repository = Repository::carePlan();
        $imported = $repository->storeAggregate($resource, [], $person->id, $legalEntity->id);

        $this->assertSame($legacy->id, $imported->id);
        $this->assertSame(1, CarePlan::wherePersonId($person->id)->whereLegalEntityId($legalEntity->id)->count());
        $this->assertFalse($repository->findById($imported->id)->requiresFhirImport());

        Livewire::test(PatientCarePlans::class, ['legalEntity' => $legalEntity, 'personId' => $person->id])
            ->assertOk()->assertDontSee(__('care-plan.registry_incomplete'))
            ->assertSee(route('care-plans.show', [$legalEntity, $imported->id]), false);
    }

    public function test_sync_reads_first_page_queues_details_and_prevents_duplicates(): void
    {
        ['person' => $person, 'legalEntity' => $legalEntity] = $this->compositionFixture(scopes: ['care_plan:read']);
        Queue::fake();
        $this->mockSyncFirstPage($person);
        session()->put(config('ehealth.api.oauth.bearer_token'), 'synthetic-token');
        $component = Livewire::test(PatientCarePlans::class, ['legalEntity' => $legalEntity, 'personId' => $person->id])
            ->call('sync')->assertSee(__('care-plan.sync_first_page'))
            ->assertDontSee(__('care-plan.sync_success'))->assertNotDispatched('flashMessage');
        $batchId = $component->get('syncBatchId');
        $this->assertNotNull($batchId);
        $batch = Bus::findBatch($batchId);
        $this->assertSame($person->uuid, $batch->options['patient_uuid']);
        $this->assertSame($person->id, $batch->options['person_id']);
        $this->assertSame($legalEntity->id, DB::table('job_batches')->where('id', $batchId)->value('legal_entity_id'));
        $this->assertSame('synthetic-token', Crypt::decryptString($batch->options['token']));
        $component->call('sync')->assertSee(__('care-plan.sync_running'));
        Queue::assertPushed(CarePlanFullSync::class, 1);
        Http::assertNothingSent();

        DB::table('job_batches')->where('id', $batchId)->update(['pending_jobs' => 0, 'finished_at' => time()]);
        $component->call('checkSyncStatus')->assertSet('syncBatchId', null)
            ->assertSee(__('care-plan.sync_success'))->assertSee('alert-message fixed top-6 right-2', false)
            ->call('resetFilters')->assertDontSee(__('care-plan.sync_success'));
    }

    public function test_failed_sync_reports_an_error_without_success_or_clinic_status_changes(): void
    {
        ['person' => $person, 'legalEntity' => $legalEntity] = $this->compositionFixture(scopes: ['care_plan:read']);
        Queue::fake();
        $this->mockSyncFirstPage($person);
        session()->put(config('ehealth.api.oauth.bearer_token'), 'synthetic-token');
        $component = Livewire::test(PatientCarePlans::class, ['legalEntity' => $legalEntity, 'personId' => $person->id])->call('sync');
        DB::table('job_batches')->where('id', $component->get('syncBatchId'))->update(['failed_jobs' => 1, 'cancelled_at' => time()]);
        $component->call('checkSyncStatus')->assertSet('syncBatchId', null)
            ->assertSee(__('care-plan.sync_failed'))->assertDontSee(__('care-plan.sync_success'));
        $this->assertSame('COMPLETED', $legalEntity->fresh()->syncStatus);
        Http::assertNothingSent();
    }

    private function mockSyncFirstPage(Person $person): void
    {
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn([['id' => (string) Str::uuid(), 'status' => 'active', 'title' => 'Queued plan']]);
        $response->shouldReceive('isNotLast')->once()->andReturn(false);
        $api = Mockery::mock(CarePlanApi::class);
        $api->shouldReceive('withToken')->once()->andReturnSelf();
        $api->shouldReceive('getBySearchParams')->once()->with($person->uuid, ['page' => 1])->andReturn($response);
        $this->instance(CarePlanApi::class, $api);
    }

    public static function clinicContexts(): array
    {
        return [
            'PMD' => [LegalEntity::TYPE_PRIMARY_CARE, Role::DOCTOR],
            'SMD' => [LegalEntity::TYPE_OUTPATIENT, Role::SPECIALIST],
        ];
    }

}
