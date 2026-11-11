<?php

declare(strict_types=1);

namespace Tests\Feature\DeviceRequest;

use App\Classes\eHealth\Api\CarePlan as CarePlanApi;
use App\Classes\eHealth\Api\CarePlanActivity;
use App\Classes\eHealth\Api\Configuration;
use App\Classes\eHealth\Api\DeviceDefinition;
use App\Classes\eHealth\Api\Patient\DeviceRequest as DeviceRequestApi;
use App\Classes\eHealth\EHealthResponse;
use App\Core\Arr;
use App\Livewire\CarePlan\CarePlanApprovals;
use App\Livewire\DeviceRequest\DeviceRequestForm;
use App\Models\CarePlan;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Coding;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\Person\Person;
use App\Models\User;
use App\Policies\EncounterPolicy;
use App\Repositories\CarePlanRepository;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use App\Repositories\Repository;
use App\Services\MedicalEvents\DeviceRequestLifecycleService;
use App\Services\MedicalEvents\Mappers\DeviceRequestMapper;
use App\Services\SignatureService;
use Illuminate\Auth\Access\Response as PolicyResponse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Tests\Concerns\CreatesFhirCarePlans;
use Tests\Feature\Composition\CompositionTestFixtures;
use Tests\Support\NativeClinicalFixtures;
use Tests\TestCase;

class DeviceRequestTv39RemediationTest extends TestCase
{
    use CompositionTestFixtures;
    use CreatesFhirCarePlans;
    use DatabaseTransactions;

    private function documentContext(): array
    {
        $this->withoutVite();
        $context = $this->compositionFixture(scopes: ['device_request:write', 'device_request:read', 'care_plan:read', 'care_plan:write', 'encounter:read']);
        $employee = $context['employee'];
        $person = $context['person'];
        $this->grantCarePlanEmployeeRole($employee, 'OUTPATIENT');
        $this->partialMock(EncounterPolicy::class)->shouldReceive('view')->andReturn(PolicyResponse::allow());
        $this->fakeSignatureService();
        $encounter = Encounter::create(['uuid' => (string) Str::uuid(), 'person_id' => $person->id, 'status' => 'finished',
            'episode_id' => Identifier::create(['value' => (string) Str::uuid()])->id,
            'performer_id' => Identifier::create(['value' => $employee->uuid])->id,
            'type_id' => CodeableConcept::create()->id,
            'class_id' => Coding::create(['system' => 'eHealth/encounter_classes', 'code' => 'AMB'])->id]);
        $encounter->period()->create(['start' => now()->startOfDay()->utc(), 'end' => now()->utc()]);
        $plan = $this->createFhirCarePlan(['person_id' => $person->id, 'legal_entity_id' => $context['legalEntity']->id,
            'author_id' => $employee->id, 'encounter_id' => $encounter->id, 'status' => 'active',
            'title' => 'BA08 device plan', 'period_end' => now()->addMonth()->toDateString()]);
        $this->grantCarePlanWriteApproval($plan, $employee);
        $activity = $this->createFhirCarePlanActivity(['care_plan_id' => $plan->id, 'author_id' => $employee->id,
            'kind' => 'device_request', 'status' => 'scheduled', 'quantity' => 10,
            'quantity_code' => 'piece', 'quantity_system' => 'device_unit',
            'product_codeable_concept' => ['coding' => [['system' => 'assistive_devices', 'code' => 'walker']]]]);
        $resource = Repository::carePlanActivity()->getResource($activity);
        $resource['detail']['remainingQuantity'] = ['value' => 5, 'code' => 'piece', 'system' => 'device_unit', 'unit' => 'шт.'];
        Repository::carePlanActivity()->storeResource($resource, $plan, $activity->id);
        $service = Mockery::mock(DeviceRequestLifecycleService::class)->makePartial();
        $service->shouldReceive('carePlanContext')->withArgs(static fn (Person $patient, string $planId, string $activityId, string $actor): bool => $patient->id === $person->id
                && $planId === $plan->uuid && $activityId === $activity->uuid && $actor === $employee->uuid)
            ->andReturn(['detail' => Arr::toSnakeCase($resource['detail'])]);
        $service->shouldReceive('prescribingOptions')->andReturn(['model' => [], 'program' => [], 'parameters' => [], 'packages' => []]);
        $service->shouldReceive('dictionary')->with('device_unit')->andReturn(['piece' => 'Штука']);
        $service->shouldNotReceive('prepare');
        $this->instance(DeviceRequestLifecycleService::class, $service);
        Http::preventStrayRequests();

        return [...$context, 'encounter' => $encounter, 'plan' => $plan, 'activity' => $activity];
    }

    private function planHarness(array $context)
    {
        return Livewire::test(DeviceRequestPlanHarness::class, [
            'legalEntity' => $context['legalEntity'], 'person' => $context['person'], 'encounter' => $context['encounter'],
        ]);
    }

    public function test_plan_selection_preserves_remaining_quantity_and_exact_actor_after_search(): void
    {
        $context = $this->documentContext();
        $this->planHarness($context)->call('searchCarePlans')->assertHasNoErrors()->assertCount('carePlanChoices', 1)
            ->call('selectCarePlan', $context['plan']->id)->assertCount('carePlanActivityChoices', 1)
            ->call('selectCarePlanActivity', $context['activity']->id)->assertSet('activityUuid', $context['activity']->uuid)
            ->assertSee('шт.')->set('carePlanSearch', 'another search')->call('searchCarePlans')
            ->assertSet('carePlanApprovalRequired', false)->assertCount('carePlanActivityChoices', 1);
        $this->assertDatabaseCount('device_request_requests', 0);
        Http::assertNothingSent();
    }

    public function test_denied_plan_search_shows_feedback_without_calling_the_service(): void
    {
        $context = $this->documentContext();
        $context['user']->syncRoles([]);
        $context['user']->revokePermissionTo('device_request:write');
        $this->planHarness($context)->call('searchCarePlans')->assertSet('carePlanChoices', [])
            ->assertSee(__('device-requests.policy.create'));
        $this->assertDatabaseCount('device_request_requests', 0);
        Http::assertNothingSent();
    }

    public function test_revoked_plan_approval_stops_save_and_sign_with_visible_feedback(): void
    {
        $context = $this->documentContext();
        $component = $this->planHarness($context)->call('selectCarePlan', $context['plan']->id)
            ->call('selectCarePlanActivity', $context['activity']->id);
        $context['plan']->approvals()->update(['is_verified' => false]);
        // Keep catalog/configuration boundaries observable after consent is revoked.
        $this->mock(Configuration::class)->shouldNotReceive('getDictionaries');
        $this->mock(DeviceDefinition::class)->shouldNotReceive('getMany')->shouldNotReceive('getDetails');
        $service = Mockery::mock(DeviceRequestLifecycleService::class)->makePartial();
        $service->shouldNotReceive('prepare')->shouldNotReceive('prescribingOptions');
        $this->instance(DeviceRequestLifecycleService::class, $service);
        $component->call('createDraft')->assertSet('draftId', null)->assertSee(__('device-requests.validation.inactive_plan'))
            ->call('primeSigning')->set('form.knedp', 'test')->set('form.password', 'test')
            ->set('form.keyContainerUpload', UploadedFile::fake()->create('key.dat', 1))
            ->call('sign')->assertSet('showSignatureModal', true)->assertSee(__('device-requests.validation.inactive_plan'));
        $this->assertDatabaseCount('device_request_requests', 0);
        Http::assertNothingSent();
    }

    public function test_rendered_approval_uses_selected_actor_and_remounts_without_falling_back(): void
    {
        $context = $this->documentContext();
        $plan = $context['plan'];
        $encounter = $context['encounter'];
        $first = $context['employee'];
        $second = $first->replicate();
        $second->uuid = (string) Str::uuid();
        $second->save();
        $context['user']->employees()->attach($second->id);
        $this->grantCarePlanEmployeeRole($second, 'OUTPATIENT');
        $plan->approvals()->delete();
        $this->assertSame($first->uuid, $context['user']->getCarePlanWriterEmployee('OUTPATIENT')->uuid);
        $encounter->performer()->update(['value' => $second->uuid]);
        Queue::fake();
        DeviceApprovalActorCapture::$receivedActors = [];
        Livewire::component('care-plan.care-plan-approvals', DeviceApprovalActorCapture::class);

        try {
            $component = $this->planHarness($context)->call('selectCarePlan', $plan->id);
            $component->assertSeeHtml('data-approval-actor="'.$second->uuid.'"');
            $this->assertSame([$second->uuid], DeviceApprovalActorCapture::$receivedActors);

            // The plan stays selected; a different actor must mount a new approval component.
            $encounter->performer()->update(['value' => $first->uuid]);
            $component->call('$refresh')->assertSeeHtml('data-approval-actor="'.$first->uuid.'"');
            $this->assertSame([$second->uuid, $first->uuid], DeviceApprovalActorCapture::$receivedActors);

            // Another writer remains available, but cannot receive this encounter's consent.
            $first->update(['is_active' => false]);
            $this->assertSame($second->uuid, $context['user']->getCarePlanWriterEmployee('OUTPATIENT')->uuid);
            $component->call('searchCarePlans')->assertDontSeeHtml('data-approval-actor=');
            $this->assertSame([$second->uuid, $first->uuid], DeviceApprovalActorCapture::$receivedActors);

            $first->update(['is_active' => true]);
            $context['user']->syncRoles([]);
            $context['user']->revokePermissionTo('device_request:write');
            $context['user']->unsetRelation('roles')->unsetRelation('permissions');
            $this->assertFalse($context['user']->can('device_request:write'));
            $component->call('searchCarePlans')->assertDontSeeHtml('data-approval-actor=')
                ->assertSee(__('device-requests.policy.create'));
            $this->assertSame([$second->uuid, $first->uuid], DeviceApprovalActorCapture::$receivedActors);
            $this->assertDatabaseCount('device_request_requests', 0);
            $this->assertSame(0, $plan->approvals()->count());
            Http::assertNothingSent();
            Queue::assertNothingPushed();
        } finally {
            Livewire::component('care-plan.care-plan-approvals', CarePlanApprovals::class);
        }
    }

    public function test_selected_model_displays_native_manufacturer_name_and_country(): void
    {
        [$person, $entity] = $this->signingContext();
        $definition = NativeClinicalFixtures::definition(['manufacturer' => ['name' => 'Виробник виробу', 'country' => 'UA']]);
        $model = NativeClinicalFixtures::definitionResponse($definition)->validate();

        Livewire::test(DeviceRequestManufacturerHarness::class, ['legalEntity' => $entity, 'person' => $person, 'model' => $model])
            ->assertSee(__('device-requests.form.manufacturer'))->assertSee('Виробник виробу')->assertSee('(UA)');
    }

    public function test_program_participant_is_not_displayed_as_a_missing_manufacturer(): void
    {
        [$person, $entity] = $this->signingContext();
        $model = NativeClinicalFixtures::definitionResponse(NativeClinicalFixtures::definition())->validate();

        Livewire::test(DeviceRequestManufacturerHarness::class, ['legalEntity' => $entity, 'person' => $person, 'model' => $model])
            ->assertDontSee(__('device-requests.form.manufacturer'))->assertDontSee('Учасник програми');
    }

    #[DataProvider('malformedManufacturers')]
    public function test_incomplete_or_malformed_manufacturer_is_rejected_before_display(array $manufacturer): void
    {
        $this->expectException(ValidationException::class);
        NativeClinicalFixtures::definitionResponse(NativeClinicalFixtures::definition(['manufacturer' => $manufacturer]))->validate();
    }

    public static function malformedManufacturers(): array
    {
        return ['missing country' => [['name' => 'Manufacturer']], 'invalid name' => [['name' => ['Manufacturer'], 'country' => 'UA']]];
    }

    public function test_new_clinical_identifier_uses_version_one_and_existing_identifier_is_preserved(): void
    {
        $context = ['person_uuid' => (string) Str::uuid(), 'employee_uuid' => (string) Str::uuid(),
            'legal_entity_uuid' => (string) Str::uuid()];
        $mapper = new DeviceRequestMapper();
        $new = $mapper->toFhir(['device_id' => 'walker'], $context);
        $this->assertSame(1, Uuid::fromString($new['id'])->getVersion());
        $existing = (string) Str::uuid();
        $this->assertSame($existing, $mapper->toFhir(['uuid' => $existing, 'device_id' => 'walker'], $context)['id']);
    }

    public static function activityPeriods(): array
    {
        return [
            'non-program activity without period' => [null, false, true],
            'program activity without period' => [null, true, false],
            'empty period' => [[], false, false],
            'end without start' => [['end' => '2026-11-30'], false, false],
            'valid open period' => [['start' => '2026-11-01'], false, true],
            'valid program period' => [['start' => '2026-11-01', 'end' => '2026-11-30'], true, true],
            'future period' => [['start' => '2026-11-20'], false, false],
            'expired period' => [['start' => '2026-11-01', 'end' => '2026-11-05'], false, false],
        ];
    }

    #[DataProvider('activityPeriods')]
    public function test_optional_activity_period_preserves_plan_and_program_limits(?array $period, bool $program, bool $allowed): void
    {
        $this->travelTo(now()->setDate(2026, 11, 11)->startOfDay());
        $person = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']);
        $planUuid = (string) Str::uuid();
        $activityUuid = (string) Str::uuid();
        $plan = new CarePlan(['uuid' => $planUuid]);
        $this->mock(CarePlanRepository::class)->shouldReceive('findByUuid')->once()
            ->with($planUuid, legalEntity()?->id, $person->id)->andReturn($plan);
        Gate::shouldReceive('authorize')->once()->with('manageActivities', [$plan, null])->andReturnTrue();
        $this->mock(CarePlanApi::class)->shouldReceive('getDetails')->once()
            ->with($person->uuid, $planUuid)->andReturn($this->validatedResponse([
                'status' => 'active', 'period' => ['start' => '2026-11-01', 'end' => '2026-11-30'],
            ]));
        $detail = ['kind' => 'device_request', 'status' => 'scheduled'];
        if ($period !== null) {
            $detail['scheduled_period'] = $period;
        }
        if ($program) {
            $detail['program'] = ['identifier' => ['value' => (string) Str::uuid()]];
        }
        $this->mock(CarePlanActivity::class)->shouldReceive('getDetails')->once()
            ->with($person->uuid, $planUuid, $activityUuid)
            ->andReturn($this->validatedResponse(['detail' => $detail]));
        if (!$allowed) {
            $this->expectException(ValidationException::class);
        }
        $context = app(DeviceRequestLifecycleService::class)->carePlanContext($person, $planUuid, $activityUuid);
        $this->assertSame($detail, $context['detail']);
    }

    public function test_signing_fields_remain_visible_and_validation_has_no_external_or_database_effects(): void
    {
        [$person, $entity] = $this->signingContext();
        $this->mock(DeviceRequestApi::class)->shouldNotReceive('createSigned');
        $this->mock(DeviceRequestLifecycleService::class)->shouldNotReceive('prescribingOptions');
        Http::preventStrayRequests();
        Queue::fake();
        $component = Livewire::test(DeviceRequestSigningHarness::class, ['legalEntity' => $entity, 'person' => $person]);
        $component->call('sign')->assertSet('showSignatureModal', true)
            ->assertHasErrors(['form.knedp', 'form.keyContainerUpload', 'form.password'])
            ->assertSee(__('forms.sign_with_KEP'))
            ->assertSee('wire:submit="sign"', false);
        $this->assertDatabaseCount('device_request_requests', 0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_concurrent_submission_keeps_the_modal_and_original_field_values(): void
    {
        [$person, $entity] = $this->signingContext();
        $this->mock(DeviceRequestApi::class)->shouldNotReceive('createSigned');
        $this->mock(DeviceRequestLifecycleService::class)->shouldNotReceive('prescribingOptions');
        $component = Livewire::test(DeviceRequestSigningHarness::class, ['legalEntity' => $entity, 'person' => $person]);
        $id = $component->get('requestUuid');
        $lock = Cache::lock('device-request-sign:'.$person->uuid.':'.$id, 600);
        $this->assertTrue($lock->get());
        try {
            $component->set('form.knedp', 'test-provider')
                ->set('form.keyContainerUpload', UploadedFile::fake()->create('test.dat', 1))
                ->set('form.keyContainerFileName', 'test.dat')->set('form.password', 'test-password')
                ->call('sign')->assertSet('showSignatureModal', true)
                ->assertSet('form.password', 'test-password')->assertSet('form.keyContainerFileName', 'test.dat')
                ->assertHasErrors(['request.sign'])
                ->assertSee(__('device-requests.validation.concurrent_prescribing'));
            $this->assertDatabaseCount('device_request_requests', 0);
            $this->assertFalse(Cache::lock('device-request-sign:'.$person->uuid.':'.$id, 600)->get());
        } finally {
            $lock->release();
        }
    }

    public static function requestPeriodLimits(): array
    {
        return [
            'no activity period' => [null, '2026-11-01', '2026-11-30', true],
            'open activity period' => [['start' => '2026-11-01'], '2026-11-01', '2026-11-30', true],
            'starts before activity' => [['start' => '2026-11-12'], '2026-11-01', '2026-11-30', false],
            'ends after activity' => [['start' => '2026-11-01', 'end' => '2026-11-12'], '2026-11-01', '2026-11-30', false],
            'starts before plan' => [null, '2026-11-12', '2026-11-30', false],
            'ends after plan' => [null, '2026-11-01', '2026-11-12', false],
        ];
    }

    #[DataProvider('requestPeriodLimits')]
    public function test_preparation_without_activity_period_still_checks_the_plan(?array $activityPeriod, string $planStart, string $planEnd, bool $allowed): void
    {
        $this->travelTo(now()->setDate(2026, 11, 11)->startOfDay());
        $person = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']);
        $entity = new LegalEntity(['uuid' => (string) Str::uuid()]);
        $employee = new Employee(['uuid' => (string) Str::uuid()]);
        $encounter = Encounter::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $person->id, 'status' => 'finished',
            'episode_id' => Identifier::create(['value' => (string) Str::uuid()])->id,
            'performer_id' => Identifier::create(['value' => $employee->uuid])->id,
            'class_id' => Coding::create(['code' => 'AMB', 'system' => 'eHealth/encounter_classes'])->id,
            'type_id' => CodeableConcept::create()->id, 'ehealth_inserted_at' => now(),
        ]);
        MedicalEventsRepository::period()->sync($encounter, ['start' => now()->toIso8601String(), 'end' => now()->toIso8601String()]);
        $planUuid = (string) Str::uuid();
        $activityUuid = (string) Str::uuid();
        $detail = ['product_codeable_concept' => ['coding' => [['code' => 'walker']]]];
        if ($activityPeriod !== null) {
            $detail['scheduled_period'] = $activityPeriod;
        }
        $service = Mockery::mock(DeviceRequestLifecycleService::class)->makePartial();
        $service->shouldReceive('authorizeAction')->andReturn($employee);
        $service->shouldReceive('encounter')->andReturn($encounter);
        $service->shouldReceive('prescribingOptions')->andReturn(['program' => [], 'packages' => [], 'parameters' => [], 'model' => []]);
        $service->shouldReceive('authMethods')->andReturn([]);
        $service->shouldReceive('carePlanContext')->with($person, $planUuid, $activityUuid, $employee->uuid)
            ->andReturn(['plan' => ['period' => ['start' => $planStart, 'end' => $planEnd]], 'detail' => $detail]);
        $data = ['uuid' => (string) Str::uuid(), 'encounter_uuid' => $encounter->uuid, 'device_id' => 'walker',
            'device_code_type' => 'CLASSIFICATION_TYPE', 'device_code_system' => 'assistive_devices', 'program_id' => '',
            'quantity' => null, 'quantity_code' => 'piece', 'started_at' => now()->addHour()->toIso8601String(),
            'ended_at' => now()->addDays(2)->toIso8601String(), 'phone_confirmed' => false,
            'parameter_values' => [], 'inform_with' => ''];
        if (!$allowed) {
            $this->expectException(ValidationException::class);
            $this->expectExceptionMessage(__('device-requests.validation.activity_period_limit'));
        }
        $prepared = $service->prepare($person, $entity, $data, $planUuid, $activityUuid);
        $this->assertSame($data['uuid'], $prepared['payload']['id']);
        $this->assertSame($activityUuid, data_get($prepared, 'payload.based_on.1.identifier.value'));
    }

    private function validatedResponse(array $data): EHealthResponse
    {
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn($data);

        return $response;
    }

    private function signingContext(): array
    {
        $this->withoutVite();
        $entity = LegalEntity::create(['uuid' => (string) Str::uuid(), 'status' => 'ACTIVE']);
        $this->instance('legalEntity', $entity);
        $person = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']);
        $this->actingAs(User::create(['email' => Str::random(12).'@example.com', 'password' => 'unused']));
        $this->mock(SignatureService::class)->shouldReceive('getCertificateAuthorities')->andReturn([]);

        return [$person, $entity];
    }
}

class DeviceRequestSigningHarness extends DeviceRequestForm
{
    public function mount(LegalEntity $legalEntity, Person $person): void
    {
        $this->legalEntityId = $legalEntity->id;
        $this->personId = $person->id;
        $this->requestUuid = (string) Str::uuid();
        $this->showSignatureModal = true;
        $this->request = ['device_code_type' => 'CLASSIFICATION_TYPE', 'device_id' => '', 'device_code_system' => '',
            'quantity' => null, 'quantity_code' => 'piece', 'program_id' => '', 'inform_with' => '',
            'phone_confirmed' => false, 'reason_reference' => [], 'parameter_values' => []];
    }
}

class DeviceRequestManufacturerHarness extends DeviceRequestSigningHarness
{
    public function mount(LegalEntity $legalEntity, Person $person, array $model = []): void
    {
        parent::mount($legalEntity, $person);
        $this->showSignatureModal = false;
        $this->options = ['model' => $model, 'program' => ['participant' => ['name' => 'Учасник програми']]];
    }
}

class DeviceRequestPlanHarness extends DeviceRequestSigningHarness
{
    public function mount(LegalEntity $legalEntity, Person $person, ?Encounter $encounter = null): void
    {
        parent::mount($legalEntity, $person);
        $this->showSignatureModal = false;
        $this->request['encounter_uuid'] = $encounter->uuid;
    }

    public function primeSigning(): void
    {
        $this->showSignatureModal = true;
    }
}

class DeviceApprovalActorCapture extends Component
{
    public static array $receivedActors = [];

    public string $actorUuid;

    public function mount(LegalEntity $legalEntity, CarePlan $carePlan, string $actingEmployeeUuid): void
    {
        $this->actorUuid = $actingEmployeeUuid;
        self::$receivedActors[] = $actingEmployeeUuid;
    }

    public function render(): string
    {
        return '<div data-approval-actor="'.$this->actorUuid.'"></div>';
    }
}
