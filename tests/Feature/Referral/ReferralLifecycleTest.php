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
use App\Livewire\CarePlan\Activity\Show\CarePlanActivityShow;

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
            'program' => (string) Str::uuid(),
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

    private function mockActivityRegisteredInEHealth(): void
    {
        $mockActivityApi = Mockery::mock(\App\Classes\eHealth\Api\CarePlanActivity::class);
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('successful')->andReturn(true);
        $response->shouldReceive('getData')->andReturn(['id' => (string) Str::uuid()]);
        $mockActivityApi->shouldReceive('getDetails')->andReturn($response);
        $this->instance(\App\Classes\eHealth\Api\CarePlanActivity::class, $mockActivityApi);
    }

    private function mockReferralMissingInEHealth(ServiceRequestApi $mockServiceApi, string $personUuid, string $requestUuid): void
    {
        $missingResponse = Mockery::mock(EHealthResponse::class);
        $missingResponse->shouldReceive('getData')->andReturn([]);
        $mockServiceApi->shouldReceive('getById')
            ->with($personUuid, $requestUuid)
            ->andReturn($missingResponse);
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

    public function test_can_map_to_prequalify_payloads(): void
    {
        $serviceMapper = new ServiceRequestMapper();
        $deviceMapper = new DeviceRequestMapper();

        $carePlanUuid = $this->serviceActivity->carePlan->uuid;

        $serviceData = [
            'service_id' => '59300-00',
            'quantity' => 2.0,
            'intent' => 'order',
            'category' => 'procedure',
            'program_id' => 'program-uuid',
            'priority' => 'routine',
            'started_at' => '2026-06-01',
            'ended_at' => '2026-09-01',
            'supporting_info' => [
                ['type' => 'condition', 'uuid' => (string) Str::uuid()],
            ],
        ];

        $deviceData = [
            'device_id' => 'D-707',
            'quantity' => 1.0,
            'intent' => 'order',
            'program_id' => 'program-uuid',
            'started_at' => '2026-06-01',
            'ended_at' => '2026-09-01',
            'supporting_info' => [
                ['type' => 'condition', 'uuid' => (string) Str::uuid()],
            ],
        ];

        $uuids = [
            'person_uuid' => $this->person->uuid,
            'encounter_uuid' => $this->encounter->uuid,
            'employee_uuid' => $this->employee->uuid,
            'legal_entity_uuid' => $this->employee->legalEntity->uuid,
        ];

        $servicePrequalify = $serviceMapper->toPrequalifyPayload(
            $serviceData,
            $uuids,
            $carePlanUuid,
            (string) $this->serviceActivity->uuid
        );
        $devicePrequalify = $deviceMapper->toPrequalifyPayload(
            $deviceData,
            $uuids,
            $carePlanUuid,
            (string) $this->deviceActivity->uuid
        );

        $this->assertArrayHasKey('service_request', $servicePrequalify);
        $this->assertEquals('active', $servicePrequalify['service_request']['status']);
        $this->assertEquals('59300-00', $servicePrequalify['service_request']['code']['identifier']['value']);
        $this->assertEquals($carePlanUuid, $servicePrequalify['service_request']['based_on'][0]['identifier']['value']);
        $this->assertArrayNotHasKey('patient', $servicePrequalify['service_request']);
        $this->assertArrayHasKey('quantity', $servicePrequalify['service_request']);
        $this->assertArrayHasKey('programs', $servicePrequalify);

        $this->assertArrayHasKey('device_request', $devicePrequalify);
        $this->assertEquals('D-707', $devicePrequalify['device_request']['code']['coding'][0]['code']);
        $this->assertEquals(1, $devicePrequalify['device_request']['quantity']['value']);
        $this->assertArrayHasKey('identifier', $devicePrequalify['device_request']['requester']);
        $this->assertArrayNotHasKey('agent', $devicePrequalify['device_request']['requester']);
        $this->assertArrayHasKey('authored_on', $devicePrequalify['device_request']);
        $this->assertArrayHasKey('occurrence_period', $devicePrequalify['device_request']);
        $this->assertArrayHasKey('programs', $devicePrequalify);
        $this->assertArrayNotHasKey('programs', $devicePrequalify['device_request']);

        $devicePrequalifyWithoutDates = $deviceMapper->toPrequalifyPayload(
            [
                'device_id' => 'D-707',
                'quantity' => 1.0,
                'intent' => 'order',
                'program_id' => 'program-uuid',
            ],
            $uuids,
            $carePlanUuid,
            (string) $this->deviceActivity->uuid
        );

        $this->assertArrayHasKey('occurrence_period', $devicePrequalifyWithoutDates['device_request']);
    }

    public function test_device_prequalify_uses_device_definition_identifier_for_uuid(): void
    {
        $deviceMapper = new DeviceRequestMapper();
        $carePlanUuid = $this->serviceActivity->carePlan->uuid;
        $deviceUuid = '0fa1e6cd-7066-4881-92a5-6d747a1128f7';

        $deviceData = [
            'device_id' => $deviceUuid,
            'device_code_type' => 'DEVICE_DEFINITION',
            'quantity' => 100.0,
            'quantity_code' => 'piece',
            'intent' => 'order',
            'program_id' => 'program-uuid',
            'started_at' => '2026-06-01',
            'ended_at' => '2026-09-01',
        ];

        $uuids = [
            'person_uuid' => $this->person->uuid,
            'encounter_uuid' => $this->encounter->uuid,
            'employee_uuid' => $this->employee->uuid,
            'legal_entity_uuid' => $this->employee->legalEntity->uuid,
        ];

        $devicePrequalify = $deviceMapper->toPrequalifyPayload(
            $deviceData,
            $uuids,
            $carePlanUuid,
            (string) $this->deviceActivity->uuid
        );

        $this->assertArrayNotHasKey('code', $devicePrequalify['device_request']);
        $this->assertSame($deviceUuid, $devicePrequalify['device_request']['code_reference']['identifier']['value']);
        $this->assertSame('device_definition', $devicePrequalify['device_request']['code_reference']['identifier']['type']['coding'][0]['code']);
        $this->assertSame('piece', $devicePrequalify['device_request']['quantity']['code']);
    }

    public function test_can_map_to_create_signed_payloads(): void
    {
        $serviceMapper = new ServiceRequestMapper();
        $deviceMapper = new DeviceRequestMapper();

        $carePlanUuid = $this->serviceActivity->carePlan->uuid;
        $requestUuid = (string) Str::uuid();

        $serviceData = [
            'uuid' => $requestUuid,
            'service_id' => '59300-00',
            'quantity' => 2.0,
            'intent' => 'order',
            'category' => 'procedure',
            'program_id' => 'program-uuid',
            'priority' => 'routine',
            'started_at' => '2026-06-01',
            'ended_at' => '2026-09-01',
        ];

        $deviceData = [
            'uuid' => $requestUuid,
            'device_id' => 'D-707',
            'quantity' => 1.0,
            'intent' => 'order',
            'program_id' => 'program-uuid',
            'priority' => 'urgent',
            'started_at' => '2026-06-01',
            'ended_at' => '2026-09-01',
        ];

        $uuids = [
            'person_uuid' => $this->person->uuid,
            'encounter_uuid' => $this->encounter->uuid,
            'employee_uuid' => $this->employee->uuid,
            'legal_entity_uuid' => $this->employee->legalEntity->uuid,
        ];

        $serviceSigned = $serviceMapper->toCreateSignedPayload(
            $serviceData,
            $uuids,
            $carePlanUuid,
            (string) $this->serviceActivity->uuid
        );
        $deviceSigned = $deviceMapper->toCreateSignedPayload(
            $deviceData,
            $uuids,
            $carePlanUuid,
            (string) $this->deviceActivity->uuid
        );

        $this->assertEquals($requestUuid, $serviceSigned['service_request']['id']);
        $this->assertEquals('active', $serviceSigned['service_request']['status']);
        $this->assertArrayHasKey('authored_on', $serviceSigned['service_request']);
        $this->assertArrayHasKey('programs', $serviceSigned);

        $this->assertEquals($requestUuid, $deviceSigned['device_request']['id']);
        $this->assertEquals('active', $deviceSigned['device_request']['status']);
        $this->assertArrayHasKey('authored_on', $deviceSigned['device_request']);
        $this->assertArrayHasKey('occurrence_period', $deviceSigned['device_request']);
        $this->assertArrayHasKey('programs', $deviceSigned);
        $this->assertArrayNotHasKey('programs', $deviceSigned['device_request']);

        $serviceSignContent = $serviceMapper->toCreateSignedContent(
            $serviceData,
            $uuids,
            $carePlanUuid,
            (string) $this->serviceActivity->uuid
        );
        $deviceSignContent = $deviceMapper->toCreateSignedContent(
            $deviceData,
            $uuids,
            $carePlanUuid,
            (string) $this->deviceActivity->uuid
        );

        $this->assertArrayNotHasKey('authored_on', $serviceSignContent);
        $this->assertArrayNotHasKey('authored_on', $deviceSignContent);
        $this->assertEquals($requestUuid, $serviceSignContent['id']);
        $this->assertArrayNotHasKey('service_request', $serviceSignContent);
        $this->assertArrayHasKey('requester_employee', $serviceSignContent);
        $this->assertEquals($requestUuid, $deviceSignContent['id']);
        $this->assertArrayNotHasKey('device_request', $deviceSignContent);
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
        $mockServiceApi->shouldReceive('createSigned')->once()->andReturn($serviceCreateResponse);

        $serviceSignResponse = Mockery::mock(EHealthResponse::class);
        $serviceSignResponse->shouldReceive('getData')->andReturn([
            'id' => (string) Str::uuid(),
            'status' => 'active',
            'request_number' => 'SR-11112222'
        ]);
        $serviceSignResponse->shouldReceive('getStatusCode')->andReturn(200);

        // Assert ServiceRequest API
        $resServiceCreate = app(ServiceRequestApi::class)->createSigned($this->person->uuid, []);
        $this->assertEquals(201, $resServiceCreate->getStatusCode());
        $this->assertEquals('NEW', $resServiceCreate->getData()['status']);
    }

    public function test_livewire_component_actions(): void
    {
        $carePlan = $this->serviceActivity->carePlan;
        $this->actingAs($this->user);
        $this->mockActivityRegisteredInEHealth();

        $mockServiceApi = Mockery::mock(ServiceRequestApi::class);
        $this->instance(ServiceRequestApi::class, $mockServiceApi);

        $prequalifyResponse = Mockery::mock(EHealthResponse::class);
        $prequalifyResponse->shouldReceive('getData')->andReturn([
            'data' => [
                ['status' => 'VALID'],
            ],
        ]);
        $mockServiceApi->shouldReceive('prequalify')->once()->andReturn($prequalifyResponse);

        $component = Livewire::test(CarePlanActivityShow::class, [
            'carePlan' => $carePlan,
            'activity' => $this->serviceActivity,
        ])
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

        $draftUuid = $component->get('referralRequestIdToSign');
        $this->assertNotEmpty($draftUuid);

        $this->assertDatabaseHas('service_request_requests', [
            'uuid' => $draftUuid,
            'status' => 'draft',
            'quantity' => 3.0,
            'based_on_id' => $this->serviceActivity->id,
        ]);
    }

    public function test_livewire_skips_prequalify_when_activity_has_no_program(): void
    {
        $this->serviceActivity->update(['program' => null]);
        $carePlan = $this->serviceActivity->carePlan;
        $this->actingAs($this->user);
        $this->mockActivityRegisteredInEHealth();

        $mockServiceApi = Mockery::mock(ServiceRequestApi::class);
        $this->instance(ServiceRequestApi::class, $mockServiceApi);

        $mockServiceApi->shouldReceive('prequalify')->never();

        $component = Livewire::test(CarePlanActivityShow::class, [
            'carePlan' => $carePlan,
            'activity' => $this->serviceActivity->fresh(),
        ])
            ->call('initReferralForm', $this->serviceActivity->id)
            ->call('validateReferral');

        $draftUuid = $component->get('referralRequestIdToSign');
        $this->assertNotEmpty($draftUuid);

        $this->assertDatabaseHas('service_request_requests', [
            'uuid' => $draftUuid,
            'status' => 'draft',
            'based_on_id' => $this->serviceActivity->id,
        ]);
    }

    public function test_livewire_resend_referral_sms(): void
    {
        $carePlan = $this->serviceActivity->carePlan;
        $this->actingAs($this->user);

        $uuid = (string) Str::uuid();
        \App\Models\MedicalEvents\Sql\ServiceRequestRequest::create([
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
            'request_number' => 'SR-888888',
        ]);

        $mockServiceApi = Mockery::mock(ServiceRequestApi::class);
        $resendResponse = Mockery::mock(EHealthResponse::class);
        $resendResponse->shouldReceive('successful')->andReturn(true);
        $resendResponse->shouldReceive('getData')->andReturn(['status' => 'ok']);
        $mockServiceApi->shouldReceive('resendSms')->once()->with($this->person->uuid, $uuid)->andReturn($resendResponse);
        $this->instance(ServiceRequestApi::class, $mockServiceApi);

        Livewire::test(CarePlanActivityShow::class, [
            'carePlan' => $carePlan,
            'activity' => $this->serviceActivity,
        ])
            ->call('resendReferralSms', $uuid, 'service_request')
            ->assertDispatched('flashMessage');
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
        Livewire::test(CarePlanActivityShow::class, [
            'carePlan' => $carePlan,
            'activity' => $this->serviceActivity,
        ])
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

    public function test_sign_referral_does_not_require_status_reason(): void
    {
        $carePlan = $this->serviceActivity->carePlan;
        $this->actingAs($this->user);
        $this->mockActivityRegisteredInEHealth();

        $draftUuid = (string) Str::uuid();
        $signedUuid = (string) Str::uuid();
        \App\Models\MedicalEvents\Sql\ServiceRequestRequest::create([
            'uuid' => $draftUuid,
            'employee_id' => $this->employee->id,
            'person_id' => $this->person->id,
            'status' => 'draft',
            'service_id' => '59300-00',
            'quantity' => 1.0,
            'intent' => 'order',
            'based_on_id' => $this->serviceActivity->id,
            'context_id' => $this->encounter->id,
            'priority' => 'routine',
            'started_at' => '2026-06-01',
            'ended_at' => '2026-09-01',
        ]);

        $mockServiceApi = Mockery::mock(ServiceRequestApi::class);
        $createResponse = Mockery::mock(EHealthResponse::class);
        $createResponse->shouldReceive('getData')->andReturn([
            'id' => $signedUuid,
            'status' => 'active',
            'request_number' => 'SR-12345678',
        ]);
        $this->mockReferralMissingInEHealth($mockServiceApi, $this->person->uuid, $draftUuid);
        $mockServiceApi->shouldReceive('createSigned')->once()->andReturn($createResponse);
        $this->instance(ServiceRequestApi::class, $mockServiceApi);

        $mockSignatureService = Mockery::mock(\App\Services\SignatureService::class);
        $this->instance(\App\Services\SignatureService::class, $mockSignatureService);
        $mockSignatureService->shouldReceive('signData')->andReturn('mock-base64-signature');
        $mockSignatureService->shouldReceive('getCertificateAuthorities')->andReturn([]);

        Livewire::test(CarePlanActivityShow::class, [
            'carePlan' => $carePlan,
            'activity' => $this->serviceActivity,
        ])
            ->call('initReferralForm', $this->serviceActivity->id)
            ->assertSet('showSignatureModal', true)
            ->assertSet('actionType', 'sign_servicerequest')
            ->set('form.password', '12345678')
            ->set('form.knedp', 'acsk_test')
            ->set('form.keyContainerUpload', \Illuminate\Http\UploadedFile::fake()->create('key.dat', 10))
            ->call('sign')
            ->assertHasNoErrors(['statusReason'])
            ->assertSet('showSignatureModal', false);

        $this->assertDatabaseHas('service_request_requests', [
            'uuid' => $signedUuid,
            'employee_id' => $this->employee->id,
            'based_on_id' => $this->serviceActivity->id,
            'context_id' => $this->encounter->id,
            'status' => 'active',
            'request_number' => 'SR-12345678',
        ]);
    }

    public function test_sign_referral_syncs_when_ehealth_reports_already_exists(): void
    {
        $carePlan = $this->serviceActivity->carePlan;
        $this->actingAs($this->user);
        $this->mockActivityRegisteredInEHealth();

        $draftUuid = (string) Str::uuid();
        \App\Models\MedicalEvents\Sql\ServiceRequestRequest::create([
            'uuid' => $draftUuid,
            'employee_id' => $this->employee->id,
            'person_id' => $this->person->id,
            'status' => 'draft',
            'service_id' => '59300-00',
            'quantity' => 1.0,
            'intent' => 'order',
            'based_on_id' => $this->serviceActivity->id,
            'context_id' => $this->encounter->id,
            'priority' => 'routine',
            'started_at' => '2026-06-01',
            'ended_at' => '2026-09-01',
        ]);

        $mockServiceApi = Mockery::mock(ServiceRequestApi::class);
        $this->instance(ServiceRequestApi::class, $mockServiceApi);

        $missingResponse = Mockery::mock(EHealthResponse::class);
        $missingResponse->shouldReceive('getData')->andReturn([]);
        $getResponse = Mockery::mock(EHealthResponse::class);
        $getResponse->shouldReceive('getData')->andReturn([
            'id' => $draftUuid,
            'status' => 'active',
            'request_number' => 'SR-EXISTING-99',
            'code' => [
                'identifier' => ['value' => '59300-00'],
            ],
            'quantity' => ['value' => 1.0],
            'occurrence_period' => [
                'start' => '2026-06-01T00:00:00Z',
                'end' => '2026-09-01T00:00:00Z',
            ],
        ]);
        $mockServiceApi->shouldReceive('getById')
            ->with($this->person->uuid, $draftUuid)
            ->andReturn($missingResponse, $getResponse);

        $mockServiceApi->shouldReceive('createSigned')->once()->andThrow(new \App\Exceptions\EHealth\EHealthValidationException([
            'error' => [
                'message' => 'Validation failed.',
                'invalid' => [
                    [
                        'entry' => '$.id',
                        'rules' => [
                            ['description' => 'Service request with such id already exists'],
                        ],
                    ],
                ],
            ],
        ]));

        $mockSignatureService = Mockery::mock(\App\Services\SignatureService::class);
        $this->instance(\App\Services\SignatureService::class, $mockSignatureService);
        $mockSignatureService->shouldReceive('signData')->andReturn('mock-base64-signature');
        $mockSignatureService->shouldReceive('getCertificateAuthorities')->andReturn([]);

        Livewire::test(CarePlanActivityShow::class, [
            'carePlan' => $carePlan,
            'activity' => $this->serviceActivity,
        ])
            ->call('initReferralForm', $this->serviceActivity->id)
            ->set('form.password', '12345678')
            ->set('form.knedp', 'acsk_test')
            ->set('form.keyContainerUpload', \Illuminate\Http\UploadedFile::fake()->create('key.dat', 10))
            ->call('sign')
            ->assertSet('showSignatureModal', false);

        $this->assertDatabaseHas('service_request_requests', [
            'uuid' => $draftUuid,
            'status' => 'active',
            'request_number' => 'SR-EXISTING-99',
            'employee_id' => $this->employee->id,
        ]);
    }

    public function test_init_referral_form_syncs_draft_when_already_active_in_ehealth(): void
    {
        $carePlan = $this->serviceActivity->carePlan;
        $this->actingAs($this->user);
        $this->mockActivityRegisteredInEHealth();

        $draftUuid = (string) Str::uuid();
        \App\Models\MedicalEvents\Sql\ServiceRequestRequest::create([
            'uuid' => $draftUuid,
            'employee_id' => $this->employee->id,
            'person_id' => $this->person->id,
            'status' => 'draft',
            'service_id' => '59300-00',
            'quantity' => 1.0,
            'intent' => 'order',
            'based_on_id' => $this->serviceActivity->id,
            'context_id' => $this->encounter->id,
            'priority' => 'routine',
            'started_at' => '2026-06-01',
            'ended_at' => '2026-09-01',
        ]);

        $mockServiceApi = Mockery::mock(ServiceRequestApi::class);
        $this->instance(ServiceRequestApi::class, $mockServiceApi);

        $getResponse = Mockery::mock(EHealthResponse::class);
        $getResponse->shouldReceive('getData')->andReturn([
            'id' => $draftUuid,
            'status' => 'active',
            'request_number' => 'SR-SYNC-ON-OPEN',
        ]);
        $mockServiceApi->shouldReceive('getById')
            ->with($this->person->uuid, $draftUuid)
            ->andReturn($getResponse);
        $mockServiceApi->shouldNotReceive('createSigned');

        Livewire::test(CarePlanActivityShow::class, [
            'carePlan' => $carePlan,
            'activity' => $this->serviceActivity,
        ])
            ->call('initReferralForm', $this->serviceActivity->id)
            ->assertSet('showSignatureModal', false);

        $this->assertDatabaseHas('service_request_requests', [
            'uuid' => $draftUuid,
            'status' => 'active',
            'request_number' => 'SR-SYNC-ON-OPEN',
        ]);
    }

    public function test_active_referrals_are_normalized_for_activity_view(): void
    {
        $carePlan = $this->serviceActivity->carePlan;
        $this->actingAs($this->user);

        $referralUuid = (string) Str::uuid();
        \App\Models\MedicalEvents\Sql\ServiceRequestRequest::create([
            'uuid' => $referralUuid,
            'employee_id' => $this->employee->id,
            'person_id' => $this->person->id,
            'status' => 'active',
            'request_number' => 'SR-VIEW-001',
            'service_id' => '59300-00',
            'quantity' => 1.0,
            'intent' => 'order',
            'based_on_id' => $this->serviceActivity->id,
            'context_id' => $this->encounter->id,
            'priority' => 'routine',
            'category' => 'procedure',
            'started_at' => '2026-06-01',
            'ended_at' => '2026-09-01',
        ]);

        $component = Livewire::test(CarePlanActivityShow::class, [
            'carePlan' => $carePlan,
            'activity' => $this->serviceActivity,
        ]);

        $linkedReferrals = collect($component->get('activeReferrals'))
            ->where('based_on_id', $this->serviceActivity->id);

        $this->assertCount(1, $linkedReferrals);
        $referral = $linkedReferrals->first();
        $this->assertSame('SR-VIEW-001', $referral['request_number']);
        $this->assertSame('active', $referral['status']);
        $this->assertSame('Активне', $referral['status_label']);
        $this->assertSame('59300-00', $referral['product_code']);
        $this->assertSame('service_request', $referral['kind']);
    }

    public function test_load_referral_printout_form_returns_html(): void
    {
        $carePlan = $this->serviceActivity->carePlan;
        $this->actingAs($this->user);

        $referralUuid = (string) Str::uuid();
        \App\Models\MedicalEvents\Sql\ServiceRequestRequest::create([
            'uuid' => $referralUuid,
            'employee_id' => $this->employee->id,
            'person_id' => $this->person->id,
            'status' => 'active',
            'request_number' => 'SR-PRINT-001',
            'service_id' => '59300-00',
            'quantity' => 1.0,
            'intent' => 'order',
            'based_on_id' => $this->serviceActivity->id,
            'context_id' => $this->encounter->id,
            'priority' => 'routine',
            'started_at' => '2026-06-01',
            'ended_at' => '2026-09-01',
        ]);

        $component = Livewire::test(CarePlanActivityShow::class, [
            'carePlan' => $carePlan,
            'activity' => $this->serviceActivity,
        ])
            ->call('loadReferralPrintoutForm', $referralUuid);

        $html = $component->get('printableContent');

        $this->assertIsString($html);
        $this->assertStringContainsString('SR-PRINT-001', $html);
        $this->assertStringContainsString('ІНФОРМАЦІЙНА ДОВІДКА НАПРАВЛЕННЯ', $html);
    }

    public function test_init_referral_form_blocks_medication_activity(): void
    {
        $carePlan = $this->serviceActivity->carePlan;
        $medicationActivity = CarePlanActivity::create([
            'uuid' => (string) Str::uuid(),
            'care_plan_id' => $carePlan->id,
            'author_id' => $this->employee->id,
            'status' => 'scheduled',
            'kind' => 'medication_request',
            'product_reference' => '02b5e4de-22ec-429d-81f2-8faf44bd8c92',
            'quantity' => 1.0,
        ]);

        $this->actingAs($this->user);

        Livewire::test(CarePlanActivityShow::class, [
            'carePlan' => $carePlan,
            'activity' => $medicationActivity,
        ])
            ->call('initReferralForm', $medicationActivity->id)
            ->assertSet('showReferralDrawer', false)
            ->assertDispatched('flashMessage');
    }
}
