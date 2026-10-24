<?php

declare(strict_types=1);

namespace Tests\Feature\Encounter;

use App\Enums\Person\EncounterStatus;
use App\Livewire\EncounterPackage\EncounterEPrescriptionCreate;
use App\Livewire\EncounterPackage\EncounterReferralCreate;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Coding;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\Person\Person;
use App\Policies\EncounterPolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Composition\CompositionTestFixtures;
use Tests\Feature\Composition\UsesCompositionDictionaries;
use Tests\TestCase;

class EncounterDocumentPagesTest extends TestCase
{
    use CompositionTestFixtures;
    use DatabaseTransactions;
    use UsesCompositionDictionaries;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pagination.per_page' => 10]);
        Http::preventStrayRequests();
        $this->withoutVite();
        // These tests exercise routing/patient scope, not token rotation or session binding.
        $this->withoutMiddleware([
            \App\Http\Middleware\EnsureSingleSession::class,
            \App\Classes\eHealth\Middleware\CheckSessionToken::class,
        ]);
        $this->fakeSignatureService();
        $key = \App\Services\Dictionary\Dictionaries\BasicDictionary::KEY;
        $dictionaries = \Illuminate\Support\Facades\Cache::get($key, []);
        $dictionaries[] = ['name' => 'SERVICE_CATEGORY', 'values' => [
            ['code' => 'counselling', 'description' => 'Консультування', 'is_active' => true],
        ]];
        \Illuminate\Support\Facades\Cache::forever($key, $dictionaries);
        $this->mock(EncounterPolicy::class)->shouldReceive('view')->andReturn(Response::allow());
    }

    #[DataProvider('documentPages')]
    public function test_forms_open_as_pages_with_a_link_back_to_their_encounter(string $routeName, string $component): void
    {
        ['legalEntity' => $legalEntity, 'person' => $person] = $this->fixture();
        $encounter = $this->encounter($person);
        $this->fakeEHealth(['*' => Http::response($this->eHealthBody([]))]);

        $this->get(route($routeName, [$legalEntity, $person, $encounter]))
            ->assertOk()
            ->assertSeeLivewire($component)
            ->assertSee(route('encounter.view', [$legalEntity, $person, $encounter]), false)
            ->assertDontSee("entangle('showEncounterReferralDrawer')", false)
            ->assertDontSee("entangle('showEncounterEPrescriptionDrawer')", false);
    }

    #[DataProvider('allDocumentRoutes')]
    public function test_an_encounter_of_another_patient_is_rejected_before_remote_calls(string $routeName): void
    {
        ['legalEntity' => $legalEntity, 'person' => $person] = $this->fixture();
        $other = Person::create(['uuid' => (string) Str::uuid(), 'birth_date' => '1990-01-01', 'gender' => 'MALE']);
        $encounter = $this->encounter($other);

        $this->get(route($routeName, [$legalEntity, $person, $encounter]))->assertNotFound();
        Http::assertNothingSent();
    }

    #[DataProvider('documentPages')]
    public function test_an_unsigned_draft_encounter_cannot_issue_a_standalone_document(string $routeName, string $component): void
    {
        ['legalEntity' => $legalEntity, 'person' => $person] = $this->fixture();
        $encounter = $this->encounter($person, EncounterStatus::DRAFT);

        $this->get(route($routeName, [$legalEntity, $person, $encounter]))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_legacy_care_plan_url_redirects_to_the_patient_scoped_route(): void
    {
        ['legalEntity' => $legalEntity, 'person' => $person] = $this->fixture();
        $encounter = $this->encounter($person);

        $this->get('/dashboard/'.$legalEntity->id.'/encounters/'.$encounter->id.'/care-plan/create')
            ->assertRedirect(route('care-plans.create-by-encounter', [$legalEntity, $person, $encounter]));
        Http::assertNothingSent();
    }

    public static function documentPages(): array
    {
        return [
            'referral' => ['encounter.referral.create', EncounterReferralCreate::class],
            'eprescription' => ['encounter.eprescription.create', EncounterEPrescriptionCreate::class],
        ];
    }

    public static function allDocumentRoutes(): array
    {
        return [
            'referral' => ['encounter.referral.create'],
            'eprescription' => ['encounter.eprescription.create'],
            'care-plan' => ['care-plans.create-by-encounter'],
        ];
    }

    private function fixture(): array
    {
        $fixture = $this->compositionFixture(scopes: ['encounter:read', 'service_request:write', 'medication_request_request:write', 'care_plan:write']);
        $fixture['user']->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($fixture['user'], 'ehealth');
        $this->withSession(['ehealth_legal_entity_uuid' => $fixture['legalEntity']->uuid]);

        return $fixture;
    }

    private function encounter(Person $person, EncounterStatus $status = EncounterStatus::FINISHED): Encounter
    {
        return Encounter::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $person->id, 'status' => $status->value,
            'episode_id' => Identifier::create(['value' => (string) Str::uuid()])->id,
            'class_id' => Coding::create(['code' => 'AMB', 'system' => 'eHealth/encounter_classes'])->id,
            'type_id' => CodeableConcept::create()->id, 'ehealth_inserted_at' => now(),
        ]);
    }
}
