<?php

declare(strict_types=1);

namespace Tests\Feature\MedicationRequest;

use App\Classes\eHealth\Api\MedicalProgram;
use App\Classes\eHealth\Api\Patient\DeviceRequest;
use App\Classes\eHealth\EHealthResponse;
use App\Classes\eHealth\Middleware\CheckSessionToken;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\EnsureSingleSession;
use App\Livewire\DeviceRequest\DeviceRequestForm;
use App\Livewire\DeviceRequest\DeviceRequestIndex;
use App\Livewire\EncounterPackage\EncounterPackageEdit;
use App\Livewire\MedicationRequest\MedicationRequestForm;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Coding;
use App\Models\MedicalEvents\Sql\DeviceRequestRequest;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\Person\Person;
use App\Models\Relations\Party;
use App\Models\User;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use App\Services\Dictionary\Dictionaries\MedicalProgramDictionary;

use App\Services\MedicalEvents\DeviceRequestLifecycleService;
use App\Services\MedicalEvents\EHealthJobResolver;
use App\Services\MedicalEvents\MedicationRequestLifecycleService;
use App\Services\SignatureService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\MocksBasicDictionaries;
use Tests\Support\NativeClinicalFixtures as Native;
use Tests\TestCase;

/**
 * The standalone eRx / device-request forms used to submit `base64(json)` as if it were a
 * qualified electronic signature, and told the doctor it had been signed with a KEP.
 * These tests pin the corrected behaviour.
 */
class StandaloneRequestSigningTest extends TestCase
{
    use DatabaseTransactions;
    use MocksBasicDictionaries;

    protected User $user;

    protected LegalEntity $legalEntity;

    protected SignatureService $signature;

    protected function migrateDatabases(): void
    {
        $this->artisan('migrate:fresh', [
            '--path' => [
                database_path('migrations'),
                database_path('migrations/install'),
                database_path('migrations/update/0_1'),
            ],
            '--realpath' => true,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement("SET LOCAL TIME ZONE 'UTC'");
        $this->travelTo(CarbonImmutable::parse('2026-10-29 23:30:00', (string) config('app.timezone')));
        $this->mockBasicDictionaries(['device_unit' => ['piece' => 'Штука', 'pair' => 'Пара']]);
        Cache::put(MedicalProgramDictionary::KEY, [], now()->addDay());
        Cache::put(MedicalProgramDictionary::KEY.':fresh', true, now()->addDay());

        $party = Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Іван',
            'last_name' => 'Петренко',
            'tax_id' => '9876543210',
            'birth_date' => '1980-08-08',
            'gender' => 'MALE',
        ]);

        $this->user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'sign_' . Str::random(6) . '@example.com',
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $typeId = DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        $this->legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);
        $this->instance('legalEntity', $this->legalEntity);

        $employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Д-р Іван Петренко',
            'employee_type' => 'DOCTOR',
            'status' => 'APPROVED',
            'legal_entity_id' => $this->legalEntity->id,
            'is_active' => true,
            'position' => 'Doctor',
            'start_date' => '2026-08-01',
            'user_id' => $this->user->id,
            'party_id' => $party->id,
        ]);
        $this->user->employees()->attach($employee->id);

        $this->actingAs($this->user);
        $this->grantMedicalEventAbilities($this->user);

        $this->signature = Mockery::mock(SignatureService::class);
        $this->signature->shouldReceive('getCertificateAuthorities')->andReturn([
            ['id' => 'knedp-1', 'name' => 'Тестовий КНЕДП'],
        ]);
        $this->app->instance(SignatureService::class, $this->signature);
    }

    public function test_prescription_is_signed_with_the_kep_over_the_ehealth_draft_content(): void
    {
        $draftId = (string) Str::uuid();
        $draftContent = ['id' => $draftId, 'status' => 'NEW', 'medication_id' => (string) Str::uuid()];

        $lifecycle = Mockery::mock(MedicationRequestLifecycleService::class);
        $lifecycle->shouldReceive('createDraft')->once()->andReturn($draftContent);
        $lifecycle->shouldReceive('sign')
            ->once()
            ->withArgs(static function (string $id, array $payload) use ($draftId): bool {
                return $id === $draftId
                    && $payload['signed_medication_request_request'] === 'REAL-KEP-SIGNATURE'
                    && $payload['signed_content_encoding'] === 'base64';
            })
            ->andReturn([]);
        $this->app->instance(MedicationRequestLifecycleService::class, $lifecycle);

        $this->signature->shouldReceive('signData')
            ->once()
            ->withArgs(static function (array $data, string $password, string $knedp, $keyFile, string $taxId) use ($draftContent): bool {
                return $data === $draftContent
                    && $password === 'secret'
                    && $knedp === 'knedp-1'
                    && $keyFile instanceof UploadedFile
                    && $taxId === '9876543210';
            })
            ->andReturn('REAL-KEP-SIGNATURE');

        Livewire::test(MedicationRequestForm::class, ['legalEntity' => $this->legalEntity])
            ->set('patientId', (string) Str::uuid())
            ->set('medicalProgram', (string) Str::uuid())
            ->set('dosageInstruction', 'Take 1 pill')
            ->set('duration', '30')
            ->call('createDraft')
            ->assertSet('isDraftCreated', true)
            ->assertSet('draftId', $draftId)
            ->set('form.knedp', 'knedp-1')
            ->set('form.keyContainerUpload', UploadedFile::fake()->create('key.dat', 10))
            ->set('form.password', 'secret')
            ->call('sign')
            ->assertSet('showSignatureModal', false)
            ->assertHasNoErrors();
    }

    public function test_prescription_cannot_be_signed_without_kep_credentials(): void
    {
        $draftId = (string) Str::uuid();

        $lifecycle = Mockery::mock(MedicationRequestLifecycleService::class);
        $lifecycle->shouldReceive('createDraft')->once()->andReturn(['id' => $draftId]);
        $lifecycle->shouldNotReceive('sign');
        $this->app->instance(MedicationRequestLifecycleService::class, $lifecycle);

        $this->signature->shouldNotReceive('signData');

        Livewire::test(MedicationRequestForm::class, ['legalEntity' => $this->legalEntity])
            ->set('patientId', (string) Str::uuid())
            ->set('medicalProgram', (string) Str::uuid())
            ->set('dosageInstruction', 'Take 1 pill')
            ->set('duration', '30')
            ->call('createDraft')
            ->call('sign')
            ->assertHasErrors(['form.knedp', 'form.keyContainerUpload', 'form.password']);
    }

    public function test_prescription_signing_is_refused_before_a_draft_exists(): void
    {
        $lifecycle = Mockery::mock(MedicationRequestLifecycleService::class);
        $lifecycle->shouldNotReceive('sign');
        $this->app->instance(MedicationRequestLifecycleService::class, $lifecycle);

        Livewire::test(MedicationRequestForm::class, ['legalEntity' => $this->legalEntity])
            ->call('sign')
            ->assertSet('showSignatureModal', false);
    }

    public function test_draft_without_an_identifier_is_not_treated_as_created(): void
    {
        $lifecycle = Mockery::mock(MedicationRequestLifecycleService::class);
        $lifecycle->shouldReceive('createDraft')->once()->andReturn(['status' => 'NEW']);
        $this->app->instance(MedicationRequestLifecycleService::class, $lifecycle);

        Livewire::test(MedicationRequestForm::class, ['legalEntity' => $this->legalEntity])
            ->set('patientId', (string) Str::uuid())
            ->set('medicalProgram', (string) Str::uuid())
            ->set('dosageInstruction', 'Take 1 pill')
            ->set('duration', '30')
            ->call('createDraft')
            ->assertSet('isDraftCreated', false)
            ->assertSet('draftId', null);
    }

    public function test_device_request_signs_the_persisted_draft_after_fresh_validation(): void
    {
        $person = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']);
        $employee = $this->user->employees()->firstOrFail();
        $payload = [];
        $lifecycle = Mockery::mock(DeviceRequestLifecycleService::class);
        $lifecycle->shouldReceive('authorizeAction')->andReturn($employee);
        $lifecycle->shouldReceive('collectPages')->andReturn([]);
        $lifecycle->shouldReceive('authMethods')->andReturn([]);
        $lifecycle->shouldReceive('permittedTypes')->andReturn([]);
        $lifecycle->shouldReceive('prepare')->twice()
            ->withArgs(static fn ($patient, $entity, array $data): bool => $patient->id === $person->id
                && (int) $data['quantity'] === 2)
            ->andReturnUsing(static function ($patient, $entity, array $data) use ($employee, &$payload): array {
                $payload = ['id' => $data['uuid'], 'quantity' => ['value' => 2], 'status' => 'active'];

                return ['data' => $data, 'employee' => $employee, 'payload' => $payload, 'authType' => 'OFFLINE'];
            });
        $lifecycle->shouldReceive('details')->once()->andReturnUsing(static function () use (&$payload): array {
            return ['id' => $payload['id'], 'status' => 'active', 'requisition' => 'ABCD-1234-5678-9012'];
        });
        $lifecycle->shouldReceive('sync')->once()->andReturn(new DeviceRequestRequest());
        $lifecycle->shouldReceive('createdMessage')->once()->andReturn('Створено');
        $this->app->instance(DeviceRequestLifecycleService::class, $lifecycle);

        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn(['status' => 'pending', 'links' => [['entity' => 'job', 'href' => '/api/jobs/test-job']]]);
        $resolver = Mockery::mock(EHealthJobResolver::class)->makePartial();
        $resolver->shouldReceive('resolve')->once()->andReturn(['status' => 'processed']);
        $this->instance(EHealthJobResolver::class, $resolver);
        $api = Mockery::mock(DeviceRequest::class);
        $api->shouldReceive('createSigned')->once()
            ->with($person->uuid, ['signed_data' => 'REAL-KEP-SIGNATURE', 'signed_data_encoding' => 'base64'])
            ->andReturn($response);
        $this->app->instance(DeviceRequest::class, $api);
        $this->signature->shouldReceive('signData')->once()
            ->withArgs(static fn (array $content): bool => $content['quantity']['value'] === 2)
            ->andReturn('REAL-KEP-SIGNATURE');

        $component = Livewire::test(DeviceRequestForm::class, ['legalEntity' => $this->legalEntity, 'person' => $person])
            ->set('request.device_id', 'device-code')
            ->set('request.quantity', 2)
            ->call('createDraft')
            ->assertHasNoErrors()
        ;
        $this->assertNotNull($component->get('draftId'), strip_tags($component->html()));
        $component
            // A modified browser field cannot replace the persisted draft during KEP submission.
            ->set('request.quantity', 999)
            ->set('form.knedp', 'knedp-1')
            ->set('form.keyContainerUpload', UploadedFile::fake()->create('key.dat', 10))
            ->set('form.password', 'secret')
            ->call('sign')
            ->assertSet('showSignatureModal', false)
            ->assertHasNoErrors();
    }

    private function createDeviceEncounter(Person $person, Employee $employee): Encounter
    {
        $encounter = Encounter::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $person->id, 'status' => 'finished',
            'episode_id' => Identifier::create(['value' => (string) Str::uuid()])->id,
            'performer_id' => Identifier::create(['value' => $employee->uuid])->id,
            'class_id' => Coding::create(['code' => 'AMB', 'system' => 'eHealth/encounter_classes'])->id,
            'type_id' => CodeableConcept::create()->id, 'ehealth_inserted_at' => now(),
        ]);
        MedicalEventsRepository::period()->sync($encounter, [
            'start' => now()->subHour()->utc()->toIso8601String(),
            'end' => now()->utc()->toIso8601String(),
        ]);

        return $encounter;
    }

    #[DataProvider('encounterLocalTimes')]
    public function testDeviceFormUsesLocalEncounterIdAndCachedDevicePrograms(string $localTime): void
    {
        $this->travelTo(CarbonImmutable::parse($localTime, config('app.timezone')));
        $employee = $this->user->employees()->firstOrFail();
        $person = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']);
        $encounter = $this->createDeviceEncounter($person, $employee);
        $deviceProgram = ['id' => (string) Str::uuid(), 'name' => 'Вироби', 'type' => 'DEVICE', 'is_active' => true];
        Cache::put(MedicalProgramDictionary::KEY, [$deviceProgram, ['id' => (string) Str::uuid(), 'name' => 'Неактивна', 'type' => 'DEVICE', 'is_active' => false],
            ['id' => (string) Str::uuid(), 'name' => 'Ліки', 'type' => 'MEDICATION', 'is_active' => true]], now()->addDay());
        $api = Mockery::mock(MedicalProgram::class);
        $api->shouldNotReceive('getMany');
        $this->instance(MedicalProgram::class, $api);
        $service = Mockery::mock(DeviceRequestLifecycleService::class);
        $service->shouldReceive('authorizeAction')->andReturn($employee);
        $service->shouldReceive('encounter')->once()->withArgs(fn ($patient, $actor, string $uuid): bool =>
            $patient->id === $person->id && $actor->id === $employee->id && $uuid === $encounter->uuid)->andReturn($encounter);
        $service->shouldReceive('authMethods')->andReturn([]);
        $service->shouldReceive('permittedTypes')->andReturn([]);
        $this->instance(DeviceRequestLifecycleService::class, $service);
        Livewire::withQueryParams(['encounter' => $encounter->id])->test(DeviceRequestForm::class, ['legalEntity' => $this->legalEntity, 'person' => $person])
            ->assertHasNoErrors()
            ->assertSet('request.encounter_uuid', $encounter->uuid)
            ->assertSet('programs', [$deviceProgram]);
    }

    public static function encounterLocalTimes(): array
    {
        return [
            'daytime' => ['2026-10-27 12:30:00'],
            'late evening' => ['2026-10-27 23:30:00'],
            'after midnight' => ['2026-10-28 00:30:00'],
            'summer offset' => ['2026-08-13 23:30:00'],
        ];
    }

    public function test_device_form_translates_and_displays_errors_at_fields_and_resets_changed_reason(): void
    {
        $this->mockBasicDictionaries(['device_unit' => ['piece' => 'Штука']]);
        $employee = $this->user->employees()->firstOrFail();
        $person = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']);
        $this->createDeviceEncounter($person, $employee);
        $service = Mockery::mock(DeviceRequestLifecycleService::class)->makePartial();
        $service->shouldReceive('authorizeAction')->andReturn($employee);
        $service->shouldReceive('authMethods')->andReturn([['id' => (string) Str::uuid(), 'type' => 'THIRD_PERSON']]);
        $service->shouldReceive('permittedTypes')->andReturn([]);
        $service->shouldReceive('prescribingOptions')->andReturn(['packages' => [], 'parameters' => []]);
        $this->instance(DeviceRequestLifecycleService::class, $service);

        Livewire::test(DeviceRequestForm::class, ['legalEntity' => $this->legalEntity, 'person' => $person])
            ->assertSee('Через законного представника')->assertDontSee('THIRD_PERSON')
            ->assertSee('datepicker-input', false)->assertDontSee('type="date"', false)
            ->call('addReason')->set('request.reason_reference.0.uuid', (string) Str::uuid())
            ->set('request.reason_reference.0.type', 'observation')->assertSet('request.reason_reference.0.uuid', '')
            ->call('createDraft')->assertHasErrors(['request.device_id', 'request.reason_reference.0.uuid'])
            ->assertSee('медичний виріб')->assertSee('медичний запис причини призначення')
            ->assertDontSee("Поле 'device_id'")->assertDontSee("Поле 'reason_reference.0.uuid'")
            ->call('removeReason', 0)->assertSet('request.reason_reference', [])
            ->call('selectType', '')->assertSet('request.device_id', '')
            ->set('request.device_code_type', 'DEVICE_DEFINITION')->call('selectModel', '')
            ->assertSet('request.device_id', '')->call('selectQuantity', '')->assertSet('request.quantity', null);
    }

    public function test_device_form_rejects_encounter_from_another_patient(): void
    {
        $employee = $this->user->employees()->firstOrFail();
        $person = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']);
        $otherPerson = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'FEMALE']);
        $this->createDeviceEncounter($person, $employee);
        $otherEncounter = $this->createDeviceEncounter($otherPerson, $employee);
        $service = Mockery::mock(DeviceRequestLifecycleService::class);
        $service->shouldReceive('authorizeAction')->andReturn($employee);
        $service->shouldNotReceive('encounter');
        $this->instance(DeviceRequestLifecycleService::class, $service);
        Livewire::withQueryParams(['encounter' => $otherEncounter->id])->test(DeviceRequestForm::class, ['legalEntity' => $this->legalEntity, 'person' => $person])
            ->assertSet('request.encounter_uuid', '')
            ->assertHasErrors(['request.encounter_uuid']);
    }

    public function test_device_form_requires_local_id_instead_of_uuid_in_query(): void
    {
        $employee = $this->user->employees()->firstOrFail();
        $person = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']);
        $encounter = $this->createDeviceEncounter($person, $employee);
        $service = Mockery::mock(DeviceRequestLifecycleService::class);
        $service->shouldReceive('authorizeAction')->andReturn($employee);
        $service->shouldNotReceive('encounter');
        $this->instance(DeviceRequestLifecycleService::class, $service);
        Livewire::withQueryParams(['encounter' => $encounter->uuid])->test(DeviceRequestForm::class, ['legalEntity' => $this->legalEntity, 'person' => $person])
            ->assertSet('request.encounter_uuid', '')
            ->assertHasErrors(['request.encounter_uuid']);
    }

    public function test_encounter_device_request_link_uses_local_id(): void
    {
        $employee = $this->user->employees()->firstOrFail();
        $person = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']);
        $encounter = $this->createDeviceEncounter($person, $employee);
        $service = Mockery::mock(DeviceRequestLifecycleService::class);
        $service->shouldReceive('authorizeAction')->andReturn($employee);
        $service->shouldReceive('encounter')->once()->withArgs(fn ($patient, $actor, string $uuid): bool =>
            $patient->id === $person->id && $uuid === $encounter->uuid)->andReturn($encounter);
        $this->instance(DeviceRequestLifecycleService::class, $service);
        $component = new DeviceEncounterLinkHarness();
        $component->personId = $person->id;
        $component->encounterId = $encounter->id;
        $component->encounterUuid = $encounter->uuid;
        $component->openDeviceRequest();
        $this->assertSame(route('persons.device-requests.create', ['legalEntity' => $this->legalEntity, 'person' => $person, 'encounter' => $encounter->id]), $component->redirectedUrl);
    }

    public function test_assistant_with_write_scope_cannot_prescribe_devices(): void
    {
        $this->grantMedicalEventAbilities($this->user);
        $employee = $this->user->employees()->firstOrFail();
        $employee->employeeType = 'ASSISTANT';
        $employee->save();
        $this->expectException(AuthorizationException::class);
        app(DeviceRequestLifecycleService::class)->authorizeAction($this->legalEntity, 'device_request:write');
    }

    public function test_encounter_actions_keep_device_prescribing_and_composition_link_after_ui_update(): void
    {
        $person = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']);
        $employee = $this->user->employees()->firstOrFail();
        $encounter = $this->createDeviceEncounter($person, $employee);

        Livewire::test(DeviceEncounterActionsHarness::class, ['personId' => $person->id, 'encounterId' => $encounter->id])
            ->assertSeeHtml('wire:click="openDeviceRequest"')
            ->assertSeeHtml('href="/review/composition/create"')
            ->assertSee(__('device-requests.add_request'));
    }

    public function test_encounter_actions_keep_patient_registry_context_for_readers_and_disable_unavailable_composition(): void
    {
        $employee = $this->user->employees()->firstOrFail();
        $employee->update(['employee_type' => 'ASSISTANT']);
        $person = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']);

        Livewire::test(DeviceEncounterActionsHarness::class, ['personId' => $person->id, 'compositionUrl' => null])
            ->assertDontSeeHtml('wire:click="openDeviceRequest"')
            ->assertSeeHtml('href="'.route('persons.device-requests', ['legalEntity' => $this->legalEntity, 'person' => $person->id]).'"')
            ->assertSeeHtml('disabled')
            ->assertSee(__('encounters.medical_report_creation_unavailable'));
    }

    public function test_encounter_actions_disable_device_requests_for_preperson_without_route_error(): void
    {
        Livewire::test(DeviceEncounterActionsHarness::class, ['personId' => null, 'compositionUrl' => null])
            ->assertStatus(200)
            ->assertDontSeeHtml('wire:click="openDeviceRequest"')
            ->assertSeeHtml('title="'.e(__('encounters.device_requests_unavailable')).'"')
            ->assertSee(__('encounters.open_device_requests'));
    }

    public function test_device_policy_requires_matching_team_scope_and_active_prescriber(): void
    {
        $model = DeviceRequestRequest::class;
        $this->assertTrue($this->user->can('create', $model));
        $this->assertTrue($this->user->can('viewAny', $model));
        $this->assertFalse($this->user->can('perform', [$model, 'unrelated:write']));
        $this->assertFalse($this->user->can('perform', [$model, 'device_request:revoke']));
        $this->assertSame(404, Gate::inspect('perform', [$model, 'device_request:revoke'])->status());
        setPermissionsTeamId($this->legalEntity->id + 1000);
        $this->assertFalse($this->user->can('create', $model));
        $this->assertFalse($this->user->can('viewAny', $model));
        setPermissionsTeamId($this->legalEntity->id);
        $this->user->employees()->firstOrFail()->update(['is_active' => false]);
        $this->assertFalse($this->user->can('create', $model));
    }

    public function testDevicePolicyDeniesRequestsWithoutLegalEntityContext(): void
    {
        $this->app->forgetInstance('legalEntity');
        $model = DeviceRequestRequest::class;
        $this->assertFalse($this->user->can('viewAny', $model));
        $this->assertFalse($this->user->can('create', $model));
        $this->assertSame(404, Gate::inspect('viewAny', $model)->status());
    }

    public static function deniedDeviceRoutes(): array
    {
        return [
            ['device-requests.index', 'device_request:read'],
            ['persons.device-requests', 'device_request:read'],
            ['persons.device-requests.view', 'device_request:read'],
            ['persons.device-requests.create', 'device_request:write'],
        ];
    }

    #[DataProvider('deniedDeviceRoutes')]
    public function testDeviceRouteRejectsMissingPermissionBeforeMountAndApi(string $routeName, string $scope): void
    {
        $this->withoutVite();
        $this->withoutMiddleware([
            Authenticate::class,
            EnsureEmailIsVerified::class,
            EnsureSingleSession::class,
            CheckSessionToken::class,
        ]);
        $this->grantMedicalEventAbilities($this->user, ['patient_summary:read']);
        $this->user->revokePermissionTo($scope);
        $this->user->unsetRelation('permissions')->unsetRelation('roles');
        $this->assertTrue($this->user->can('access', $this->legalEntity));
        $this->assertTrue($this->user->can('view', Person::class));
        $this->assertFalse($this->user->can($scope));
        $api = Mockery::mock(DeviceRequest::class);
        $api->shouldNotReceive('getBySearchParams');
        $this->instance(DeviceRequest::class, $api);
        $component = Mockery::mock(DeviceRequestIndex::class)->makePartial();
        $component->shouldNotReceive('mount');
        $this->instance(DeviceRequestIndex::class, $component);
        $form = Mockery::mock(DeviceRequestForm::class)->makePartial();
        $form->shouldNotReceive('mount');
        $this->instance(DeviceRequestForm::class, $form);
        $person = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']);
        $parameters = ['legalEntity' => $this->legalEntity];
        if ($routeName !== 'device-requests.index') {
            $parameters['person'] = $person;
        }

        if ($routeName === 'persons.device-requests.view') {
            $parameters['deviceRequest'] = (string) Str::uuid();
        }

        $this->get(route($routeName, $parameters))->assertNotFound();
    }

    public function testDeviceAuthorizationRejectsAnotherEntityArgumentDespiteCurrentEntityPermission(): void
    {
        $otherEntity = LegalEntity::create(['uuid' => (string) Str::uuid(), 'status' => 'ACTIVE']);
        $this->assertTrue($this->user->can('create', DeviceRequestRequest::class));
        $this->expectException(HttpException::class);
        app(DeviceRequestLifecycleService::class)->authorizeAction($otherEntity, 'device_request:write');
    }

    public function testDeviceFormActionsStopWhenPrescriberPermissionIsRevoked(): void
    {
        $this->withoutVite();
        $person = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']);
        $service = Mockery::mock(DeviceRequestLifecycleService::class)->makePartial();
        $service->shouldReceive('authMethods')->once()->andReturn([]);
        $service->shouldReceive('permittedTypes')->once()->andReturn([]);
        $service->shouldNotReceive('collectPages');
        $service->shouldNotReceive('prescribingOptions');
        $this->instance(DeviceRequestLifecycleService::class, $service);
        $component = Livewire::test(DeviceRequestForm::class, ['legalEntity' => $this->legalEntity, 'person' => $person]);
        $this->user->employees()->firstOrFail()->update(['is_active' => false]);

        foreach (['searchModels', 'refreshOptions', 'confirmPhone'] as $action) {
            $component->call($action)->assertStatus(200)
                ->assertSee(__('device-requests.policy.create'))
                ->assertSet('request.phone_confirmed', false)
                ->assertSet('models', [])->assertSet('options', []);
        }
    }

    public function test_assistant_with_extra_write_scope_can_still_open_device_registry(): void
    {
        $this->grantMedicalEventAbilities($this->user);
        $employee = $this->user->employees()->firstOrFail();
        $employee->employeeType = 'ASSISTANT';
        $employee->save();
        $person = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']);
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->andReturn([]);
        $response->shouldReceive('getPaging')->andReturn(['total_pages' => 1]);
        $api = Mockery::mock(DeviceRequest::class);
        $api->shouldReceive('getBySearchParams')->twice()
            ->with($person->uuid, ['requester_legal_entity' => $this->legalEntity->uuid, 'page' => 1])->andReturn($response);
        $this->instance(DeviceRequest::class, $api);
        Livewire::test(DeviceRequestIndex::class, ['legalEntity' => $this->legalEntity, 'person' => $person])
            ->assertSet('legalEntityId', $this->legalEntity->id)
            ->assertSet('personId', $person->id)
            ->assertSet('filters.parameters.requester_legal_entity', $this->legalEntity->uuid)
            ->call('search', false)
            ->assertSet('drafts', [])
            ->assertHasNoErrors();
    }

    public function test_device_registry_patient_cannot_be_changed_by_livewire_payload(): void
    {
        $component = Livewire::test(DeviceRequestIndex::class, ['legalEntity' => $this->legalEntity]);

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $component->set('personId', 999);
    }

    public function test_device_registry_renders_raw_api_search_results_and_opens_details_by_resource_id(): void
    {
        $person = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']);
        $requestId = (string) Str::uuid();
        $record = Native::deviceRequest($requestId, $person->uuid);
        $service = Mockery::mock(DeviceRequestLifecycleService::class)->makePartial();
        $service->shouldReceive('dictionary')->andReturn([]);
        $service->shouldReceive('collectPages')->andReturn([]);
        $this->instance(DeviceRequestLifecycleService::class, $service);
        config(['ehealth.api.domain' => 'https://ehealth.example']);
        Http::fake(function (Request $request) use ($person, $record, $requestId) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/api/patients/'.$person->uuid.'/device_requests') {
                return Http::response(['data' => [$record], 'paging' => Native::paging()], 200);
            }
            if ($path === '/api/patients/'.$person->uuid.'/device_requests/'.$requestId) {
                return Http::response(['data' => $record], 200);
            }

            throw new RuntimeException('Unexpected eHealth request: '.$path);
        });
        $factory = Http::getFacadeRoot();
        $this->app->bind(DeviceRequest::class, static function () use ($factory) {
            $api = new DeviceRequest($factory);
            $api->stub((function () {
                return $this->stubCallbacks;
            })->call($factory));
            $api->preventStrayRequests();

            return $api;
        });

        Livewire::test(DeviceRequestIndex::class, ['legalEntity' => $this->legalEntity, 'person' => $person])
            ->assertSet('requests.0.id', $requestId)
            ->assertSee($record['requisition'])
            ->assertSeeHtml("wire:click=\"showDetails('{$requestId}')\"")
            ->call('showDetails', $requestId)
            ->assertSet('selectedId', $requestId)
            ->assertHasNoErrors();

        Http::assertSentCount(2);
        Http::assertSent(static fn (Request $request): bool =>
            $request->method() === 'GET' && parse_url($request->url(), PHP_URL_PATH) === '/api/patients/'.$person->uuid.'/device_requests/'.$requestId);
    }

    public function test_device_registry_entity_cannot_be_changed_by_livewire_payload(): void
    {
        $component = Livewire::test(DeviceRequestIndex::class, ['legalEntity' => $this->legalEntity]);

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $component->set('legalEntityId', $this->legalEntity->id + 1);
    }

    public function test_device_registry_rechecks_policy_on_livewire_actions(): void
    {
        $this->withoutVite();

        $component = Livewire::test(DeviceRequestIndex::class, ['legalEntity' => $this->legalEntity]);
        $this->user->employees()->firstOrFail()->update(['is_active' => false]);

        $component->call('searchPatients')->assertStatus(200)
            ->assertSee(__('device-requests.policy.perform'));
    }

    public function test_doctor_can_write_only_in_the_current_permission_team(): void
    {
        $this->grantMedicalEventAbilities($this->user);
        $service = app(DeviceRequestLifecycleService::class);
        $this->assertSame($this->user->employees()->firstOrFail()->id, $service->authorizeAction($this->legalEntity, 'device_request:write')->id);
        setPermissionsTeamId($this->legalEntity->id + 1000);
        $this->expectException(AuthorizationException::class);
        $service->authorizeAction($this->legalEntity, 'device_request:write');
    }

    public function test_resumed_device_draft_restores_html_dates_and_requires_phone_confirmation_again(): void
    {
        $person = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']);
        $employee = $this->user->employees()->firstOrFail();
        $draftId = (string) Str::uuid();
        DeviceRequestRequest::create([
            'uuid' => $draftId, 'person_id' => $person->id, 'employee_id' => $employee->id, 'device_id' => '', 'status' => 'draft', 'quantity' => null,
            'request_payload' => ['data' => ['device_id' => '', 'device_code_type' => 'CLASSIFICATION_TYPE', 'device_code_system' => '', 'program_id' => '', 'quantity' => null, 'quantity_code' => 'piece',
                'started_at' => now()->toIso8601String(), 'ended_at' => now()->addDays(5)->toIso8601String(), 'encounter_uuid' => '',
                'inform_with' => '', 'phone_confirmed' => true, 'confirmed_phone' => '+380***1234', 'parameter_values' => [], 'reason_reference' => []],
                'carePlanUuid' => null, 'activityUuid' => null],
        ]);
        $service = Mockery::mock(DeviceRequestLifecycleService::class);
        $service->shouldReceive('authorizeAction')->andReturn($employee);
        $service->shouldReceive('collectPages')->andReturn([]);
        $service->shouldReceive('authMethods')->andReturn([]);
        $service->shouldReceive('permittedTypes')->andReturn([]);
        $this->instance(DeviceRequestLifecycleService::class, $service);
        Livewire::withQueryParams(['draft' => $draftId])->test(DeviceRequestForm::class, ['legalEntity' => $this->legalEntity, 'person' => $person])
            ->assertSet('draftId', $draftId)
            ->assertSet('request.started_at', today()->format('d.m.Y'))
            ->assertSet('request.ended_at', today()->addDays(5)->format('d.m.Y'))
            ->assertSet('request.phone_confirmed', false)
            ->assertSet('request.confirmed_phone', '');
    }
}

class DeviceEncounterLinkHarness extends EncounterPackageEdit
{
    public string $redirectedUrl = '';

    public function redirect($url, $navigate = false): void
    {
        $this->redirectedUrl = (string) $url;
    }
}

class DeviceEncounterActionsHarness extends Component
{
    public ?int $prepersonId = null;

    public ?int $personId = null;

    public int $encounterId = 17;

    public bool $isFinished = true;

    public ?string $compositionUrl = '/review/composition/create';

    #[Computed]
    public function createCompositionFromEncounterUrl(): ?string
    {
        return $this->compositionUrl;
    }

    public function render()
    {
        return view('livewire.encounter-package.parts.additional-actions', [
            'isReadonly' => true,
            'footerItems' => [
                ['id' => 'medical-reports', 'label' => __('patients.medical_reports'), 'icon' => 'book-open'],
                ['id' => 'device-requests', 'label' => __('encounters.device_requests'), 'icon' => 'equipment'],
            ],
        ]);
    }
}
