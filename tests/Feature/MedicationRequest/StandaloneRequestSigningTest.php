<?php

declare(strict_types=1);

namespace Tests\Feature\MedicationRequest;

use App\Dto\MedicationRequest\DraftResult;

use App\Classes\eHealth\Api\Patient\MedicationRequest as MedicationRequestApi;

use App\Exceptions\EHealth\EHealthValidationException;

use App\Dto\DeviceRequest\DraftResult as DeviceDraftResult;

use App\Classes\eHealth\EHealthResponse;

use App\Classes\eHealth\Api\Job;

use App\Classes\eHealth\Api\DeviceRequest as DeviceRequestApi;

use App\Livewire\DeviceRequest\DeviceRequestForm;
use App\Livewire\MedicationRequest\MedicationRequestForm;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\Relations\Party;
use App\Models\User;
use App\Services\MedicalEvents\DeviceRequestLifecycleService;
use App\Services\SignatureService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use App\Services\Dictionary\Dictionaries\MedicalProgramDictionary;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use Tests\Concerns\MocksBasicDictionaries;
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
            'start_date' => now()->format('Y-m-d'),
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

        $lifecycle = Mockery::mock(MedicationRequestApi::class);
        $lifecycle->shouldReceive('createAndResolve')->once()->andReturn(new DraftResult($draftContent, $draftContent));
        $lifecycle->shouldReceive('signAndResolve')
            ->once()
            ->withArgs(static function (string $id, array $payload) use ($draftId): bool {
                return $id === $draftId
                    && $payload['signed_medication_request_request'] === 'REAL-KEP-SIGNATURE'
                    && $payload['signed_content_encoding'] === 'base64';
            })
            ->andReturn([]);
        $this->app->instance(MedicationRequestApi::class, $lifecycle);

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

        $lifecycle = Mockery::mock(MedicationRequestApi::class);
        $lifecycle->shouldReceive('createAndResolve')->once()->andReturn(new DraftResult(['id' => $draftId], ['id' => $draftId]));
        $lifecycle->shouldNotReceive('signAndResolve');
        $this->app->instance(MedicationRequestApi::class, $lifecycle);

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
        $lifecycle = Mockery::mock(MedicationRequestApi::class);
        $lifecycle->shouldNotReceive('signAndResolve');
        $this->app->instance(MedicationRequestApi::class, $lifecycle);

        Livewire::test(MedicationRequestForm::class, ['legalEntity' => $this->legalEntity])
            ->call('sign')
            ->assertSet('showSignatureModal', false);
    }

    public function test_draft_without_an_identifier_is_not_treated_as_created(): void
    {
        $lifecycle = Mockery::mock(MedicationRequestApi::class);
        $lifecycle->shouldReceive('createAndResolve')->once()->andReturn(new DraftResult(['status' => 'NEW'], ['status' => 'NEW']));
        $this->app->instance(MedicationRequestApi::class, $lifecycle);

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
        $person = \App\Models\Person\Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']);
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
        $lifecycle->shouldReceive('sync')->once()->andReturn(new \App\Models\MedicalEvents\Sql\DeviceRequestRequest());
        $lifecycle->shouldReceive('createdMessage')->once()->andReturn('Створено');
        $this->app->instance(DeviceRequestLifecycleService::class, $lifecycle);

        $response = Mockery::mock(\App\Classes\eHealth\EHealthResponse::class);
        $response->shouldReceive('getData')->andReturn(['status' => 'active']);
        $api = Mockery::mock(\App\Classes\eHealth\Api\Patient\DeviceRequest::class);
        $api->shouldReceive('createSigned')->once()
            ->with($person->uuid, ['signed_data' => 'REAL-KEP-SIGNATURE', 'signed_data_encoding' => 'base64'])
            ->andReturn($response);
        $this->app->instance(\App\Classes\eHealth\Api\Patient\DeviceRequest::class, $api);
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

    private function createDeviceEncounter(\App\Models\Person\Person $person, Employee $employee): \App\Models\MedicalEvents\Sql\Encounter
    {
        $encounter = \App\Models\MedicalEvents\Sql\Encounter::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $person->id, 'status' => 'finished',
            'episode_id' => \App\Models\MedicalEvents\Sql\Identifier::create(['value' => (string) Str::uuid()])->id,
            'performer_id' => \App\Models\MedicalEvents\Sql\Identifier::create(['value' => $employee->uuid])->id,
            'class_id' => \App\Models\MedicalEvents\Sql\Coding::create(['code' => 'AMB', 'system' => 'eHealth/encounter_classes'])->id,
            'type_id' => \App\Models\MedicalEvents\Sql\CodeableConcept::create()->id, 'ehealth_inserted_at' => now(),
        ]);
        $encounter->period()->create(['start' => now()->subHour()->toIso8601String(), 'end' => now()->toIso8601String()]);

        return $encounter;
    }

    public function test_device_form_uses_local_encounter_id_and_cached_device_programs(): void
    {
        $employee = $this->user->employees()->firstOrFail();
        $person = \App\Models\Person\Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']);
        $encounter = $this->createDeviceEncounter($person, $employee);
        $deviceProgram = ['id' => (string) Str::uuid(), 'name' => 'Вироби', 'type' => 'DEVICE', 'is_active' => true];
        Cache::put(MedicalProgramDictionary::KEY, [$deviceProgram, ['id' => (string) Str::uuid(), 'name' => 'Неактивна', 'type' => 'DEVICE', 'is_active' => false],
            ['id' => (string) Str::uuid(), 'name' => 'Ліки', 'type' => 'MEDICATION', 'is_active' => true]], now()->addDay());
        $api = Mockery::mock(\App\Classes\eHealth\Api\MedicalProgram::class);
        $api->shouldNotReceive('getMany');
        $this->instance(\App\Classes\eHealth\Api\MedicalProgram::class, $api);
        $service = Mockery::mock(DeviceRequestLifecycleService::class);
        $service->shouldReceive('authorizeAction')->andReturn($employee);
        $service->shouldReceive('encounter')->once()->withArgs(fn ($patient, $actor, string $uuid): bool =>
            $patient->id === $person->id && $actor->id === $employee->id && $uuid === $encounter->uuid)->andReturn($encounter);
        $service->shouldReceive('authMethods')->andReturn([]);
        $service->shouldReceive('permittedTypes')->andReturn([]);
        $this->instance(DeviceRequestLifecycleService::class, $service);
        Livewire::withQueryParams(['encounter' => $encounter->id])->test(DeviceRequestForm::class, ['legalEntity' => $this->legalEntity, 'person' => $person])
            ->assertSet('request.encounter_uuid', $encounter->uuid)
            ->assertSet('programs', [$deviceProgram])
            ->assertHasNoErrors();
    }

    public function test_device_form_translates_and_displays_errors_at_fields_and_resets_changed_reason(): void
    {
        $this->mockBasicDictionaries(['device_unit' => ['piece' => 'Штука']]);
        $employee = $this->user->employees()->firstOrFail();
        $person = \App\Models\Person\Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']);
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
        $person = \App\Models\Person\Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']);
        $otherPerson = \App\Models\Person\Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'FEMALE']);
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
        $person = \App\Models\Person\Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']);
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
        $person = \App\Models\Person\Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']);
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
        $this->assertSame(route('device-requests.create', ['legalEntity' => $this->legalEntity, 'person' => $person, 'encounter' => $encounter->id]), $component->redirectedUrl);
    }

    public function test_assistant_with_write_scope_cannot_prescribe_devices(): void
    {
        $this->grantMedicalEventAbilities($this->user);
        $employee = $this->user->employees()->firstOrFail();
        $employee->employeeType = 'ASSISTANT';
        $employee->save();
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        app(DeviceRequestLifecycleService::class)->authorizeAction($this->legalEntity, 'device_request:write');
    }

    public function test_encounter_actions_keep_device_prescribing_and_composition_link_after_ui_update(): void
    {
        $person = \App\Models\Person\Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']);
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
        $person = \App\Models\Person\Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']);

        Livewire::test(DeviceEncounterActionsHarness::class, ['personId' => $person->id, 'compositionUrl' => null])
            ->assertDontSeeHtml('wire:click="openDeviceRequest"')
            ->assertSeeHtml('href="'.route('device-requests.index', ['legalEntity' => $this->legalEntity, 'person' => $person->id]).'"')
            ->assertSeeHtml('disabled')
            ->assertSee(__('encounters.medical_report_creation_unavailable'));
    }

    public function test_device_policy_requires_matching_team_scope_and_active_prescriber(): void
    {
        $model = \App\Models\MedicalEvents\Sql\DeviceRequestRequest::class;
        $this->assertTrue($this->user->can('create', [$model, $this->legalEntity]));
        $this->assertFalse($this->user->can('perform', [$model, $this->legalEntity, 'unrelated:write']));
        $this->assertFalse($this->user->can('perform', [$model, $this->legalEntity, 'device_request:revoke']));
        $this->assertSame(404, \Illuminate\Support\Facades\Gate::inspect('perform', [$model, $this->legalEntity, 'device_request:revoke'])->status());
        setPermissionsTeamId($this->legalEntity->id + 1000);
        $this->assertFalse($this->user->can('create', [$model, $this->legalEntity]));
        setPermissionsTeamId($this->legalEntity->id);
        $this->user->employees()->firstOrFail()->update(['is_active' => false]);
        $this->assertFalse($this->user->can('create', [$model, $this->legalEntity]));
    }

    public function test_assistant_with_extra_write_scope_can_still_open_device_registry(): void
    {
        $this->grantMedicalEventAbilities($this->user);
        $employee = $this->user->employees()->firstOrFail();
        $employee->employeeType = 'ASSISTANT';
        $employee->save();
        $person = \App\Models\Person\Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']);
        $response = Mockery::mock(\App\Classes\eHealth\EHealthResponse::class);
        $response->shouldReceive('getData')->andReturn([]);
        $response->shouldReceive('getPaging')->andReturn(['total_pages' => 1]);
        $api = Mockery::mock(\App\Classes\eHealth\Api\Patient\DeviceRequest::class);
        $api->shouldReceive('getBySearchParams')->twice()->with($person->uuid, ['requester_legal_entity' => $this->legalEntity->uuid, 'page' => 1])->andReturn($response);
        $this->instance(\App\Classes\eHealth\Api\Patient\DeviceRequest::class, $api);
        Livewire::test(\App\Livewire\DeviceRequest\DeviceRequestIndex::class, ['legalEntity' => $this->legalEntity, 'person' => $person])
            ->assertSet('legalEntityId', $this->legalEntity->id)
            ->assertSet('personId', $person->id)
            ->call('search', false)
            ->assertSet('drafts', [])
            ->assertHasNoErrors();
    }

    public function test_device_registry_patient_cannot_be_changed_by_livewire_payload(): void
    {
        $component = Livewire::test(\App\Livewire\DeviceRequest\DeviceRequestIndex::class, ['legalEntity' => $this->legalEntity]);

        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        $component->set('personId', 999);
    }

    public function test_device_registry_renders_raw_api_search_results_and_opens_details_by_resource_id(): void
    {
        $person = \App\Models\Person\Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']);
        $requestId = (string) Str::uuid();
        $record = ['id' => $requestId, 'status' => 'active', 'intent' => 'order', 'requisition' => 'ABCD-1234-5678-9012'];
        config(['ehealth.api.domain' => 'https://ehealth.example']);
        \Illuminate\Support\Facades\Http::fake(function (\Illuminate\Http\Client\Request $request) use ($person, $record, $requestId) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/api/patients/'.$person->uuid.'/device_requests') {
                return \Illuminate\Support\Facades\Http::response(['data' => [$record], 'paging' => ['page_number' => 1, 'total_pages' => 1]], 200);
            }
            if ($path === '/api/patients/'.$person->uuid.'/device_requests/'.$requestId) {
                return \Illuminate\Support\Facades\Http::response(['data' => $record], 200);
            }

            throw new \RuntimeException('Unexpected eHealth request: '.$path);
        });
        $factory = \Illuminate\Support\Facades\Http::getFacadeRoot();
        $this->app->bind(\App\Classes\eHealth\Api\Patient\DeviceRequest::class, static function () use ($factory) {
            $api = new \App\Classes\eHealth\Api\Patient\DeviceRequest($factory);
            $api->stub((function () {
                return $this->stubCallbacks;
            })->call($factory));
            $api->preventStrayRequests();

            return $api;
        });

        Livewire::test(\App\Livewire\DeviceRequest\DeviceRequestIndex::class, ['legalEntity' => $this->legalEntity, 'person' => $person])
            ->assertSet('requests.0.id', $requestId)
            ->assertSee($record['requisition'])
            ->assertSeeHtml("wire:click=\"showDetails('{$requestId}')\"")
            ->call('showDetails', $requestId)
            ->assertSet('selectedId', $requestId)
            ->assertHasNoErrors();

        \Illuminate\Support\Facades\Http::assertSentCount(2);
        \Illuminate\Support\Facades\Http::assertSent(static fn (\Illuminate\Http\Client\Request $request): bool =>
            $request->method() === 'GET' && parse_url($request->url(), PHP_URL_PATH) === '/api/patients/'.$person->uuid.'/device_requests/'.$requestId);
    }

    public function test_device_registry_entity_cannot_be_changed_by_livewire_payload(): void
    {
        $component = Livewire::test(\App\Livewire\DeviceRequest\DeviceRequestIndex::class, ['legalEntity' => $this->legalEntity]);

        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        $component->set('legalEntityId', $this->legalEntity->id + 1);
    }

    public function test_device_registry_rechecks_policy_on_livewire_actions(): void
    {
        $this->withoutVite();

        $component = Livewire::test(\App\Livewire\DeviceRequest\DeviceRequestIndex::class, ['legalEntity' => $this->legalEntity]);
        $this->user->employees()->firstOrFail()->update(['is_active' => false]);

        $component->call('searchPatients')->assertStatus(404);
    }

    public function test_doctor_can_write_only_in_the_current_permission_team(): void
    {
        $this->grantMedicalEventAbilities($this->user);
        $service = app(DeviceRequestLifecycleService::class);
        $this->assertSame($this->user->employees()->firstOrFail()->id, $service->authorizeAction($this->legalEntity, 'device_request:write')->id);
        setPermissionsTeamId($this->legalEntity->id + 1000);
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $service->authorizeAction($this->legalEntity, 'device_request:write');
    }

    public function test_resumed_device_draft_restores_html_dates_and_requires_phone_confirmation_again(): void
    {
        $person = \App\Models\Person\Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']);
        $employee = $this->user->employees()->firstOrFail();
        $draftId = (string) Str::uuid();
        \App\Models\MedicalEvents\Sql\DeviceRequestRequest::create([
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
    public function test_original_draft_is_retained_when_resolved_job_contains_only_metadata(): void
    {
        $draftId = (string) Str::uuid();
        $raw = ['id' => $draftId, 'person' => ['id' => 'patient-id'], 'unknown_extension' => ['zero' => 0, 'list' => []]];
        $api = Mockery::mock(MedicationRequestApi::class);
        $api->shouldReceive('createAndResolve')->once()->andReturn(new DraftResult(
            ['data' => $raw],
            ['id' => $draftId, 'status' => 'processed']
        ));
        $this->instance(MedicationRequestApi::class, $api);

        Livewire::test(MedicationRequestForm::class, ['legalEntity' => $this->legalEntity])
            ->set('patientId', (string) Str::uuid())->set('medicalProgram', (string) Str::uuid())
            ->set('dosageInstruction', 'Take 1 pill')->set('duration', '30')
            ->call('createDraft')->assertSet('isDraftCreated', true)
            ->assertSet('draftId', $draftId)->assertSet('draftContent', $raw);
    }

}

class DeviceEncounterLinkHarness extends \App\Livewire\EncounterPackage\EncounterPackageEdit
{
    public string $redirectedUrl = '';

    public function redirect($url, $navigate = false): void
    {
        $this->redirectedUrl = (string) $url;
    }
}

class DeviceEncounterActionsHarness extends \Livewire\Component
{
    public ?int $personId = null;

    public int $encounterId = 17;

    public bool $isFinished = true;

    public ?string $compositionUrl = '/review/composition/create';

    #[\Livewire\Attributes\Computed]
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
