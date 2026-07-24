<?php

declare(strict_types=1);

namespace Tests\Feature\MedicationRequest;

use App\Classes\eHealth\Api\Patient\MedicationRequest as MedicationRequestApi;
use App\Classes\eHealth\Api\Person as PersonApi;
use App\Classes\eHealth\EHealthResponse;
use App\Models\CarePlanActivity;
use App\Models\Person\Person;
use App\Models\Employee\Employee;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Repositories\MedicalEvents\Repository;
use App\Services\MedicalEvents\Mappers\MedicationRequestMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;
use Livewire\Livewire;
use App\Livewire\CarePlan\Activity\Show\CarePlanActivityShow;

class MedicationRequestLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected Person $person;
    protected Encounter $encounter;
    protected Employee $employee;
    protected \App\Models\User $user;
    protected CarePlanActivity $carePlanActivity;

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

        // 5. Create Care Plan & Medication Care Plan Activity
        $carePlan = \App\Models\CarePlan::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->employee->legal_entity_id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Affordable Medicines Plan',
            'status' => 'active',
        ]);

        $this->carePlanActivity = CarePlanActivity::create([
            'uuid' => (string) Str::uuid(),
            'care_plan_id' => $carePlan->id,
            'author_id' => $this->employee->id,
            'status' => 'scheduled',
            'kind' => 'medication_request',
            'product_reference' => 'INN-101',
            'quantity' => 60.0,
            'program' => 'program-1',
        ]);
    }

    public function test_can_persist_medication_request_request_locally(): void
    {
        $repo = Repository::medicationRequest();

        $uuid = (string) Str::uuid();
        $payload = [
            'uuid' => $uuid,
            'employee_id' => $this->employee->id,
            'status' => 'draft',
            'medication_id' => 'INN-101',
            'medication_qty' => 60.0,
            'medication_program_id' => 'program-affordable-medicines',
            'intent' => 'order',
            'based_on_id' => $this->carePlanActivity->id,
            'context_id' => $this->encounter->id,
            'dosage_instructions' => [
                [
                    'sequence' => 1,
                    'text' => '1 tablet 2 times daily',
                    'patient_instruction' => 'Take with water',
                    'route' => 'oral',
                    'as_needed_boolean' => false,
                    'dose_and_rate' => [
                        [
                            'dose_quantity_value' => 1.0,
                            'dose_quantity_unit' => 'tab'
                        ]
                    ]
                ]
            ]
        ];

        $id = $repo->store($payload, $this->person->id);
        $this->assertGreaterThan(0, $id);

        $this->assertDatabaseHas('medication_request_requests', [
            'id' => $id,
            'uuid' => $uuid,
            'medication_id' => 'INN-101',
            'person_id' => $this->person->id,
            'based_on_id' => $this->carePlanActivity->id
        ]);

        $this->assertDatabaseHas('dosage_instructions', [
            'medication_request_request_id' => $id,
            'sequence' => 1,
            'route' => 'oral',
            'patient_instruction' => 'Take with water'
        ]);
    }

    public function test_can_map_to_fhir_payload(): void
    {
        $mapper = new MedicationRequestMapper();

        $payload = [
            'uuid' => (string) Str::uuid(),
            'status' => 'draft',
            'intent' => 'order',
            'medication_id' => 'INN-101',
            'medication_qty' => 30.0,
            'medication_program_id' => 'program-affordable-medicines',
            'based_on_uuid' => $this->carePlanActivity->uuid,
            'note' => 'Take in the morning',
            'dosage_instructions' => [
                [
                    'sequence' => 1,
                    'text' => '1 tablet daily',
                    'route' => 'oral',
                    'as_needed_boolean' => false,
                    'dose_and_rate' => [
                        [
                            'dose_quantity_value' => 1.0
                        ]
                    ]
                ]
            ]
        ];

        $uuids = [
            'person_uuid' => $this->person->uuid,
            'encounter_uuid' => $this->encounter->uuid,
            'employee_uuid' => $this->employee->uuid,
            'legal_entity_uuid' => (string) Str::uuid()
        ];

        $fhir = $mapper->toFhir($payload, $uuids);

        $this->assertEquals($payload['uuid'], $fhir['id']);
        $this->assertEquals('draft', $fhir['status']);
        $this->assertEquals('INN-101', $fhir['medicationCodeableConcept']['coding'][0]['code']);
        $this->assertEquals('program-affordable-medicines', $fhir['program']['identifier']['value']);
        $this->assertEquals($this->carePlanActivity->uuid, $fhir['basedOn'][0]['identifier']['value']);
        $this->assertEquals('Take in the morning', $fhir['note'][0]['text']);
        $this->assertEquals(1.0, $fhir['dosageInstruction'][0]['doseAndRate'][0]['doseQuantity']['value']);
    }

    public function test_create_request_payload_uses_snomed_route_and_object_dose_and_rate(): void
    {
        $mapper = new MedicationRequestMapper();

        $payload = $mapper->toCreateRequestPayload(
            [
                'medication_id' => 'INN-101',
                'medication_qty' => 10.0,
                'started_at' => '2026-06-24',
                'ended_at' => '2026-07-03',
                'based_on_uuid' => $this->carePlanActivity->uuid,
                'dosage_instructions' => [
                    [
                        'sequence' => 1,
                        'text' => '1 tablet daily',
                        'route' => 'oral',
                        'dose_and_rate' => [
                            [
                                'dose_quantity_value' => 1.0,
                                'dose_quantity_unit' => 'tab',
                            ],
                        ],
                        'max_dose_per_administration' => 1.0,
                        'max_dose_per_period' => 1.0,
                    ],
                ],
            ],
            [
                'person_uuid' => $this->person->uuid,
                'employee_uuid' => $this->employee->uuid,
                'division_uuid' => (string) Str::uuid(),
            ],
            $this->carePlanActivity->carePlan->uuid,
        );

        $dosage = $payload['medication_request_request']['dosage_instruction'][0];

        $this->assertSame('26643006', $dosage['route']['coding'][0]['code']);
        $this->assertIsArray($dosage['dose_and_rate']);
        $this->assertFalse(array_is_list($dosage['dose_and_rate']));
        $this->assertSame('ordered', $dosage['dose_and_rate']['type']['coding'][0]['code']);
        $this->assertSame(1.0, $dosage['dose_and_rate']['dose_quantity']['value']);
    }

    public function test_mock_api_create_and_sign_lifecycle(): void
    {
        $mockApi = Mockery::mock(MedicationRequestApi::class);
        $this->instance(MedicationRequestApi::class, $mockApi);

        $requestId = (string) Str::uuid();
        $finalPrescriptionId = (string) Str::uuid();

        // 1. Mock Create Request
        $createResponse = Mockery::mock(EHealthResponse::class);
        $createResponse->shouldReceive('getData')->andReturn([
            'id' => $requestId,
            'status' => 'NEW',
            'request_number' => 'MR-1234567'
        ]);
        $createResponse->shouldReceive('getStatusCode')->andReturn(201);
        $mockApi->shouldReceive('createRequest')->once()->andReturn($createResponse);

        // 2. Mock Sign Request
        $signResponse = Mockery::mock(EHealthResponse::class);
        $signResponse->shouldReceive('getData')->andReturn([
            'id' => $finalPrescriptionId,
            'status' => 'active',
            'request_number' => 'MR-1234567'
        ]);
        $signResponse->shouldReceive('getStatusCode')->andReturn(200);
        $mockApi->shouldReceive('signRequest')->once()->andReturn($signResponse);

        // Call the mocked API and assert
        $resCreate = app(MedicationRequestApi::class)->createRequest([]);
        $this->assertEquals(201, $resCreate->getStatusCode());
        $this->assertEquals('NEW', $resCreate->getData()['status']);

        $resSign = app(MedicationRequestApi::class)->signRequest($requestId, []);
        $this->assertEquals(200, $resSign->getStatusCode());
        $this->assertEquals('active', $resSign->getData()['status']);
    }

    public function test_livewire_component_actions(): void
    {
        // 1. Mock Drug and Program API endpoints
        $mockDrugApi = Mockery::mock(\App\Classes\eHealth\Api\Drug::class);
        $drugResponse = Mockery::mock(\App\Classes\eHealth\EHealthResponse::class);
        $drugResponse->shouldReceive('getData')->andReturn([
            [
                'id' => 'INN-101',
                'name' => 'Test Drug',
                'innm_dosage_form' => 'ml',
                'packages' => [
                    [
                        'package_min_qty' => 10,
                        'max_request_dosage' => 100
                    ]
                ]
            ]
        ]);
        $drugResponse->shouldReceive('getStatusCode')->andReturn(200);
        $mockDrugApi->shouldReceive('getMany')->andReturn($drugResponse);
        $this->instance(\App\Classes\eHealth\Api\Drug::class, $mockDrugApi);

        $mockProgramApi = Mockery::mock(\App\Classes\eHealth\Api\MedicalProgram::class);
        $programResponse = Mockery::mock(\App\Classes\eHealth\EHealthResponse::class);
        $programResponse->shouldReceive('getData')->andReturn([
            [
                'id' => 'program-1',
                'name' => 'Affordable Medicines',
                'settings' => [
                    'skip_treatment_period' => true,
                    'request_max_period_day' => 90
                ]
            ]
        ]);
        $programResponse->shouldReceive('getPaging')->andReturn(['total_pages' => 1]);
        $programResponse->shouldReceive('getStatusCode')->andReturn(200);
        $mockProgramApi->shouldReceive('asMis')->andReturnSelf();
        $mockProgramApi->shouldReceive('getMany')->andReturn($programResponse);
        $this->instance(\App\Classes\eHealth\Api\MedicalProgram::class, $mockProgramApi);

        $mockPersonApi = Mockery::mock(\App\Classes\eHealth\Api\Person::class);
        $personResponse = Mockery::mock(\App\Classes\eHealth\EHealthResponse::class);
        $personResponse->shouldReceive('getData')->andReturn([
            [
                'uuid' => 'auth-1',
                'type' => 'OTP',
                'phone_number' => '+380991112233'
            ]
        ]);
        $personResponse->shouldReceive('getStatusCode')->andReturn(200);
        $mockPersonApi->shouldReceive('getAuthMethods')->andReturn($personResponse);
        $this->instance(\App\Classes\eHealth\Api\Person::class, $mockPersonApi);

        $mockActivityApi = Mockery::mock(\App\Classes\eHealth\Api\CarePlanActivity::class);
        $activityResponse = Mockery::mock(EHealthResponse::class);
        $activityResponse->shouldReceive('successful')->andReturn(true);
        $activityResponse->shouldReceive('getData')->andReturn(['id' => $this->carePlanActivity->uuid]);
        $mockActivityApi->shouldReceive('getDetails')->andReturn($activityResponse);
        $this->instance(\App\Classes\eHealth\Api\CarePlanActivity::class, $mockActivityApi);

        // 2. Fetch the CarePlan
        $carePlan = $this->carePlanActivity->carePlan;
        $this->actingAs($this->user);

        // 3. Test Livewire rendering & initEPrescriptionForm
        Livewire::test(CarePlanActivityShow::class, [
            'carePlan' => $carePlan,
            'activity' => $this->carePlanActivity,
        ])
            ->assertSet('showEPrescriptionDrawer', false)
            ->call('initEPrescriptionForm', $this->carePlanActivity->id)
            ->assertSet('showEPrescriptionDrawer', true)
            ->assertSet('ePrescriptionForm.medication_id', 'INN-101')
            ->assertSet('ePrescriptionForm.medication_qty', 10)
            ->set('ePrescriptionForm.duration', 15)
            ->call('calculateTreatmentDates')
            ->assertSet('ePrescriptionForm.duration', 15);
    }

    public function test_livewire_prescription_cancellation(): void
    {
        $carePlan = $this->carePlanActivity->carePlan;
        $this->actingAs($this->user);

        // Create a mock MedicationRequestRequest in DB
        $uuid = (string) Str::uuid();
        $medicationRequest = \App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest::create([
            'uuid' => $uuid,
            'employee_id' => $this->employee->id,
            'person_id' => $this->person->id,
            'status' => 'active',
            'medication_id' => 'INN-101',
            'medication_qty' => 30.0,
            'intent' => 'order',
            'based_on_id' => $this->carePlanActivity->id,
            'context_id' => $this->encounter->id,
            'request_number' => 'MR-777777',
            'inform_with' => 'otp-method-uuid|OTP|+380991112233'
        ]);

        // Mock eHealth cancel API
        $mockApi = Mockery::mock(MedicationRequestApi::class);
        $cancelResponse = Mockery::mock(EHealthResponse::class);
        $cancelResponse->shouldReceive('successful')->andReturn(true);
        $cancelResponse->shouldReceive('getData')->andReturn(['status' => 'cancelled']);
        $mockApi->shouldReceive('cancel')->once()->andReturn($cancelResponse);
        $this->instance(MedicationRequestApi::class, $mockApi);

        // Mock SignatureService
        $mockSignatureService = Mockery::mock(\App\Services\SignatureService::class);
        $this->instance(\App\Services\SignatureService::class, $mockSignatureService);
        $mockSignatureService->shouldReceive('signData')->andReturn('mock-base64-signature');
        $mockSignatureService->shouldReceive('getCertificateAuthorities')->andReturn([]);

        // Livewire test
        Livewire::test(CarePlanActivityShow::class, [
            'carePlan' => $carePlan,
            'activity' => $this->carePlanActivity,
        ])
            ->call('cancelPrescription', $uuid)
            ->assertSet('showSignatureModal', true)
            ->assertSet('ePrescriptionRequestIdToSign', $uuid)
            ->assertSet('actionType', 'cancel_prescription')
            ->set('statusReason', 'entered-in-error')
            ->set('form.password', '12345678')
            ->set('form.knedp', 'acsk_test')
            ->set('form.keyContainerUpload', \Illuminate\Http\UploadedFile::fake()->create('key.dat', 10))
            ->call('signCancelPrescription')
            ->assertSet('showSignatureModal', false);

        // Assert database updated
        $this->assertDatabaseHas('medication_request_requests', [
            'uuid' => $uuid,
            'status' => 'cancelled'
        ]);
    }

    public function test_livewire_loads_printout_from_ehealth_api(): void
    {
        $carePlan = $this->carePlanActivity->carePlan;
        $this->actingAs($this->user);

        $uuid = (string) Str::uuid();
        \App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest::create([
            'uuid' => $uuid,
            'employee_id' => $this->employee->id,
            'person_id' => $this->person->id,
            'status' => 'active',
            'medication_id' => 'INN-101',
            'medication_qty' => 30.0,
            'intent' => 'order',
            'based_on_id' => $this->carePlanActivity->id,
            'context_id' => $this->encounter->id,
            'request_number' => 'MR-555555',
            'started_at' => '2026-06-01',
            'ended_at' => '2026-09-01',
        ]);

        $mockApi = Mockery::mock(PersonApi::class);
        $printoutResponse = Mockery::mock(EHealthResponse::class);
        $printoutResponse->shouldReceive('getData')->andReturn([
            'printout_form' => '<div>Official eHealth printout</div>',
        ]);
        $mockApi->shouldReceive('getMedicationRequestPrintoutForm')
            ->once()
            ->with($this->person->uuid, $uuid)
            ->andReturn($printoutResponse);
        $this->instance(PersonApi::class, $mockApi);

        Livewire::test(CarePlanActivityShow::class, [
            'carePlan' => $carePlan,
            'activity' => $this->carePlanActivity,
        ])
            ->call('loadPrintoutForm', $uuid)
            ->assertSet('printableContent', '<div>Official eHealth printout</div>')
            ->assertDispatched('printoutLoaded');
    }
}
