<?php

declare(strict_types=1);

namespace Tests\Feature\Encounter;

use App\Classes\eHealth\Api\Job;
use App\Classes\eHealth\Api\Patient\ServiceRequest as ServiceRequestApi;
use App\Classes\eHealth\EHealthResponse;
use App\Classes\eHealth\Middleware\CheckSessionToken;
use App\Enums\JobStatus;
use App\Enums\Person\EncounterStatus;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Http\Middleware\EnsureSingleSession;
use App\Livewire\Components\XMessage;
use App\Livewire\EncounterPackage\EncounterEPrescriptionCreate;
use App\Livewire\EncounterPackage\EncounterReferralCreate;
use App\Livewire\Referral\Forms\ServiceRequestForm;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Coding;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\Permission;
use App\Models\Person\Person;
use App\Policies\EncounterPolicy;
use App\Repositories\MedicalEvents\Repository;
use App\Repositories\MedicalEvents\ServiceRequestRequestRepository;
use App\Services\Dictionary\Dictionaries\BasicDictionary;
use App\Services\Dictionary\Dictionaries\MedicalProgramDictionary;
use App\Services\Dictionary\Dictionaries\ServiceDictionary;
use App\Services\MedicalEvents\Mappers\ServiceRequestMapper;
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use App\Services\SignatureService;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Response as HttpResponse;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Composition\CompositionTestFixtures;
use Tests\Feature\Composition\UsesCompositionDictionaries;
use Tests\Support\GrantsReferralEmployeeRole;
use Tests\Support\NativeClinicalFixtures;
use Tests\TestCase;

class EncounterDocumentPagesTest extends TestCase
{
    use CompositionTestFixtures {
        fakeEHealth as private fakeBaseEHealth;
    }
    use DatabaseTransactions;
    use GrantsReferralEmployeeRole;
    use UsesCompositionDictionaries;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pagination.per_page' => 10]);
        Http::preventStrayRequests();
        $this->withoutVite();
        // These tests exercise routing/patient scope, not token rotation or session binding.
        $this->withoutMiddleware([
            EnsureSingleSession::class,
            CheckSessionToken::class,
        ]);
        $this->fakeSignatureService();
        foreach ([MedicalProgramDictionary::KEY, ServiceDictionary::KEY] as $dictionaryKey) {
            Cache::forever($dictionaryKey, []);
            Cache::forever($dictionaryKey.':fresh', true);
        }
        $key = BasicDictionary::KEY;
        $dictionaries = Cache::get($key, []);
        $dictionaries[] = ['name' => 'SERVICE_CATEGORY', 'values' => [
            ['code' => 'counselling', 'description' => 'Консультування', 'is_active' => true],
        ]];
        $dictionaries[] = ['name' => 'SERVICE_REQUEST_PRIORITY', 'values' => [
            ['code' => 'routine', 'description' => 'Планове', 'is_active' => true],
            ['code' => 'urgent', 'description' => 'Ургентне', 'is_active' => true],
            ['code' => 'retired', 'description' => 'Неактивний пріоритет', 'is_active' => false],
        ]];
        Cache::forever($key, $dictionaries);
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
            ->assertSee(route('persons.encounters.package.show', [$legalEntity, $person, $encounter]), false)
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
            ->assertRedirect(route('persons.encounters.care-plans.create', [$legalEntity, $person, $encounter]));
        Http::assertNothingSent();
    }

    public function test_correcting_times_after_validation_and_prequalification_errors_keeps_the_service_and_same_editable_draft(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 5)->setTime(14, 7, 35));
        ['legalEntity' => $legalEntity, 'person' => $person] = $this->fixture();
        $encounter = $this->encounter($person);
        $parameters = compact('legalEntity', 'person', 'encounter') + ['preperson' => null];
        $service = [
            'id' => (string) Str::uuid(), 'code' => 'TEST-CONSULT', 'name' => 'Консультація для перевірки чернетки',
            'category' => 'counselling', 'type' => 'service', 'is_composition' => false, 'is_active' => true, 'request_allowed' => true,
            'inserted_at' => '2026-01-01T09:00:00Z', 'updated_at' => '2026-01-01T09:00:00Z',
            'inserted_by' => NativeClinicalFixtures::UUID, 'updated_by' => NativeClinicalFixtures::UUID,
        ];
        Cache::forever(ServiceDictionary::KEY, [$service]);
        Cache::forever(ServiceDictionary::KEY.':fresh', true);
        $this->fakeEHealth(['*' => Http::response($this->eHealthBody([]))]);
        $rejection = new EHealthValidationException(['error' => ['message' => 'Rejected by medical program']]);
        $programId = (string) Str::uuid();
        $response = $this->qualificationResponse($programId);
        $attempt = 0;
        $prequalificationPayloads = [];
        $api = $this->mock(ServiceRequestApi::class);
        $api->shouldReceive('prequalify')->andReturnUsing(function (string $personUuid, array $payload) use (&$attempt, &$prequalificationPayloads, $rejection, $response) {
            $prequalificationPayloads[] = $payload;
            if (++$attempt === 1) {
                throw $rejection;
            }

            return $response;
        });
        $component = Livewire::test(EncounterReferralCreate::class, $parameters)
            ->call('selectEncounterReferralServiceFromCatalog', $service)
            ->set('encounterReferralForm.program_id', $programId)
            ->set('encounterReferralForm.started_at', '05.11.2026')
            ->set('encounterReferralForm.ended_at', '05.11.2026')
            ->set('encounterReferralForm.start_time', '10:10')
            ->set('encounterReferralForm.end_time', '11:11')
            ->call('validateEncounterReferral')->assertHasErrors('encounterReferralForm.start_time')
            ->assertSet('encounterReferralForm.service_id', $service['id'])
            ->assertSet('encounterReferralSelectedService.name', $service['name'])
            ->assertSet('showSignatureModal', false)->assertNoRedirect();
        $this->assertSame(0, ServiceRequestRequest::wherePersonId($person->id)->count());

        $component->set('encounterReferralForm.start_time', '15:30')
            ->set('encounterReferralForm.end_time', '16:30')
            ->call('validateEncounterReferral')->assertHasNoErrors()->assertSet('showSignatureModal', false);
        $this->assertSame(1, ServiceRequestRequest::wherePersonId($person->id)->count());
        $record = ServiceRequestRequest::wherePersonId($person->id)->sole();
        $editUrl = route('persons.encounters.referrals.edit', [$legalEntity, $person, $encounter, $record->id]);
        $component->assertRedirect($editUrl);
        $api->shouldNotHaveReceived('prequalify');
        $this->assertSame('draft', $record->status);
        $this->assertNull($record->requestNumber);
        $this->assertSame($service['id'], $record->serviceId);
        $this->assertSame('15:30', $record->startedAt->format('H:i'));
        $this->assertSame('16:30', $record->endedAt->format('H:i'));

        $edit = Livewire::test(EncounterReferralCreate::class, $parameters + ['referral' => $record->id])
            ->assertSet('prepareReferralForSigning', true)
            ->assertSet('showSignatureModal', false)
            ->assertSeeHtml('wire:init="prepareSavedReferralForSigning"')
            ->call('prepareSavedReferralForSigning')->assertHasNoErrors()->assertNoRedirect()
            ->assertSet('prepareReferralForSigning', false)
            ->assertSet('showSignatureModal', false)
            ->assertSee($rejection->getFormattedMessage())
            ->assertDontSee(__('referrals.draft_found_for_signing'))
            ->assertSet('encounterReferralForm.service_id', $service['id'])
            ->assertSet('encounterReferralSelectedService.code', $service['code'])
            ->assertSet('encounterReferralSelectedService.name', $service['name'])
            ->assertSet('encounterReferralForm.start_time', '15:30')
            ->assertSet('encounterReferralForm.end_time', '16:30');
        $edit->call('prepareSavedReferralForSigning')->assertSet('showSignatureModal', false);
        $this->assertSame(1, $attempt);
        $edit->set('encounterReferralForm.start_time', '15:45')
            ->set('encounterReferralForm.end_time', '16:45')
            ->call('validateEncounterReferral')->assertHasNoErrors()->assertSet('showSignatureModal', true)
            ->assertSet('encounterReferralRequestIdToSign', $record->uuid)
            ->assertSet('encounterReferralSelectedService.name', $service['name']);
        $api->shouldHaveReceived('prequalify')->twice();
        foreach ($prequalificationPayloads as $payload) {
            $this->assertSame($service['id'], data_get($payload, 'service_request.code.identifier.value'));
        }
        $this->assertSame('2026-11-05T13:45:00Z', CarbonImmutable::parse(data_get($prequalificationPayloads[1], 'service_request.occurrence_period.start'))->utc()->toIso8601ZuluString());
        $this->assertSame('2026-11-05T14:45:00Z', CarbonImmutable::parse(data_get($prequalificationPayloads[1], 'service_request.occurrence_period.end'))->utc()->toIso8601ZuluString());
        $this->assertSame(1, ServiceRequestRequest::wherePersonId($person->id)->count());
        $this->assertSame('15:45', $record->fresh()->startedAt->format('H:i'));
        Livewire::test(EncounterReferralCreate::class, $parameters + ['referral' => $record->id])
            ->assertSet('encounterReferralForm.service_id', $service['id'])
            ->assertSet('encounterReferralSelectedService.code', $service['code'])
            ->assertSet('encounterReferralSelectedService.name', $service['name'])
            ->assertSet('encounterReferralForm.start_time', '15:45')
            ->assertSet('encounterReferralForm.end_time', '16:45');
        Http::assertNotSent(static fn ($request): bool => $request->method() === 'POST'
            && !str_ends_with($request->url(), '/service_requests/prequalify'));
    }

    #[DataProvider('referralSaveActions')]
    public function test_saving_a_new_referral_redirects_to_its_local_edit_url_and_restores_the_saved_form(string $action, bool $sign): void
    {
        ['legalEntity' => $legalEntity, 'person' => $person] = $this->fixture();
        $encounter = $this->encounter($person);
        $this->fakeEHealth(['*' => Http::response($this->eHealthBody([]))]);
        $parameters = compact('legalEntity', 'person', 'encounter') + ['preperson' => null];
        $defaultProgramId = (string) Str::uuid();
        Cache::forever(MedicalProgramDictionary::KEY, [[
            'id' => $defaultProgramId, 'name' => 'Програма державних фінансових гарантій',
            'type' => 'SERVICE', 'is_active' => true,
        ]]);
        Cache::forever(MedicalProgramDictionary::KEY.':fresh', true);
        $serviceId = $this->serviceId();
        $start = now()->addHour()->startOfMinute();
        $end = $start->copy()->addWeek();
        $component = Livewire::test(EncounterReferralCreate::class, $parameters)
            ->assertSet('encounterReferralForm.program_id', $defaultProgramId)
            ->set('encounterReferralForm.service_id', $serviceId)
            ->set('encounterReferralForm.program_id', '')
            ->set('encounterReferralForm.quantity', 2.5)
            ->set('encounterReferralForm.started_at', $start->format('d.m.Y'))
            ->set('encounterReferralForm.start_time', $start->format('H:i'))
            ->set('encounterReferralForm.ended_at', $end->format('d.m.Y'))
            ->set('encounterReferralForm.end_time', $end->format('H:i'))
            ->set('encounterReferralForm.note', 'Saved before redirect')
            ->call($action)->assertHasNoErrors();
        $record = ServiceRequestRequest::whereUuid($component->get('encounterReferralRequestIdToSign'))->sole();
        $editUrl = route('persons.encounters.referrals.edit', [$legalEntity, $person, $encounter, $record->id]);
        $component->assertRedirect($editUrl);
        $this->assertStringEndsWith('/referrals/'.$record->id.'/edit', $editUrl);

        $edit = Livewire::test(EncounterReferralCreate::class, $parameters + ['referral' => $record->id])
            ->assertSet('referralId', $record->id)
            ->assertSet('submissionId', (string) $record->id)
            ->assertSet('encounterReferralRequestIdToSign', $record->uuid)
            ->assertSet('encounterReferralForm.service_id', $serviceId)
            ->assertSet('encounterReferralForm.program_id', '')
            ->assertSet('encounterReferralSelectedService.id', $serviceId)
            ->assertSet('encounterReferralForm.quantity', 2.5)
            ->assertSet('encounterReferralForm.started_at', $start->format('d.m.Y'))
            ->assertSet('encounterReferralForm.start_time', $start->format('H:i'))
            ->assertSet('encounterReferralForm.ended_at', $end->format('d.m.Y'))
            ->assertSet('encounterReferralForm.end_time', $end->format('H:i'))
            ->assertSet('encounterReferralForm.note', 'Saved before redirect')
            ->assertSet('prepareReferralForSigning', $sign)
            ->assertSet('showSignatureModal', false)
            ->assertSet('actionType', null)
            ->assertSee(__('referrals.draft_saved'))
            ->assertDontSee(__('referrals.draft_found_for_signing'));
        if ($sign) {
            $edit->call('prepareSavedReferralForSigning')->assertHasNoErrors()->assertNoRedirect()
                ->assertSet('prepareReferralForSigning', false)
                ->assertSet('showSignatureModal', true)
                ->assertSet('actionType', 'sign_referral');
        }
        $edit->set('showSignatureModal', false)
            ->set('encounterReferralForm.note', 'Edited in place')
            ->call('saveEncounterReferralDraft')->assertHasNoErrors()->assertNoRedirect()
            ->assertSet('encounterReferralRequestIdToSign', $record->uuid);
        $this->assertSame('Edited in place', $record->fresh()->note);
        $this->assertSame(1, ServiceRequestRequest::wherePersonId($person->id)->count());
        $this->get($editUrl)->assertOk()->assertSeeLivewire(EncounterReferralCreate::class);
        Livewire::test(EncounterReferralCreate::class, $parameters + ['referral' => $record->id])
            ->assertSet('showSignatureModal', false)
            ->assertSet('encounterReferralForm.program_id', '');
        Http::assertNotSent(static fn ($request): bool => $request->method() === 'POST'
            && !str_ends_with($request->url(), '/service_requests/prequalify'));
    }

    public function test_start_expiring_during_prequalification_keeps_the_draft_without_opening_kep(): void
    {
        ['legalEntity' => $legalEntity, 'person' => $person] = $this->fixture();
        $encounter = $this->encounter($person);
        $parameters = compact('legalEntity', 'person', 'encounter') + ['preperson' => null];
        $this->fakeEHealth(['*' => Http::response($this->eHealthBody([]))]);
        $programId = (string) Str::uuid();
        $response = $this->qualificationResponse($programId);
        $api = $this->mock(ServiceRequestApi::class);
        $api->shouldNotReceive('createSigned');
        $api->shouldReceive('prequalify')->once()->andReturnUsing(function () use ($response) {
            $this->travel(31)->minutes();

            return $response;
        });

        $component = Livewire::test(EncounterReferralCreate::class, $parameters)
            ->set('encounterReferralForm.service_id', $this->serviceId())
            ->set('encounterReferralForm.program_id', $programId)
            ->call('validateEncounterReferral')->assertHasNoErrors();
        $record = ServiceRequestRequest::whereUuid($component->get('encounterReferralRequestIdToSign'))->sole();
        $start = $record->startedAt->toIso8601String();
        $api->shouldNotHaveReceived('prequalify');

        Livewire::test(EncounterReferralCreate::class, $parameters + ['referral' => $record->id])
            ->call('prepareSavedReferralForSigning')->assertHasErrors('encounterReferralForm.start_time')
            ->assertSet('showSignatureModal', false)->assertSet('actionType', null)
            ->assertSet('encounterReferralRequestIdToSign', $record->uuid)
            ->assertSee(__('errors.ehealth.messages.occurrence_start_in_past'))
            ->assertNoRedirect();
        $record->refresh();
        $this->assertSame('draft', $record->status);
        $this->assertSame($start, $record->startedAt->toIso8601String());
        $this->assertSame(1, ServiceRequestRequest::wherePersonId($person->id)->count());
        app(SignatureService::class)->shouldNotHaveReceived('signData');
        Http::assertNotSent(static fn ($request): bool => $request->method() === 'POST'
            && !str_ends_with($request->url(), '/service_requests/prequalify'));
    }

    public static function referralSaveActions(): array
    {
        return ['save' => ['saveEncounterReferralDraft', false], 'save and sign' => ['validateEncounterReferral', true]];
    }

    public function test_edit_referral_route_rejects_a_draft_of_another_encounter_or_patient(): void
    {
        ['legalEntity' => $legalEntity, 'person' => $person, 'employee' => $employee] = $this->fixture();
        $encounter = $this->encounter($person);
        $lifecycle = app(ReferralRequestLifecycleService::class);
        $uuid = $lifecycle->createEncounterDraft($encounter, [
            'quantity' => 1.0, 'service_id' => $this->serviceId(), 'category' => 'counselling',
        ], $lifecycle->resolveEncounterEmployeeContext($encounter, $employee->id));
        $record = Repository::serviceRequest()->findByUuid($uuid);
        $this->fakeEHealth(['*' => Http::response($this->eHealthBody([]))]);
        $otherPatient = Person::create(['uuid' => (string) Str::uuid(), 'birth_date' => '1990-01-01', 'gender' => 'MALE']);
        foreach ([$this->encounter($person), $this->encounter($otherPatient)] as $otherEncounter) {
            $this->get(route('persons.encounters.referrals.edit', [$legalEntity, $person, $otherEncounter, $record->id]))
                ->assertNotFound();
        }
        Http::assertNotSent(static fn ($request): bool => $request->method() === 'POST');
    }

    public function test_repeated_referral_preparation_updates_the_same_draft_and_explicit_new_starts_empty(): void
    {
        $this->withoutExceptionHandling();
        ['legalEntity' => $legalEntity, 'person' => $person] = $this->fixture();
        $encounter = $this->encounter($person);
        $this->fakeEHealth(['*' => Http::response($this->eHealthBody([]))]);
        $serviceId = $this->serviceId();

        $component = Livewire::test(EncounterReferralCreate::class, compact('legalEntity', 'person', 'encounter') + ['preperson' => null])
            ->set('encounterReferralForm.service_id', $serviceId)
            ->set('encounterReferralForm.program_id', '')
            ->set('encounterReferralForm.note', 'First version')
            ->call('validateEncounterReferral')->assertHasNoErrors()->assertSet('showSignatureModal', false);
        $component = $this->resumeReferralSigning($component, compact('legalEntity', 'person', 'encounter'));
        $uuid = $component->get('encounterReferralRequestIdToSign');
        $draft = ServiceRequestRequest::whereUuid($uuid)->sole();
        $component->assertSet('submissionId', (string) $draft->id);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $component->set('showSignatureModal', false)
                ->set('encounterReferralForm.note', 'Updated '.$attempt)
                ->set('encounterReferralForm.quantity', $attempt + 1)
                ->call('validateEncounterReferral')->assertHasNoErrors()
                ->assertSet('encounterReferralRequestIdToSign', $uuid)
                ->assertSet('showSignatureModal', true)
                ->assertSee(__('referrals.draft_found_for_signing'))
                ->assertDontSee(__('referrals.draft_created_for_signing'))
                ->call('$refresh')->assertDontSee(__('referrals.draft_found_for_signing'));
        }
        $this->assertSame(1, ServiceRequestRequest::wherePersonId($person->id)->count());
        $this->assertSame($draft->id, $draft->fresh()->id);
        $resource = Repository::serviceRequest()->getResource($draft->fresh());
        $this->assertSame('Updated 3', $resource['note']);
        $this->assertEquals(4, $resource['quantity']['value']);
        $payload = ServiceRequestForm::buildCreateContent($resource);
        $this->assertSame($uuid, $payload['id']);
        $this->assertSame('Updated 3', $payload['note']);

        $component->call('openEncounterReferralDrawer')
            ->assertSet('encounterReferralRequestIdToSign', null)
            ->assertSet('submissionId', null)
            ->assertSet('encounterReferralSubmission', [])
            ->assertSet('encounterReferralForm.service_id', '')
            ->assertSet('encounterReferralForm.note', '')
            ->assertSet('showSignatureModal', false)
            ->set('encounterReferralForm.service_id', $serviceId)
            ->set('encounterReferralForm.program_id', '')
            ->call('validateEncounterReferral')->assertHasNoErrors();
        $this->assertNotSame($uuid, $component->get('encounterReferralRequestIdToSign'));
        $this->assertSame(2, ServiceRequestRequest::wherePersonId($person->id)->count());
        $this->assertSame('Updated 3', $draft->fresh()->note);
        Http::assertNotSent(static fn ($request): bool => $request->method() === 'POST'
            && !str_ends_with($request->url(), '/service_requests/prequalify'));
    }

    public function test_new_referral_page_does_not_hydrate_an_existing_draft_but_explicit_resume_does(): void
    {
        ['legalEntity' => $legalEntity, 'person' => $person, 'employee' => $employee] = $this->fixture();
        $encounter = $this->encounter($person);
        $lifecycle = app(ReferralRequestLifecycleService::class);
        $uuid = $lifecycle->createEncounterDraft($encounter, [
            'quantity' => 2,
            'service_id' => $this->serviceId(), 'note' => 'Saved draft',
            'started_at' => now()->addHour()->toIso8601String(), 'ended_at' => now()->addMonth()->toIso8601String(),
        ], $lifecycle->resolveEncounterEmployeeContext($encounter, $employee->id));
        $draft = ServiceRequestRequest::whereUuid($uuid)->sole();
        $this->fakeEHealth(['*' => Http::response($this->eHealthBody([]))]);

        $repository = $this->partialMock(ServiceRequestRequestRepository::class);
        $repository->shouldNotReceive('getResource');
        Livewire::test(EncounterReferralCreate::class, compact('legalEntity', 'person', 'encounter') + ['preperson' => null])
            ->assertSet('encounterReferralRequestIdToSign', null)
            ->assertSet('encounterReferralForm.service_id', '')
            ->assertSet('encounterReferralForm.note', '')
            ->assertDontSee(__('referrals.draft_found_for_signing'));

        $this->app->forgetInstance(ServiceRequestRequestRepository::class);
        Livewire::withQueryParams(['submission' => (string) $draft->id])
            ->test(EncounterReferralCreate::class, compact('legalEntity', 'person', 'encounter') + ['preperson' => null])
            ->assertSet('encounterReferralRequestIdToSign', $uuid)
            ->assertSet('encounterReferralForm.note', 'Saved draft')
            ->assertSet('encounterReferralForm.quantity', 2.0)
            ->assertSee(__('referrals.draft_found_for_signing'))
            ->call('$refresh')->assertDontSee(__('referrals.draft_found_for_signing'))
            ->set('encounterReferralForm.program_id', '')
            ->set('encounterReferralForm.note', 'Resumed draft')
            ->call('validateEncounterReferral')->assertHasNoErrors()
            ->assertSet('encounterReferralRequestIdToSign', $uuid);
        $this->assertSame(1, ServiceRequestRequest::wherePersonId($person->id)->count());
        $this->assertSame('Resumed draft', $draft->fresh()->note);
    }

    #[DataProvider('uneditableReferralStates')]
    public function test_reusing_a_submitted_referral_cannot_overwrite_or_duplicate_it(string $state): void
    {
        ['person' => $person, 'employee' => $employee] = $this->fixture();
        $encounter = $this->encounter($person);
        $lifecycle = app(ReferralRequestLifecycleService::class);
        $context = $lifecycle->resolveEncounterEmployeeContext($encounter, $employee->id);
        $form = ['quantity' => 1, 'service_id' => $this->serviceId(), 'note' => 'Original'];
        $uuid = $lifecycle->createEncounterDraft($encounter, $form, $context);
        $draft = ServiceRequestRequest::whereUuid($uuid)->sole();
        if ($state === 'pending') {
            Repository::serviceRequest()->beginSubmission($draft, ['status' => 'active']);
        } else {
            $draft->update(['status' => $state]);
        }
        try {
            $lifecycle->createEncounterDraft($encounter, array_replace($form, ['quantity' => 2, 'note' => 'Overwrite', 'program_id' => (string) Str::uuid()]), $context, $uuid);
            $this->fail('A submitted referral must not be saved as a new draft.');
        } catch (ValidationException) {
            $this->assertSame('Original', $draft->fresh()->note);
            $this->assertSame(1, ServiceRequestRequest::wherePersonId($person->id)->count());
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
        $lifecycle = app(ReferralRequestLifecycleService::class);
        $context = $lifecycle->resolveEncounterEmployeeContext($encounter, $employee->id);
        $form = ['quantity' => 1, 'service_id' => $this->serviceId(), 'note' => 'Original'];
        $uuid = $lifecycle->createEncounterDraft($encounter, $form, $context);
        $draft = ServiceRequestRequest::whereUuid($uuid)->sole();
        $programId = (string) Str::uuid();
        $response = $this->qualificationResponse($programId);
        $this->mock(ServiceRequestApi::class)
            ->shouldReceive('prequalify')->once()->andReturnUsing(function () use ($draft, $response) {
                $draft->update(['status' => 'active']);

                return $response;
            });

        try {
            $lifecycle->prepareEncounterDraftForSigning($encounter, array_replace($form, ['quantity' => 2, 'note' => 'Overwrite', 'program_id' => $programId]), $context, $uuid);
            $this->fail('The draft state must be checked again when the row is locked for saving.');
        } catch (ValidationException) {
            $this->assertSame('active', $draft->fresh()->status);
            $this->assertSame('Original', $draft->fresh()->note);
        }
    }

    public function test_reusing_a_draft_from_another_encounter_or_patient_is_rejected(): void
    {
        ['person' => $person, 'employee' => $employee] = $this->fixture();
        $encounter = $this->encounter($person);
        $lifecycle = app(ReferralRequestLifecycleService::class);
        $context = $lifecycle->resolveEncounterEmployeeContext($encounter, $employee->id);
        $form = ['quantity' => 1, 'service_id' => $this->serviceId(), 'note' => 'Original'];
        $uuid = $lifecycle->createEncounterDraft($encounter, $form, $context);
        $otherPatient = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']);
        foreach ([$this->encounter($person), $this->encounter($otherPatient)] as $otherEncounter) {
            try {
                $lifecycle->createEncounterDraft($otherEncounter, $form, $context, $uuid);
                $this->fail('The selected draft must belong to this encounter and patient.');
            } catch (ModelNotFoundException) {
                $this->assertSame(1, ServiceRequestRequest::count());
            }
        }
        Http::assertNothingSent();
    }

    #[DataProvider('referralPriorities')]
    public function test_referral_priority_uses_active_service_request_dictionary(string $priority, bool $valid): void
    {
        ['legalEntity' => $legalEntity, 'person' => $person] = $this->fixture();
        $encounter = $this->encounter($person);
        $this->fakeEHealth(['*' => Http::response($this->eHealthBody([]))]);
        $component = Livewire::test(EncounterReferralCreate::class, compact('legalEntity', 'person', 'encounter') + ['preperson' => null])
            ->assertSeeHtml('<option value="routine">Планове</option>')
            ->assertSeeHtml('<option value="urgent">Ургентне</option>')
            ->assertDontSeeHtml('<option value="stat">')
            ->assertDontSeeHtml('<option value="asap">')
            ->assertDontSeeHtml('<option value="retired">')
            ->set('encounterReferralForm.service_id', $this->serviceId())
            ->set('encounterReferralForm.program_id', '')
            ->set('encounterReferralForm.priority', $priority)
            ->call('validateEncounterReferral');

        if (!$valid) {
            $component->assertHasErrors(['encounterReferralForm.priority'])
                ->assertSet('encounterReferralRequestIdToSign', null)
                ->assertSet('showSignatureModal', false);
            $this->assertDatabaseCount('service_request_requests', 0);
            Http::assertNotSent(static fn ($request): bool => $request->method() === 'POST');

            return;
        }

        $component->assertHasNoErrors()->assertSet('showSignatureModal', false);
        $component = $this->resumeReferralSigning($component, compact('legalEntity', 'person', 'encounter'));
        $record = ServiceRequestRequest::whereUuid($component->get('encounterReferralRequestIdToSign'))->sole();
        $resource = Repository::serviceRequest()->getResource($record);
        $this->assertSame($priority, $resource['priority']);
        $lifecycle = app(ReferralRequestLifecycleService::class);
        $data = $lifecycle->buildSignDbData($record, null, $encounter);
        $this->assertSame($priority, (new ServiceRequestMapper())->toCreateSignedContent($data, [])['priority']);
    }

    public static function referralPriorities(): array
    {
        return [
            'routine' => ['routine', true],
            'urgent' => ['urgent', true],
            'medication asap' => ['asap', false],
            'medication stat' => ['stat', false],
            'inactive' => ['retired', false],
            'device forced' => ['forced', false],
        ];
    }

    public function test_save_only_keeps_one_local_draft_without_prequalifying_or_opening_kep(): void
    {
        ['legalEntity' => $legalEntity, 'person' => $person] = $this->fixture();
        $encounter = $this->encounter($person);
        $this->fakeEHealth(['*' => Http::response($this->eHealthBody([]))]);
        $component = Livewire::test(EncounterReferralCreate::class, compact('legalEntity', 'person', 'encounter') + ['preperson' => null])
            ->assertSeeHtml('wire:click="saveEncounterReferralDraft"')
            ->set('encounterReferralForm.service_id', $this->serviceId())
            ->set('encounterReferralForm.program_id', (string) Str::uuid())
            ->call('saveEncounterReferralDraft')->assertHasNoErrors()
            ->assertSet('showSignatureModal', false);
        $uuid = $component->get('encounterReferralRequestIdToSign');
        $component->set('encounterReferralForm.note', 'Edited local draft')
            ->call('saveEncounterReferralDraft')->assertHasNoErrors()
            ->assertSet('encounterReferralRequestIdToSign', $uuid)->assertSet('showSignatureModal', false);
        $record = ServiceRequestRequest::whereUuid($uuid)->sole();
        $this->assertSame('draft', $record->status);
        $this->assertSame('Edited local draft', $record->note);
        $this->assertSame(1, ServiceRequestRequest::wherePersonId($person->id)->count());
        Http::assertNotSent(static fn ($request): bool => $request->method() === 'POST');
        app(SignatureService::class)->shouldNotHaveReceived('signData');
        $component->set('encounterReferralForm.program_id', '')
            ->call('validateEncounterReferral')->assertHasNoErrors()
            ->assertSet('encounterReferralRequestIdToSign', $uuid)->assertSet('showSignatureModal', true);
    }

    #[DataProvider('invalidReferralPeriods')]
    public function test_invalid_referral_period_is_rejected_before_saving_prequalification_and_kep(array $period, string $field): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-03T12:00:00+02:00'));
        ['legalEntity' => $legalEntity, 'person' => $person] = $this->fixture();
        $encounter = $this->encounter($person);
        $this->fakeEHealth(['*' => Http::response($this->eHealthBody([]))]);
        $component = Livewire::test(EncounterReferralCreate::class, compact('legalEntity', 'person', 'encounter') + ['preperson' => null])
            ->set('encounterReferralForm.service_id', $this->serviceId())
            ->set('encounterReferralForm.program_id', (string) Str::uuid());
        foreach ($period as $key => $value) {
            $component->set('encounterReferralForm.'.$key, $value);
        }
        foreach (['saveEncounterReferralDraft', 'validateEncounterReferral'] as $action) {
            $component->call($action)->assertHasErrors('encounterReferralForm.'.$field)
                ->assertSet('encounterReferralRequestIdToSign', null)->assertSet('showSignatureModal', false);
        }
        $this->assertDatabaseCount('service_request_requests', 0);
        Http::assertNotSent(static fn ($request): bool => $request->method() === 'POST');
        app(SignatureService::class)->shouldNotHaveReceived('signData');
    }

    public static function invalidReferralPeriods(): array
    {
        $valid = ['started_at' => '04.11.2026', 'start_time' => '10:00', 'ended_at' => '04.11.2026', 'end_time' => '11:00'];

        return [
            'past start despite future end' => [array_replace($valid, ['started_at' => '03.11.2026']), 'start_time'],
            'end before start on same date' => [array_replace($valid, ['end_time' => '09:59']), 'ended_at'],
            'end on previous date' => [array_replace($valid, ['ended_at' => '03.11.2026']), 'ended_at'],
            'impossible date' => [array_replace($valid, ['started_at' => '31.02.2027']), 'started_at'],
            'invalid hour' => [array_replace($valid, ['start_time' => '24:00']), 'start_time'],
            'incomplete time' => [array_replace($valid, ['end_time' => '11']), 'end_time'],
            'nonexistent spring start' => [['started_at' => '28.03.2027', 'start_time' => '03:30', 'ended_at' => '28.03.2027', 'end_time' => '05:00'], 'start_time'],
            'nonexistent spring end' => [['started_at' => '28.03.2027', 'start_time' => '02:30', 'ended_at' => '28.03.2027', 'end_time' => '03:30'], 'end_time'],
        ];
    }

    public static function documentPages(): array
    {
        return [
            'referral' => ['persons.encounters.referrals.create', EncounterReferralCreate::class],
            'eprescription' => ['persons.encounters.eprescriptions.create', EncounterEPrescriptionCreate::class],
        ];
    }

    #[DataProvider('referralTimezoneDates')]
    public function test_referral_dates_keep_the_same_instant_in_storage_display_and_signed_content(string $local, string $utc): void
    {
        config(['app.timezone' => 'Europe/Kyiv']);
        ['person' => $person, 'employee' => $employee] = $this->fixture();
        $encounter = $this->encounter($person);
        $lifecycle = app(ReferralRequestLifecycleService::class);
        $context = $lifecycle->resolveEncounterEmployeeContext($encounter, $employee->id);
        $uuid = $lifecycle->createEncounterDraft($encounter, [
            'quantity' => 1,
            'service_id' => $this->serviceId(), 'started_at' => $local, 'ended_at' => $local,
        ], $context);
        $record = ServiceRequestRequest::whereUuid($uuid)->sole();
        $expected = CarbonImmutable::parse($local)->setTimezone('Europe/Kyiv');
        $this->assertSame($expected->format('Y-m-d H:i P'), $record->startedAt->format('Y-m-d H:i P'));
        $this->assertSame($expected->format('Y-m-d H:i P'), $record->endedAt->format('Y-m-d H:i P'));
        $this->assertSame($utc, CarbonImmutable::parse($record->getRawOriginal('started_at'), 'UTC')->toIso8601ZuluString());
        $data = $lifecycle->buildSignDbData($record, null, $encounter, $context);
        $payload = (new ServiceRequestMapper())->toCreateSignedContent($data, []);
        $this->assertSame($utc, CarbonImmutable::parse($payload['occurrence_period']['end'])->toIso8601ZuluString());
        $record->startedAt = $record->startedAt;
        $record->save();
        $this->assertSame($expected->timestamp, $record->fresh()->startedAt->timestamp);
    }

    public static function referralTimezoneDates(): array
    {
        return [
            'winter midnight' => ['2026-11-02T00:30:00+02:00', '2026-11-01T22:30:00Z'],
            'summer midnight' => ['2026-07-02T00:30:00+03:00', '2026-07-01T21:30:00Z'],
        ];
    }

    #[DataProvider('referralSigningPeriods')]
    public function test_referral_period_edits_after_draft_reload_reach_the_signer(string $clock, string $start, string $end): void
    {
        $this->travelTo(CarbonImmutable::parse($clock));
        ['legalEntity' => $legalEntity, 'person' => $person] = $this->fixture();
        $encounter = $this->encounter($person);
        $this->fakeEHealth(['*' => Http::response($this->eHealthBody([]))]);
        $parameters = compact('legalEntity', 'person', 'encounter') + ['preperson' => null];
        $startDate = CarbonImmutable::parse($start);
        $endDate = CarbonImmutable::parse($end);
        $period = [
            'start' => $startDate->utc()->toIso8601ZuluString(),
            'end' => $endDate->utc()->toIso8601ZuluString(),
        ];

        $component = Livewire::test(EncounterReferralCreate::class, $parameters)
            ->set('encounterReferralForm.service_id', $this->serviceId())
            ->set('encounterReferralForm.program_id', '')
            ->set('encounterReferralForm.started_at', $startDate->format('d.m.Y'))
            ->set('encounterReferralForm.start_time', $startDate->format('H:i'))
            ->set('encounterReferralForm.ended_at', $endDate->format('d.m.Y'))
            ->set('encounterReferralForm.end_time', $endDate->format('H:i'))
            ->call('validateEncounterReferral')->assertHasNoErrors();
        $uuid = $component->get('encounterReferralRequestIdToSign');
        $record = ServiceRequestRequest::whereUuid($uuid)->sole();
        $this->assertSame($start, $record->startedAt->toIso8601String());
        $this->assertSame($end, $record->endedAt->toIso8601String());
        $this->assertSame(CarbonImmutable::parse($clock)->timestamp, $record->createdAt->timestamp);
        $repository = Repository::serviceRequest();
        $this->assertSame($period, $repository->getResource($record)['occurrencePeriod']);

        Bus::fake();
        $api = Mockery::mock(ServiceRequestApi::class);
        $api->shouldReceive('prequalify')->once()->andReturn($this->qualificationResponse());
        $api->shouldReceive('createSigned')->once()
            ->with($person->uuid, ['signed_data' => 'base64-signed-payload', 'signed_data_encoding' => 'base64'])
            ->andReturn(new EHealthResponse(
                new HttpResponse(202, [], json_encode(['data' => ['job_id' => 'referral-timezone-job', 'status' => 'PENDING']], JSON_THROW_ON_ERROR)),
                Job::validateSubmissionResponse(...)
            ));
        $this->instance(ServiceRequestApi::class, $api);

        $editedStart = $startDate->setTimezone('Europe/Kyiv')->addDays(2)->setTime(10, 0);
        $editedEnd = $endDate->setTimezone('Europe/Kyiv')->addDays(2)->setTime(11, 0);
        $editedPeriod = [
            'start' => $editedStart->utc()->toIso8601ZuluString(),
            'end' => $editedEnd->utc()->toIso8601ZuluString(),
        ];
        Livewire::withQueryParams(['submission' => (string) $record->id])->test(EncounterReferralCreate::class, $parameters)
            ->assertSet('encounterReferralForm.service_id', $record->serviceId)
            ->assertSet('encounterReferralForm.started_at', $startDate->format('d.m.Y'))
            ->assertSet('encounterReferralForm.start_time', $startDate->format('H:i'))
            ->assertSet('encounterReferralForm.ended_at', $endDate->format('d.m.Y'))
            ->assertSet('encounterReferralForm.end_time', $endDate->format('H:i'))
            ->set('encounterReferralForm.started_at', $editedStart->format('d.m.Y'))
            ->set('encounterReferralForm.start_time', '10:00')
            ->set('encounterReferralForm.ended_at', $editedEnd->format('d.m.Y'))
            ->set('encounterReferralForm.end_time', '11:00')
            ->call('validateEncounterReferral')->assertHasNoErrors()
            ->assertSet('encounterReferralRequestIdToSign', $uuid)
            ->set('form.knedp', 'ca-1')
            ->set('form.password', 'synthetic-password')
            ->set('form.keyContainerUpload', UploadedFile::fake()->create('key.dat', 8))
            ->call('sign')->assertHasNoErrors()
            ->assertSet('encounterReferralSubmission.status', JobStatus::PENDING->value)
            ->assertRedirect(route('persons.encounters.referrals.edit', [$legalEntity, $person, $encounter, $record->id]));

        app(SignatureService::class)->shouldHaveReceived('signData')
            ->withArgs(static fn (array $payload): bool => $payload['id'] === $uuid && $payload['occurrence_period'] === $editedPeriod)
            ->once();
        $this->assertSame($editedPeriod, $repository->getResource($record->fresh())['occurrencePeriod']);
        $this->assertSame($editedStart->toIso8601String(), $record->fresh()->startedAt->toIso8601String());
        $this->assertSame($editedEnd->toIso8601String(), $record->fresh()->endedAt->toIso8601String());
        $this->assertSame(1, ServiceRequestRequest::wherePersonId($person->id)->count());
        $this->assertSame('draft', $record->status);
    }

    public static function referralSigningPeriods(): array
    {
        return [
            'winter two hour offset' => ['2026-11-02T12:00:00+02:00', '2026-11-02T12:30:00+02:00', '2027-02-02T12:30:00+02:00'],
            'summer three hour offset' => ['2026-07-02T12:00:00+03:00', '2026-07-02T12:30:00+03:00', '2026-10-02T12:30:00+03:00'],
            'midnight across daylight saving change' => ['2027-02-02T23:40:00+02:00', '2027-02-03T00:10:00+02:00', '2027-05-03T00:10:00+03:00'],
        ];
    }

    public function test_refresh_keeps_the_local_draft_id_and_resolves_its_plan_actor_before_completion(): void
    {
        ['legalEntity' => $legalEntity, 'person' => $person] = $this->fixture();
        $encounter = $this->encounter($person);
        $this->fakeEHealth(['*' => Http::response($this->eHealthBody([]))]);
        $component = Livewire::test(EncounterReferralCreate::class, compact('legalEntity', 'person', 'encounter') + ['preperson' => null])
            ->set('encounterReferralForm.service_id', $this->serviceId())
            ->set('encounterReferralForm.program_id', '')
            ->call('saveEncounterReferralDraft')->assertHasNoErrors();
        $record = ServiceRequestRequest::whereUuid($component->get('encounterReferralRequestIdToSign'))->sole();
        $this->partialMock(ServiceRequestRequestRepository::class)->shouldReceive('findByUuid')
            ->once()->with($record->uuid)->andReturn($record);
        $component->call('$refresh')->assertSet('submissionId', (string) $record->id);
        $this->app->forgetInstance(ServiceRequestRequestRepository::class);

        $record->forceFill(['status' => 'active', 'requestNumber' => '1234-5678-9012-3456'])->save();
        $component->call('checkEncounterReferralSubmission')
            ->assertSet('encounterReferralRequestIdToSign', null)->assertSet('submissionId', null)
            ->assertRedirect(route('persons.encounters.package.show', [$legalEntity, $person, $encounter]));
        Livewire::test(XMessage::class)
            ->assertSee(__('referrals.submission_created', ['number' => '1234-5678-9012-3456']))
            ->call('$refresh')->assertDontSee('1234-5678-9012-3456');
        $this->get(route('persons.encounters.referrals.edit', [$legalEntity, $person, $encounter, $record->id]))
            ->assertOk()->assertSee('1234-5678-9012-3456')
            ->assertDontSeeHtml('wire:click="saveEncounterReferralDraft"')
            ->assertDontSee(__('forms.save_and_sign'));
        Http::assertNotSent(static fn ($request): bool => $request->method() === 'POST');
    }

    #[DataProvider('missingSignatureFields')]
    public function test_signature_validation_keeps_the_modal_open_and_shows_the_field_error(string $field): void
    {
        ['legalEntity' => $legalEntity, 'person' => $person] = $this->fixture();
        $encounter = $this->encounter($person);
        $this->fakeEHealth(['*' => Http::response($this->eHealthBody([]))]);
        $component = Livewire::test(EncounterReferralCreate::class, compact('legalEntity', 'person', 'encounter') + ['preperson' => null])
            ->set('encounterReferralForm.service_id', $this->serviceId())
            ->set('encounterReferralForm.program_id', '')
            ->call('validateEncounterReferral')->assertHasNoErrors();
        $component = $this->resumeReferralSigning($component, compact('legalEntity', 'person', 'encounter'))
            ->set('form.knedp', $field === 'knedp' ? '' : 'ca-1')
            ->set('form.password', $field === 'password' ? '' : 'synthetic-password');
        if ($field !== 'keyContainerUpload') {
            $component->set('form.keyContainerUpload', UploadedFile::fake()->create('key.dat', 8));
        }
        $component->call('sign')->assertHasErrors('form.'.$field)
            ->assertSet('showSignatureModal', true)->assertSet('actionType', 'sign_referral')
            ->assertSee($component->instance()->getErrorBag()->first('form.'.$field));
        app(SignatureService::class)->shouldNotHaveReceived('signData');
        Http::assertNotSent(static fn ($request): bool => $request->method() === 'POST'
            && !str_ends_with($request->url(), '/service_requests/prequalify'));
    }

    public static function missingSignatureFields(): array
    {
        return ['password' => ['password'], 'provider' => ['knedp'], 'key file' => ['keyContainerUpload']];
    }

    public function test_referral_that_expires_while_waiting_for_kep_is_rejected_before_signing(): void
    {
        ['legalEntity' => $legalEntity, 'person' => $person] = $this->fixture();
        $encounter = $this->encounter($person);
        $this->fakeEHealth(['*' => Http::response($this->eHealthBody([]))]);
        $component = Livewire::test(EncounterReferralCreate::class, compact('legalEntity', 'person', 'encounter') + ['preperson' => null])
            ->set('encounterReferralForm.service_id', $this->serviceId())
            ->set('encounterReferralForm.program_id', '')
            ->call('validateEncounterReferral')->assertHasNoErrors();
        $component = $this->resumeReferralSigning($component, compact('legalEntity', 'person', 'encounter'));
        $uuid = $component->get('encounterReferralRequestIdToSign');
        $this->travel(31)->minutes();
        $component->call('sign')->assertSet('showSignatureModal', false)
            ->assertSee(__('errors.ehealth.messages.occurrence_start_in_past'));
        $this->assertSame('draft', ServiceRequestRequest::whereUuid($uuid)->sole()->status);
        Http::assertNotSent(static fn ($request): bool => $request->method() === 'POST'
            && !str_ends_with($request->url(), '/service_requests/prequalify'));
    }

    public function test_referral_that_expires_during_signing_is_rejected_before_post_and_submission_tracking(): void
    {
        ['legalEntity' => $legalEntity, 'person' => $person] = $this->fixture();
        $encounter = $this->encounter($person);
        $this->fakeEHealth(['*' => Http::response($this->eHealthBody([]))]);
        $signer = Mockery::mock(SignatureService::class);
        $signer->shouldReceive('getCertificateAuthorities')->andReturn([]);
        $signer->shouldReceive('signData')->once()->andReturnUsing(function (): string {
            $this->travel(31)->minutes();

            return 'signed-but-expired';
        });
        $this->instance(SignatureService::class, $signer);
        $api = $this->mock(ServiceRequestApi::class);
        $api->shouldNotReceive('createSigned');
        $api->shouldReceive('prequalify')->once()->andReturn($this->qualificationResponse());
        $component = Livewire::test(EncounterReferralCreate::class, compact('legalEntity', 'person', 'encounter') + ['preperson' => null])
            ->set('encounterReferralForm.service_id', $this->serviceId())
            ->set('encounterReferralForm.program_id', '')
            ->call('validateEncounterReferral')->assertHasNoErrors();
        $component = $this->resumeReferralSigning($component, compact('legalEntity', 'person', 'encounter'))
            ->set('form.knedp', 'ca-1')->set('form.password', 'synthetic-password')
            ->set('form.keyContainerUpload', UploadedFile::fake()->create('key.dat', 8))
            ->call('sign')->assertHasErrors('encounterReferralForm.start_time')->assertSet('showSignatureModal', false);
        $record = ServiceRequestRequest::whereUuid($component->get('encounterReferralRequestIdToSign'))->sole();
        $this->assertSame('draft', $record->status);
        $this->assertFalse($record->ehealthLinks()->exists());
    }

    public static function allDocumentRoutes(): array
    {
        return [
            'referral' => ['persons.encounters.referrals.create'],
            'eprescription' => ['persons.encounters.eprescriptions.create'],
            'care-plan' => ['persons.encounters.care-plans.create'],
        ];
    }

    private function resumeReferralSigning(Testable $component, array $parameters): Testable
    {
        $record = ServiceRequestRequest::whereUuid($component->get('encounterReferralRequestIdToSign'))->sole();
        $component->assertSet('showSignatureModal', false)
            ->assertRedirect(route('persons.encounters.referrals.edit', [...array_values($parameters), $record->id]));

        return Livewire::test(EncounterReferralCreate::class, $parameters + ['preperson' => null, 'referral' => $record->id])
            ->assertSet('prepareReferralForSigning', true)
            ->assertSet('showSignatureModal', false)
            ->call('prepareSavedReferralForSigning')->assertHasNoErrors()->assertNoRedirect()
            ->assertSet('showSignatureModal', true);
    }

    private function qualificationResponse(?string $programId = null): EHealthResponse
    {
        $data = $programId === null ? [] : [['program_id' => $programId, 'program_name' => 'Synthetic program', 'status' => 'VALID']];

        return new EHealthResponse(new HttpResponse(200, [], json_encode(['data' => $data], JSON_THROW_ON_ERROR)), ServiceRequestApi::validateQualification(...));
    }

    private function serviceId(): string
    {
        $id = (string) Str::uuid();
        $services = Cache::get(ServiceDictionary::KEY, []);
        $services[] = [
            'id' => $id, 'code' => 'SYNTHETIC', 'name' => 'Synthetic service', 'category' => 'counselling',
            'is_composition' => false, 'request_allowed' => true, 'is_active' => true,
            'inserted_at' => '2026-01-01T09:00:00Z', 'updated_at' => '2026-01-01T09:00:00Z',
            'inserted_by' => NativeClinicalFixtures::UUID, 'updated_by' => NativeClinicalFixtures::UUID,
        ];
        Cache::forever(ServiceDictionary::KEY, $services);
        Cache::forever(ServiceDictionary::KEY.':fresh', true);

        return $id;
    }

    protected function fakeEHealth(array $routes): void
    {
        $this->fakeBaseEHealth($routes);
        $factory = Http::getFacadeRoot();
        $stubs = (function () {
            return $this->stubCallbacks;
        })->call($factory);
        $api = new ServiceRequestApi($factory);
        $api->stub($stubs);
        $this->instance(ServiceRequestApi::class, $api);
    }

    private function fixture(): array
    {
        $fixture = $this->compositionFixture(scopes: ['encounter:read', 'service_request:write', 'medication_request_request:write', 'care_plan:write']);
        $fixture['employee']->update(['start_date' => '2020-01-01']);
        $this->grantReferralEmployeeRole($fixture['user'], $fixture['employee'], 'ehealth');
        foreach (['encounter:read', 'service_request:write', 'medication_request_request:write', 'care_plan:write'] as $scope) {
            $permission = Permission::findOrCreate($scope, 'ehealth');
            $permission->legalEntityTypes()->syncWithoutDetaching([$fixture['legalEntity']->legalEntityTypeId]);
            $fixture['user']->givePermissionToParent($permission);
        }
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
        $user->givePermissionToParent(Permission::findOrCreate('service_request:write', 'web'));
        $this->assertFalse($policy->createServiceRequest($user, $encounter, $person, $legalEntity->id + 1)->allowed());
    }
}
