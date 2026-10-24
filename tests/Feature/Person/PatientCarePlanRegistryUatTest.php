<?php

declare(strict_types=1);

namespace Tests\Feature\Person;

use App\Enums\User\Role;
use App\Livewire\CarePlan\CarePlanIndex;
use App\Livewire\Person\Records\PatientCarePlans;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\LegalEntity;
use App\Repositories\CarePlanRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Livewire;
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
        $repository = app(CarePlanRepository::class);
        $canonical = $repository->storeResource($resource, $person->id, $legalEntity->id);
        $ids = [$legacy->id, $draft->id, $canonical->id];

        $this->assertEqualsCanonicalizing($ids, $repository->getByPersonId($person->id, legalEntityId: $legalEntity->id)->modelKeys());
        $this->assertEqualsCanonicalizing($ids, $repository->listing($legalEntity->id, [])->modelKeys());
        $this->assertEqualsCanonicalizing($ids, $repository->getByLegalEntity($legalEntity->id)->modelKeys());

        foreach ([PatientCarePlans::class, CarePlanIndex::class] as $componentClass) {
            $component = Livewire::test($componentClass, ['legalEntity' => $legalEntity, 'personId' => $person->id])
                ->assertOk()->assertSee('Legacy registered plan')->assertSee('Legacy local draft')->assertSee('Canonical plan')
                ->assertSee(__('care-plan.full_sync_required'))->assertSee(__('care-plan.draft_backfill_required'))
                ->assertDontSee(route('care-plans.show', [$legalEntity, $legacy->id]))
                ->assertDontSee(route('care-plans.edit', [$legalEntity, $draft->id]))
                ->assertSee(route('care-plans.show', [$legalEntity, $canonical->id]), false);
            if ($componentClass === PatientCarePlans::class) {
                $component->set('filterName', 'Canonical')->call('search')->assertDontSee('Legacy registered plan')
                    ->call('resetFilters')->assertSee('Legacy registered plan');
            }
        }

        $this->assertNotSame(1, $legacy->fresh()->fhirVersion);
        Http::assertNothingSent();
        $this->expectException(InvalidArgumentException::class);
        $repository->getResource($legacy->id);
    }

    public function test_canonical_plan_with_legacy_activity_does_not_break_listing_or_offer_details(): void
    {
        ['person' => $person, 'legalEntity' => $legalEntity] = $this->compositionFixture(scopes: ['care_plan:read']);
        $repository = app(CarePlanRepository::class);
        $resource = json_decode(file_get_contents(base_path('tests/Fixtures/CarePlan/plan.json')), true, flags: JSON_THROW_ON_ERROR);
        $resource['title'] = 'Partially imported plan';
        $plan = $repository->storeResource($resource, $person->id, $legalEntity->id);
        CarePlanActivity::forceCreate(['carePlanId' => $plan->id, 'uuid' => (string) Str::uuid(), 'status' => 'scheduled', 'kind' => 'medication_request']);

        Livewire::test(PatientCarePlans::class, ['legalEntity' => $legalEntity, 'personId' => $person->id])
            ->assertOk()->assertSee('Partially imported plan')->assertSee(__('care-plan.full_sync_required'))
            ->assertDontSee(route('care-plans.show', [$legalEntity, $plan->id]))
            ->set('showAdditionalParams', true)
            ->assertSee(__('care-plan.full_sync_required'))
            ->assertDontSee(route('care-plans.show', [$legalEntity, $plan->id]));

        $this->expectException(InvalidArgumentException::class);
        $repository->findById($plan->id);
    }

    public function test_full_import_unlocks_the_existing_legacy_plan_without_creating_a_duplicate(): void
    {
        ['person' => $person, 'legalEntity' => $legalEntity] = $this->compositionFixture(scopes: ['care_plan:read']);
        $resource = json_decode(file_get_contents(base_path('tests/Fixtures/CarePlan/plan.json')), true, flags: JSON_THROW_ON_ERROR);
        $legacy = CarePlan::forceCreate([
            'personId' => $person->id, 'legalEntityId' => $legalEntity->id,
            'uuid' => $resource['id'], 'title' => 'Legacy before import', 'status' => 'active',
        ]);
        $repository = app(CarePlanRepository::class);
        $imported = $repository->storeAggregate($resource, [], $person->id, $legalEntity->id);

        $this->assertSame($legacy->id, $imported->id);
        $this->assertSame(1, CarePlan::wherePersonId($person->id)->whereLegalEntityId($legalEntity->id)->count());
        $this->assertFalse($repository->findById($imported->id)->requiresFhirImport());

        Livewire::test(PatientCarePlans::class, ['legalEntity' => $legalEntity, 'personId' => $person->id])
            ->assertOk()->assertDontSee(__('care-plan.full_sync_required'))
            ->assertSee(route('care-plans.show', [$legalEntity, $imported->id]), false);
    }

    public static function clinicContexts(): array
    {
        return [
            'PMD' => [LegalEntity::TYPE_PRIMARY_CARE, Role::DOCTOR],
            'SMD' => [LegalEntity::TYPE_OUTPATIENT, Role::SPECIALIST],
        ];
    }

}
