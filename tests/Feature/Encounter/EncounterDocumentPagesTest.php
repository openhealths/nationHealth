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
use Livewire\Livewire;
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
        $this->partialMock(EncounterPolicy::class)->shouldReceive('view')->andReturn(Response::allow());
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

    public function test_repeated_referral_preparation_updates_the_same_draft_and_explicit_new_starts_empty(): void
    {
        $this->withoutExceptionHandling();
        ['legalEntity' => $legalEntity, 'person' => $person] = $this->fixture();
        $encounter = $this->encounter($person);
        $this->fakeEHealth(['*' => Http::response($this->eHealthBody([]))]);

        $component = Livewire::test(EncounterReferralCreate::class, compact('legalEntity', 'person', 'encounter') + ['preperson' => null])
            ->set('encounterReferralForm.service_id', (string) Str::uuid())
            ->set('encounterReferralForm.program_id', '')
            ->set('encounterReferralForm.note', 'First version')
            ->call('validateEncounterReferral')->assertHasNoErrors()->assertSet('showSignatureModal', true);
        $uuid = $component->get('encounterReferralRequestIdToSign');
        $draft = \App\Models\MedicalEvents\Sql\ServiceRequestRequest::whereUuid($uuid)->sole();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $component->set('showSignatureModal', false)
                ->set('encounterReferralForm.note', 'Updated '.$attempt)
                ->set('encounterReferralForm.quantity', $attempt + 1)
                ->call('validateEncounterReferral')->assertHasNoErrors()
                ->assertSet('encounterReferralRequestIdToSign', $uuid)
                ->assertSet('showSignatureModal', true);
        }
        $this->assertSame(1, \App\Models\MedicalEvents\Sql\ServiceRequestRequest::wherePersonId($person->id)->count());
        $this->assertSame($draft->id, $draft->fresh()->id);
        $resource = app(\App\Repositories\MedicalEvents\ServiceRequestRequestRepository::class)->getResource($draft->fresh());
        $this->assertSame('Updated 3', $resource['note']);
        $this->assertEquals(4, $resource['quantity']['value']);
        $payload = \App\Livewire\Referral\Forms\ServiceRequestForm::buildCreateContent($resource);
        $this->assertSame($uuid, $payload['id']);
        $this->assertSame('Updated 3', $payload['note']);

        $component->call('openEncounterReferralDrawer')
            ->assertSet('encounterReferralRequestIdToSign', null)
            ->assertSet('encounterReferralSubmission', [])
            ->assertSet('encounterReferralForm.service_id', '')
            ->assertSet('encounterReferralForm.note', '')
            ->assertSet('showSignatureModal', false)
            ->set('encounterReferralForm.service_id', (string) Str::uuid())
            ->set('encounterReferralForm.program_id', '')
            ->call('validateEncounterReferral')->assertHasNoErrors();
        $this->assertNotSame($uuid, $component->get('encounterReferralRequestIdToSign'));
        $this->assertSame(2, \App\Models\MedicalEvents\Sql\ServiceRequestRequest::wherePersonId($person->id)->count());
        $this->assertSame('Updated 3', $draft->fresh()->note);
        Http::assertNotSent(static fn ($request): bool => $request->method() === 'POST');
    }

    public function test_new_referral_page_does_not_hydrate_an_existing_draft_but_explicit_resume_does(): void
    {
        ['legalEntity' => $legalEntity, 'person' => $person, 'employee' => $employee] = $this->fixture();
        $encounter = $this->encounter($person);
        $lifecycle = app(\App\Services\MedicalEvents\ReferralRequestLifecycleService::class);
        $uuid = $lifecycle->createEncounterDraft($encounter, [
            'service_id' => (string) Str::uuid(), 'note' => 'Saved draft',
            'started_at' => now()->addHour()->toIso8601String(), 'ended_at' => now()->addMonth()->toIso8601String(),
        ], 2, $lifecycle->resolveEncounterEmployeeContext($encounter, $employee->id));
        $draft = \App\Models\MedicalEvents\Sql\ServiceRequestRequest::whereUuid($uuid)->sole();
        $this->fakeEHealth(['*' => Http::response($this->eHealthBody([]))]);

        $repository = $this->partialMock(\App\Repositories\MedicalEvents\ServiceRequestRequestRepository::class);
        $repository->shouldNotReceive('getResource');
        Livewire::test(EncounterReferralCreate::class, compact('legalEntity', 'person', 'encounter') + ['preperson' => null])
            ->assertSet('encounterReferralRequestIdToSign', null)
            ->assertSet('encounterReferralForm.service_id', '')
            ->assertSet('encounterReferralForm.note', '');

        $this->app->forgetInstance(\App\Repositories\MedicalEvents\ServiceRequestRequestRepository::class);
        Livewire::withQueryParams(['submission' => (string) $draft->id])
            ->test(EncounterReferralCreate::class, compact('legalEntity', 'person', 'encounter') + ['preperson' => null])
            ->assertSet('encounterReferralRequestIdToSign', $uuid)
            ->assertSet('encounterReferralForm.note', 'Saved draft')
            ->assertSet('encounterReferralForm.quantity', 2.0)
            ->set('encounterReferralForm.program_id', '')
            ->set('encounterReferralForm.note', 'Resumed draft')
            ->call('validateEncounterReferral')->assertHasNoErrors()
            ->assertSet('encounterReferralRequestIdToSign', $uuid);
        $this->assertSame(1, \App\Models\MedicalEvents\Sql\ServiceRequestRequest::wherePersonId($person->id)->count());
        $this->assertSame('Resumed draft', $draft->fresh()->note);
    }

    #[DataProvider('uneditableReferralStates')]
    public function test_reusing_a_submitted_referral_cannot_overwrite_or_duplicate_it(string $state): void
    {
        ['person' => $person, 'employee' => $employee] = $this->fixture();
        $encounter = $this->encounter($person);
        $lifecycle = app(\App\Services\MedicalEvents\ReferralRequestLifecycleService::class);
        $context = $lifecycle->resolveEncounterEmployeeContext($encounter, $employee->id);
        $form = ['service_id' => (string) Str::uuid(), 'note' => 'Original'];
        $uuid = $lifecycle->createEncounterDraft($encounter, $form, 1, $context);
        $draft = \App\Models\MedicalEvents\Sql\ServiceRequestRequest::whereUuid($uuid)->sole();
        if ($state === 'pending') {
            app(\App\Repositories\MedicalEvents\ServiceRequestRequestRepository::class)->beginSubmission($draft, ['status' => 'active']);
        } else {
            $draft->update(['status' => $state]);
        }
        try {
            $lifecycle->createEncounterDraft($encounter, array_replace($form, ['note' => 'Overwrite', 'program_id' => (string) Str::uuid()]), 2, $context, $uuid);
            $this->fail('A submitted referral must not be saved as a new draft.');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->assertSame('Original', $draft->fresh()->note);
            $this->assertSame(1, \App\Models\MedicalEvents\Sql\ServiceRequestRequest::wherePersonId($person->id)->count());
        }
        Http::assertNothingSent();
    }

    public static function uneditableReferralStates(): array
    {
        return ['pending' => ['pending'], 'active' => ['active'], 'cancelled' => ['cancelled']];
    }

    public function test_a_draft_that_becomes_active_during_prequalify_is_not_overwritten(): void
    {
        ['person' => $person, 'employee' => $employee] = $this->fixture();
        $encounter = $this->encounter($person);
        $lifecycle = app(\App\Services\MedicalEvents\ReferralRequestLifecycleService::class);
        $context = $lifecycle->resolveEncounterEmployeeContext($encounter, $employee->id);
        $form = ['service_id' => (string) Str::uuid(), 'note' => 'Original'];
        $uuid = $lifecycle->createEncounterDraft($encounter, $form, 1, $context);
        $draft = \App\Models\MedicalEvents\Sql\ServiceRequestRequest::whereUuid($uuid)->sole();
        $response = \Mockery::mock(\App\Classes\eHealth\EHealthResponse::class);
        $response->shouldReceive('getData')->andReturn(['status' => 'VALID']);
        $this->mock(\App\Classes\eHealth\Api\Patient\ServiceRequest::class)
            ->shouldReceive('prequalify')->once()->andReturnUsing(function () use ($draft, $response) {
                $draft->update(['status' => 'active']);

                return $response;
            });

        try {
            $lifecycle->createEncounterDraft($encounter, array_replace($form, ['note' => 'Overwrite', 'program_id' => (string) Str::uuid()]), 2, $context, $uuid);
            $this->fail('The draft state must be checked again when the row is locked for saving.');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->assertSame('active', $draft->fresh()->status);
            $this->assertSame('Original', $draft->fresh()->note);
        }
    }

    public function test_reusing_a_draft_from_another_encounter_or_patient_is_rejected(): void
    {
        ['person' => $person, 'employee' => $employee] = $this->fixture();
        $encounter = $this->encounter($person);
        $lifecycle = app(\App\Services\MedicalEvents\ReferralRequestLifecycleService::class);
        $context = $lifecycle->resolveEncounterEmployeeContext($encounter, $employee->id);
        $form = ['service_id' => (string) Str::uuid(), 'note' => 'Original'];
        $uuid = $lifecycle->createEncounterDraft($encounter, $form, 1, $context);
        $otherPatient = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']);
        foreach ([$this->encounter($person), $this->encounter($otherPatient)] as $otherEncounter) {
            try {
                $lifecycle->createEncounterDraft($otherEncounter, $form, 1, $context, $uuid);
                $this->fail('The selected draft must belong to this encounter and patient.');
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                $this->assertSame(1, \App\Models\MedicalEvents\Sql\ServiceRequestRequest::count());
            }
        }
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

    public function test_document_policy_rejects_missing_write_scope_and_foreign_legal_entity(): void
    {
        ['legalEntity' => $legalEntity, 'person' => $person, 'user' => $user] = $this->compositionFixture(scopes: ['encounter:read']);
        $encounter = $this->encounter($person);
        $policy = app(EncounterPolicy::class);
        $this->assertFalse($policy->createMedicationRequest($user, $encounter, $person, $legalEntity->id)->allowed());
        $this->assertFalse($policy->createServiceRequest($user, $encounter, $person, $legalEntity->id)->allowed());
        $user->givePermissionToParent(\App\Models\Permission::findOrCreate('service_request:write', 'web'));
        $this->assertFalse($policy->createServiceRequest($user, $encounter, $person, $legalEntity->id + 1)->allowed());
    }
}
