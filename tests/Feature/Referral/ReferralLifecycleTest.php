<?php

declare(strict_types=1);

namespace Tests\Feature\Referral;

use App\Classes\eHealth\Api\Patient\ServiceRequest as ServiceRequestApi;
use App\Classes\eHealth\Api\Patient\DeviceRequest as DeviceRequestApi;
use App\Classes\eHealth\EHealthResponse;
use App\Models\CarePlanActivity;
use App\Models\Person\Person;
use App\Models\Employee\Employee;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Repositories\MedicalEvents\Repository;
use App\Services\MedicalEvents\Mappers\ServiceRequestMapper;
use App\Services\MedicalEvents\Mappers\DeviceRequestMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;
use Livewire\Livewire;
use App\Livewire\CarePlan\CarePlanShow;

class ReferralLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected Person $person;
    protected Encounter $encounter;
    protected Employee $employee;
    protected \App\Models\User $user;
    protected CarePlanActivity $serviceActivity;
    protected CarePlanActivity $deviceActivity;

    protected function migrateDatabases()
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

        // 1. Create Patient
        $this->person = Person::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Олексій',
            'last_name' => 'Коваль',
            'birth_date' => '1985-05-15',
            'gender' => 'MALE',
            'patient_signed' => true,
            'process_disclosure_data_consent' => true,
        ]);

        // 2. Create Encounter & helpers
        $identifierId = \App\Models\MedicalEvents\Sql\Identifier::create(['value' => (string) Str::uuid()])->id;
        $codingId = \App\Models\MedicalEvents\Sql\Coding::create(['code' => 'AMB', 'system' => 'eHealth/encounter_classes'])->id;
        $ccId = \App\Models\MedicalEvents\Sql\CodeableConcept::create()->id;

        $this->encounter = Encounter::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'status' => 'finished',
            'episode_id' => $identifierId,
            'class_id' => $codingId,
            'type_id' => $ccId,
            'ehealth_inserted_at' => now(),
        ]);

        // 3. Create Legal Entity
        $typeId = \Illuminate\Support\Facades\DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id') 
            ?? \Illuminate\Support\Facades\DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        $legalEntity = \App\Models\LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);
        $this->instance('legalEntity', $legalEntity);

        // 4. Create Employee
        $party = \App\Models\Relations\Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Іван',
            'last_name' => 'Петренко',
            'tax_id' => '9876543210',
            'birth_date' => '1980-08-08',
            'gender' => 'MALE',
        ]);

        $this->user = \App\Models\User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'ivan' . Str::random(5) . '@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $this->employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Д-р Іван Петренко',
            'employee_type' => 'DOCTOR',
            'status' => 'APPROVED',
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => 'Doctor',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $this->user->id,
            'party_id' => $party->id,
        ]);

        $this->user->employees()->attach($this->employee->id);

        if (config('permission.teams')) {
            setPermissionsTeamId($legalEntity->id);
        }

        // 5. Create Care Plan & Referral Care Plan Activities
        $carePlan = \App\Models\CarePlan::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->employee->legal_entity_id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Referral Care Plan',
            'status' => 'active',
        ]);

        $this->serviceActivity = CarePlanActivity::create([
            'uuid' => (string) Str::uuid(),
            'care_plan_id' => $carePlan->id,
            'author_id' => $this->employee->id,
            'status' => 'scheduled',
            'kind' => 'service_request',
            'product_reference' => '59300-00', // Diagnostic service code
            'quantity' => 5.0,
        ]);

        $this->deviceActivity = CarePlanActivity::create([
            'uuid' => (string) Str::uuid(),
            'care_plan_id' => $carePlan->id,
            'author_id' => $this->employee->id,
            'status' => 'scheduled',
            'kind' => 'device_request',
            'product_reference' => 'D-707', // Assistive device code
            'quantity' => 1.0,
        ]);
    }

    public function test_can_persist_referral_requests_locally(): void
    {
        $serviceRepo = Repository::serviceRequest();
        $deviceRepo = Repository::deviceRequest();

        $serviceUuid = (string) Str::uuid();
        $deviceUuid = (string) Str::uuid();

        $serviceData = [
            'uuid' => $serviceUuid,
            'employee_id' => $this->employee->id,
            'status' => 'draft',
            'service_id' => '59300-00',
            'quantity' => 2.0,
            'intent' => 'order',
            'category' => 'procedure',
            'based_on_id' => $this->serviceActivity->id,
            'context_id' => $this->encounter->id,
            'priority' => 'routine',
            'note' => 'Please perform procedure ASAP',
        ];

        $deviceData = [
            'uuid' => $deviceUuid,
            'employee_id' => $this->employee->id,
            'status' => 'draft',
            'device_id' => 'D-707',
            'quantity' => 1.0,
            'intent' => 'order',
            'based_on_id' => $this->deviceActivity->id,
            'context_id' => $this->encounter->id,
            'priority' => 'urgent',
            'note' => 'Patient needs wheelchair',
        ];

        $serviceId = $serviceRepo->store($serviceData, $this->person->id);
        $deviceId = $deviceRepo->store($deviceData, $this->person->id);

        $this->assertGreaterThan(0, $serviceId);
        $this->assertGreaterThan(0, $deviceId);

        $this->assertDatabaseHas('service_request_requests', [
            'id' => $serviceId,
            'uuid' => $serviceUuid,
            'service_id' => '59300-00',
            'person_id' => $this->person->id,
            'based_on_id' => $this->serviceActivity->id,
        ]);

        $this->assertDatabaseHas('device_request_requests', [
            'id' => $deviceId,
            'uuid' => $deviceUuid,
            'device_id' => 'D-707',
            'person_id' => $this->person->id,
            'based_on_id' => $this->deviceActivity->id,
        ]);
    }

    public function test_can_map_to_fhir_payloads(): void
    {
        $serviceMapper = new ServiceRequestMapper();
        $deviceMapper = new DeviceRequestMapper();

        $serviceData = [
            'uuid' => (string) Str::uuid(),
            'status' => 'draft',
            'intent' => 'order',
            'service_id' => '59300-00',
            'quantity' => 2.0,
            'category' => 'procedure',
            'based_on_uuid' => $this->serviceActivity->uuid,
            'priority' => 'routine',
            'note' => 'Service note',
            'started_at' => '2026-06-01',
            'ended_at' => '2026-09-01',
        ];

        $deviceData = [
            'uuid' => (string) Str::uuid(),
            'status' => 'draft',
            'intent' => 'order',
            'device_id' => 'D-707',
            'quantity' => 1.0,
            'based_on_uuid' => $this->deviceActivity->uuid,
            'priority' => 'urgent',
            'note' => 'Device note',
            'started_at' => '2026-06-01',
            'ended_at' => '2026-09-01',
        ];

        $uuids = [
            'person_uuid' => $this->person->uuid,
            'encounter_uuid' => $this->encounter->uuid,
            'employee_uuid' => $this->employee->uuid,
            'legal_entity_uuid' => (string) Str::uuid()
        ];

        $serviceFhir = $serviceMapper->toFhir($serviceData, $uuids);
        $deviceFhir = $deviceMapper->toFhir($deviceData, $uuids);

        // ServiceRequest assertions
        $this->assertEquals($serviceData['uuid'], $serviceFhir['id']);
        $this->assertEquals('draft', $serviceFhir['status']);
        $this->assertEquals('59300-00', $serviceFhir['code']['coding'][0]['code']);
        $this->assertEquals($this->serviceActivity->uuid, $serviceFhir['basedOn'][0]['identifier']['value']);
        $this->assertEquals(2, $serviceFhir['quantityInteger']);

        // DeviceRequest assertions
        $this->assertEquals($deviceData['uuid'], $deviceFhir['id']);
        $this->assertEquals('draft', $deviceFhir['status']);
        $this->assertEquals('D-707', $deviceFhir['codeCodeableConcept']['coding'][0]['code']);
        $this->assertEquals($this->deviceActivity->uuid, $deviceFhir['basedOn'][0]['identifier']['value']);
        $this->assertEquals(1, $deviceFhir['quantityInteger']);
    }

    public function test_mock_api_create_and_sign_lifecycle(): void
    {
        $mockServiceApi = Mockery::mock(ServiceRequestApi::class);
        $this->instance(ServiceRequestApi::class, $mockServiceApi);

        $mockDeviceApi = Mockery::mock(DeviceRequestApi::class);
        $this->instance(DeviceRequestApi::class, $mockDeviceApi);

        $serviceRequestId = (string) Str::uuid();
        $deviceRequestId = (string) Str::uuid();

        // Mock ServiceRequest API
        $serviceCreateResponse = Mockery::mock(EHealthResponse::class);
        $serviceCreateResponse->shouldReceive('getData')->andReturn([
            'id' => $serviceRequestId,
            'status' => 'NEW',
            'request_number' => 'SR-11112222'
        ]);
        $serviceCreateResponse->shouldReceive('getStatusCode')->andReturn(201);
        $mockServiceApi->shouldReceive('createRequest')->once()->andReturn($serviceCreateResponse);

        $serviceSignResponse = Mockery::mock(EHealthResponse::class);
        $serviceSignResponse->shouldReceive('getData')->andReturn([
            'id' => (string) Str::uuid(),
            'status' => 'active',
            'request_number' => 'SR-11112222'
        ]);
        $serviceSignResponse->shouldReceive('getStatusCode')->andReturn(200);
        $mockServiceApi->shouldReceive('signRequest')->once()->andReturn($serviceSignResponse);

        // Assert ServiceRequest API
        $resServiceCreate = app(ServiceRequestApi::class)->createRequest($this->person->uuid, []);
        $this->assertEquals(201, $resServiceCreate->getStatusCode());
        $this->assertEquals('NEW', $resServiceCreate->getData()['status']);

        $resServiceSign = app(ServiceRequestApi::class)->signRequest($this->person->uuid, $serviceRequestId, []);
        $this->assertEquals(200, $resServiceSign->getStatusCode());
        $this->assertEquals('active', $resServiceSign->getData()['status']);
    }

    public function test_livewire_component_actions(): void
    {
        // Fetch the CarePlan
        $carePlan = $this->serviceActivity->carePlan;

        // Authenticate the employee user
        $this->actingAs($this->user);

        // Test Livewire triggers
        Livewire::test(CarePlanShow::class, ['carePlan' => $carePlan])
            ->assertSet('showReferralDrawer', false)
            ->call('initReferralForm', $this->serviceActivity->id)
            ->assertSet('showReferralDrawer', true)
            ->assertSet('referralForm.code', $this->serviceActivity->product_reference)
            ->assertSet('referralForm.quantity', 1.0)
            ->set('referralForm.quantity', 3.0)
            ->set('referralForm.category', 'procedure')
            ->call('validateReferral')
            ->assertSet('showReferralDrawer', false)
            ->assertSet('showSignatureModal', true);
    }

    public function test_livewire_referral_cancellation(): void
    {
        $carePlan = $this->serviceActivity->carePlan;
        $this->actingAs($this->user);

        // Create a mock ServiceRequestRequest in the DB
        $uuid = (string) Str::uuid();
        $serviceRequest = \App\Models\MedicalEvents\Sql\ServiceRequestRequest::create([
            'uuid' => $uuid,
            'employee_id' => $this->employee->id,
            'person_id' => $this->person->id,
            'status' => 'active',
            'service_id' => '59300-00',
            'quantity' => 1.0,
            'intent' => 'order',
            'based_on_id' => $this->serviceActivity->id,
            'context_id' => $this->encounter->id,
            'priority' => 'routine',
            'request_number' => 'SR-999999'
        ]);

        // Mock eHealth ServiceRequest cancel API
        $mockServiceApi = Mockery::mock(ServiceRequestApi::class);
        $cancelResponse = Mockery::mock(EHealthResponse::class);
        $cancelResponse->shouldReceive('successful')->andReturn(true);
        $cancelResponse->shouldReceive('getData')->andReturn(['status' => 'entered-in-error']);
        $mockServiceApi->shouldReceive('cancel')->once()->andReturn($cancelResponse);
        $this->instance(ServiceRequestApi::class, $mockServiceApi);

        // Mock SignatureService
        $mockSignatureService = Mockery::mock(\App\Services\SignatureService::class);
        $this->instance(\App\Services\SignatureService::class, $mockSignatureService);
        $mockSignatureService->shouldReceive('signData')->andReturn('mock-base64-signature');
        $mockSignatureService->shouldReceive('getCertificateAuthorities')->andReturn([]);

        // Livewire test
        Livewire::test(CarePlanShow::class, ['carePlan' => $carePlan])
            ->call('cancelReferral', $uuid, 'service_request')
            ->assertSet('showSignatureModal', true)
            ->assertSet('referralRequestIdToSign', $uuid)
            ->assertSet('actionType', 'cancel_referral')
            ->set('statusReason', 'entered-in-error')
            ->set('form.password', '12345678')
            ->set('form.knedp', 'acsk_test')
            ->set('form.keyContainerUpload', \Illuminate\Http\UploadedFile::fake()->create('key.dat', 10))
            ->call('signCancelReferral')
            ->assertSet('showSignatureModal', false);

        // Assert database updated
        $this->assertDatabaseHas('service_request_requests', [
            'uuid' => $uuid,
            'status' => 'entered-in-error'
        ]);
    }
}
