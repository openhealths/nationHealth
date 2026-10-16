<?php

declare(strict_types=1);

namespace Tests\Feature\MedicationRequest;

use App\Livewire\DeviceRequest\DeviceRequestForm;
use App\Livewire\MedicationRequest\MedicationRequestForm;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\Relations\Party;
use App\Models\User;
use App\Services\MedicalEvents\DeviceRequestLifecycleService;
use App\Services\MedicalEvents\MedicationRequestLifecycleService;
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
        $this->mockBasicDictionaries();
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
            ->assertHasErrors(['encounter']);
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
            ->assertHasErrors(['encounter']);
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
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(DeviceRequestLifecycleService::class)->authorizeAction($this->legalEntity, 'device_request:write');
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
        $api->shouldReceive('getBySearchParams')->once()->with($person->uuid, ['requester_legal_entity' => $this->legalEntity->uuid, 'page' => 1])->andReturn($response);
        $this->instance(\App\Classes\eHealth\Api\Patient\DeviceRequest::class, $api);
        Livewire::test(\App\Livewire\DeviceRequest\DeviceRequestIndex::class, ['legalEntity' => $this->legalEntity, 'person' => $person])
            ->assertSet('drafts', [])
            ->assertHasNoErrors();
    }

    public function test_doctor_can_write_only_in_the_current_permission_team(): void
    {
        $this->grantMedicalEventAbilities($this->user);
        $service = app(DeviceRequestLifecycleService::class);
        $this->assertSame($this->user->employees()->firstOrFail()->id, $service->authorizeAction($this->legalEntity, 'device_request:write')->id);
        setPermissionsTeamId($this->legalEntity->id + 1000);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
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
            ->assertSet('request.started_at', today()->toDateString())
            ->assertSet('request.ended_at', today()->addDays(5)->toDateString())
            ->assertSet('request.phone_confirmed', false)
            ->assertSet('request.confirmed_phone', '');
    }
}

class DeviceEncounterLinkHarness extends \App\Livewire\Encounter\EncounterEdit
{
    public string $redirectedUrl = '';

    public function redirect($url, $navigate = false): void
    {
        $this->redirectedUrl = (string) $url;
    }
}
