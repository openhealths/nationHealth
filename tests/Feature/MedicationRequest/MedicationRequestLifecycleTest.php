<?php

declare(strict_types=1);

namespace Tests\Feature\MedicationRequest;

use App\Classes\eHealth\Api\CarePlanActivity as CarePlanActivityDependency;
use App\Classes\eHealth\Api\Drug;
use App\Classes\eHealth\Api\MedicalProgram;
use App\Classes\eHealth\Api\Patient\MedicationRequest as MedicationRequestApi;
use App\Classes\eHealth\Api\Person as PersonApi;
use App\Classes\eHealth\EHealthResponse;
use App\Livewire\CarePlan\Activity\Show\CarePlanActivityShow;
use App\Models\CarePlanActivity;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Coding;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\MedicalEvents\Sql\Medications\DosageInstruction;

use App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest;
use App\Models\Person\Person;
use App\Models\Relations\Party;
use App\Models\User;
use App\Repositories\MedicalEvents\Repository;
use App\Services\MedicalEvents\Mappers\MedicationRequestMapper;
use App\Services\MedicalEvents\MedicationRequestLifecycleService;
use App\Services\SignatureService;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use ReflectionMethod;
use Tests\Concerns\CreatesFhirCarePlans;
use Tests\Concerns\CreatesFhirRequestIdentifiers;
use Tests\Concerns\MocksBasicDictionaries;
use Tests\Support\GrantsReferralEmployeeRole;
use Tests\Support\NativeClinicalFixtures;
use Tests\TestCase;

class MedicationRequestLifecycleTest extends TestCase
{
    use CreatesFhirCarePlans;
    use CreatesFhirRequestIdentifiers;
    use DatabaseTransactions;
    use GrantsReferralEmployeeRole;
    use MocksBasicDictionaries;

    protected Person $person;
    protected Encounter $encounter;
    protected Employee $employee;
    protected User $user;
    protected CarePlanActivity $carePlanActivity;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement("SET LOCAL TIME ZONE 'UTC'");
        $this->travelTo(CarbonImmutable::parse('2026-10-29 23:30:00', (string) config('app.timezone')));
        $this->mockBasicDictionaries();
        $signatureService = Mockery::mock(SignatureService::class);
        $signatureService->shouldReceive('getCertificateAuthorities')->andReturn([]);
        $this->instance(SignatureService::class, $signatureService);

        // 1. Create Patient
        $this->person = Person::create([
            'uuid' => (string) Str::uuid(),
            'birth_date' => '1985-05-15',
            'gender' => 'MALE',
            'patient_signed' => true,
            'process_disclosure_data_consent' => true,
        ]);

        $this->person->names()->create([
            'first_name' => 'Олексій',
            'last_name' => 'Коваль',
            'language' => 'uk'
        ]);

        // 2. Create Encounter & helpers
        $identifierId = Identifier::create(['value' => (string) Str::uuid()])->id;
        $codingId = Coding::create(['code' => 'AMB', 'system' => 'eHealth/encounter_classes'])->id;
        $ccId = CodeableConcept::create()->id;

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
        $typeId = DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        $legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);
        $this->instance('legalEntity', $legalEntity);

        // 4. Create Employee
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
            'email' => 'ivan' . Str::random(5) . '@example.com',
            'password' => Hash::make('password'),
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

        // A prescription may only be issued off a today's finished encounter performed by
        // this doctor (TV 3.9.1.1.2), so the fixture has to satisfy that gate.
        $performer = Identifier::create(['value' => $this->employee->uuid]);
        $this->encounter->update(['performer_id' => $performer->id]);
        $this->encounter->period()->create([
            'start' => now()->startOfDay()->utc(),
            'end' => now()->utc(),
        ]);

        if (config('permission.teams')) {
            setPermissionsTeamId($legalEntity->id);
        }

        $this->grantMedicalEventAbilities($this->user);
        $this->grantReferralEmployeeRole($this->user, $this->employee);

        // 5. Create Care Plan & Medication Care Plan Activity
        $carePlan = $this->createFhirCarePlan([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->employee->legal_entity_id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Affordable Medicines Plan',
            'status' => 'active',
        ]);
        $this->grantCarePlanWriteApproval($carePlan, $this->employee);

        $this->carePlanActivity = $this->createFhirCarePlanActivity([
            'uuid' => (string) Str::uuid(),
            'care_plan_id' => $carePlan->id,
            'author_id' => $this->employee->id,
            'status' => 'scheduled',
            'kind' => 'medication_request',
            'product_reference' => 'a3968b0d-ab41-4121-b295-6684ebc3e5b2',
            'quantity' => 60.0,
            'program' => '8d08ce22-82dd-4ac4-bdb9-432fd5aad5dd',
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
            'medication_id' => 'a3968b0d-ab41-4121-b295-6684ebc3e5b2',
            'medication_qty' => 60.0,
            'medication_program_id' => 'program-affordable-medicines',
            'intent' => 'order',
            // Repository resolves Identifier FKs from UUIDs (not activity/encounter row ids).
            'based_on_uuid' => $this->carePlanActivity->uuid,
            'context_uuid' => $this->encounter->uuid,
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

        $expectedBasedOnId = $this->fhirIdentifierId((string) $this->carePlanActivity->uuid);

        $this->assertDatabaseHas('medication_request_requests', [
            'id' => $id,
            'uuid' => $uuid,
            'medication_id' => 'a3968b0d-ab41-4121-b295-6684ebc3e5b2',
            'person_id' => $this->person->id,
            'based_on_id' => $expectedBasedOnId,
        ]);

        $this->assertDatabaseHas('dosage_instructions', [
            'medication_request_request_id' => $id,
            'sequence' => 1,
            'patient_instruction' => 'Take with water'
        ]);
        $dosage = DosageInstruction::whereMedicationRequestRequestId($id)->firstOrFail();
        $this->assertSame('26643006', data_get($dosage->route, 'coding.0.code'));
        $this->assertSame('eHealth/SNOMED/route_codes', data_get($dosage->route, 'coding.0.system'));
    }

    public function test_can_map_to_fhir_payload(): void
    {
        $mapper = new MedicationRequestMapper();

        $payload = [
            'uuid' => (string) Str::uuid(),
            'status' => 'draft',
            'intent' => 'order',
            'medication_id' => 'a3968b0d-ab41-4121-b295-6684ebc3e5b2',
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
        $this->assertEquals('a3968b0d-ab41-4121-b295-6684ebc3e5b2', $fhir['medicationReference']['identifier']['value']);
        $this->assertEquals('program-affordable-medicines', $fhir['program']['identifier']['value']);
        $this->assertEquals($this->carePlanActivity->uuid, $fhir['basedOn'][0]['identifier']['value']);
        $this->assertEquals('Take in the morning', $fhir['note']);
        $this->assertEquals(1.0, $fhir['dosageInstruction'][0]['doseAndRate'][0]['doseQuantity']['value']);
    }

    public function test_create_request_payload_uses_snomed_route_and_object_dose_and_rate(): void
    {
        $mapper = new MedicationRequestMapper();

        $payload = $mapper->toCreateRequestPayload(
            [
                'medication_id' => 'a3968b0d-ab41-4121-b295-6684ebc3e5b2',
                'medication_qty' => 10.0,
                'started_at' => '2026-06-24',
                'ended_at' => '2026-07-03',
                'based_on_uuid' => $this->carePlanActivity->uuid,
                'dosage_instructions' => [
                    [
                        'sequence' => 1,
                        'text' => '1 tablet daily',
                        'route' => 'oral',
                        'as_needed_boolean' => false,
                        'dose_and_rate' => [
                            [
                                'dose_quantity_value' => 1.0,
                                'dose_quantity_unit' => 'tab',
                                'type' => ['coding' => [['system' => 'eHealth/dose_and_rate', 'code' => 'ordered']]],
                                'rate_ratio' => ['numerator' => ['value' => 1, 'unit' => 'tab'], 'denominator' => ['value' => 1, 'unit' => 'd']],
                            ],
                        ],
                        'max_dose_per_administration' => ['value' => 1.0, 'unit' => 'tab'],
                        'max_dose_per_period' => ['numerator' => ['value' => 1.0, 'unit' => 'tab'], 'denominator' => ['value' => 1.0, 'unit' => 'd']],
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
        $createResponse->shouldReceive('getData', 'validate')->andReturn([
            'id' => $requestId,
            'status' => 'NEW',
            'request_number' => 'MR-1234567'
        ]);
        $createResponse->shouldReceive('getStatusCode')->andReturn(201);
        $mockApi->shouldReceive('createRequest')->once()->andReturn($createResponse);

        // 2. Mock Sign Request
        $signResponse = Mockery::mock(EHealthResponse::class);
        $signResponse->shouldReceive('getData', 'validate')->andReturn([
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
        $mockDrugApi = Mockery::mock(Drug::class);
        $drugResponse = Mockery::mock(EHealthResponse::class);
        $drugResponse->shouldReceive('getData', 'validate')->andReturn([
            [
                'id' => 'a3968b0d-ab41-4121-b295-6684ebc3e5b2',
                'name' => 'Test Drug',
                'innm_dosage_form' => 'ml',
                'ingredients' => [['id' => 'ingredient-1', 'name' => 'Ingredient', 'is_primary' => true, 'dosage' => ['numerator_value' => 1.0, 'numerator_unit' => 'MG', 'denumerator_value' => 1.0, 'denumerator_unit' => 'ML']]],
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
        $this->instance(Drug::class, $mockDrugApi);

        $mockProgramApi = Mockery::mock(MedicalProgram::class);
        $programResponse = Mockery::mock(EHealthResponse::class);
        $programResponse->shouldReceive('getData', 'validate')->andReturn([
            [
                'id' => '8d08ce22-82dd-4ac4-bdb9-432fd5aad5dd',
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
        $this->instance(MedicalProgram::class, $mockProgramApi);

        $mockPersonApi = Mockery::mock(PersonApi::class);
        $personResponse = Mockery::mock(EHealthResponse::class);
        $personResponse->shouldReceive('getData', 'validate')->andReturn([
            [
                'uuid' => 'auth-1',
                'type' => 'OTP',
                'phone_number' => '+380991112233'
            ]
        ]);
        $personResponse->shouldReceive('getStatusCode')->andReturn(200);
        $mockPersonApi->shouldReceive('getAuthMethods')->andReturn($personResponse);
        $this->instance(PersonApi::class, $mockPersonApi);

        $mockActivityApi = Mockery::mock(CarePlanActivityDependency::class);
        // The registration guard and remaining quantity lookup validate the same native resource.
        $activityData = [
            'id' => $this->carePlanActivity->uuid,
            'care_plan' => NativeClinicalFixtures::reference('care_plan', $this->carePlanActivity->carePlan->uuid),
            'author' => [NativeClinicalFixtures::reference('employee', $this->employee->uuid)],
            'detail' => [
                'kind' => 'medication_request', 'status' => 'scheduled', 'do_not_perform' => false,
                'product_reference' => NativeClinicalFixtures::reference('medication', 'a3968b0d-ab41-4121-b295-6684ebc3e5b2'),
                'remaining_quantity' => ['value' => 30, 'system' => 'MEDICATION_UNIT', 'code' => 'ML'],
                'remaining_quantity_type' => 'for_request',
            ],
            'inserted_at' => now()->utc()->toIso8601String(), 'updated_at' => now()->utc()->toIso8601String(),
            'inserted_by' => $this->employee->uuid, 'updated_by' => $this->employee->uuid,
        ];
        $validator = new ReflectionMethod(CarePlanActivityDependency::class, 'validateDetails');
        $activityApi = new CarePlanActivityDependency();
        $activityResponse = new EHealthResponse(
            new Response(200, [], json_encode(['data' => $activityData], JSON_THROW_ON_ERROR)),
            static fn (EHealthResponse $response): array => $validator->invoke($activityApi, $response)
        );
        $mockActivityApi->shouldReceive('getDetails')->andReturn($activityResponse);
        $this->instance(CarePlanActivityDependency::class, $mockActivityApi);

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
            ->assertSet('ePrescriptionRemainingQty', 30.0)
            ->assertSet('ePrescriptionForm.medication_id', 'a3968b0d-ab41-4121-b295-6684ebc3e5b2')
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
        $fhirRefs = $this->fhirBasedOnContextIds(
            (string) $this->carePlanActivity->uuid,
            (string) $this->encounter->uuid,
        );
        $medicationRequest = MedicationRequestRequest::create([
            'uuid' => $uuid,
            'employee_id' => $this->employee->id,
            'person_id' => $this->person->id,
            'status' => 'active',
            'medication_id' => 'a3968b0d-ab41-4121-b295-6684ebc3e5b2',
            'medication_qty' => 30.0,
            'intent' => 'order',
            'based_on_id' => $fhirRefs['based_on_id'],
            'context_id' => $fhirRefs['context_id'],
            'request_number' => 'MR-777777',
            'inform_with' => 'otp-method-uuid|OTP|+380991112233'
        ]);

        // Avoid Mockery alias mocks — they break when the API class is already loaded by earlier suites.
        $lifecycle = Mockery::mock(MedicationRequestLifecycleService::class);
        $lifecycle->shouldIgnoreMissing();
        $lifecycle->shouldReceive('rejectPrescription')
            ->once()
            ->andReturnUsing(function ($carePlan, $requestRecord, array $formData = [], string $statusReason = '') {
                $requestRecord->update(['status' => 'rejected']);

                return ['status' => 'rejected'];
            });
        $this->instance(MedicationRequestLifecycleService::class, $lifecycle);

        // Mock SignatureService (KEP modal still fills form fields before signRejectPrescription).
        $mockSignatureService = Mockery::mock(SignatureService::class);
        $this->instance(SignatureService::class, $mockSignatureService);
        $mockSignatureService->shouldReceive('signData')->andReturn('mock-base64-signature');
        $mockSignatureService->shouldReceive('getCertificateAuthorities')->andReturn([]);

        // Livewire test
        Livewire::test(CarePlanActivityShow::class, [
            'carePlan' => $carePlan,
            'activity' => $this->carePlanActivity,
        ])
            ->call('rejectPrescription', $uuid)
            ->assertSet('showSignatureModal', true)
            ->assertSet('ePrescriptionRequestIdToSign', $uuid)
            ->assertSet('actionType', 'reject_prescription')
            ->set('statusReason', 'entered-in-error')
            ->set('form.password', '12345678')
            ->set('form.knedp', 'acsk_test')
            ->set('form.keyContainerUpload', UploadedFile::fake()->create('key.dat', 10))
            ->call('signRejectPrescription')
            ->assertSet('showSignatureModal', false);

        // Assert database updated
        $this->assertDatabaseHas('medication_request_requests', [
            'uuid' => $uuid,
            'status' => 'rejected'
        ]);
    }

    public function test_livewire_loads_printout_from_ehealth_api(): void
    {
        $carePlan = $this->carePlanActivity->carePlan;
        $this->actingAs($this->user);

        $uuid = (string) Str::uuid();
        $fhirRefs = $this->fhirBasedOnContextIds(
            (string) $this->carePlanActivity->uuid,
            (string) $this->encounter->uuid,
        );
        MedicationRequestRequest::create([
            'uuid' => $uuid,
            'employee_id' => $this->employee->id,
            'person_id' => $this->person->id,
            'status' => 'active',
            'resource_type' => MedicationRequestRequest::TYPE_PRESCRIPTION,
            'medication_id' => 'a3968b0d-ab41-4121-b295-6684ebc3e5b2',
            'medication_qty' => 30.0,
            'intent' => 'order',
            'based_on_id' => $fhirRefs['based_on_id'],
            'context_id' => $fhirRefs['context_id'],
            'request_number' => 'MR-555555',
            'started_at' => '2026-06-01',
            'ended_at' => '2026-09-01',
        ]);

        $mockApi = Mockery::mock(MedicationRequestApi::class);
        $detailResponse = Mockery::mock(EHealthResponse::class);
        $detailResponse->shouldReceive('validate')->andReturn(['id' => $uuid, 'person' => ['id' => $this->person->uuid], 'status' => 'ACTIVE']);
        $mockApi->shouldReceive('getById')->once()->with($this->person->uuid, $uuid)->andReturn($detailResponse);
        $printoutResponse = Mockery::mock(EHealthResponse::class);
        $printoutResponse->shouldReceive('validate')->andReturn([
            'id' => $uuid, 'printout_form' => '<div>Official eHealth printout</div>',
        ]);
        $mockApi->shouldReceive('getPrintoutForm')
            ->once()
            ->with($this->person->uuid, $uuid)
            ->andReturn($printoutResponse);
        $this->instance(MedicationRequestApi::class, $mockApi);

        Livewire::test(CarePlanActivityShow::class, [
            'carePlan' => $carePlan,
            'activity' => $this->carePlanActivity,
        ])
            ->call('loadPrintoutForm', $uuid)
            ->assertSet('printableContent', '<div>Official eHealth printout</div>')
            ->assertDispatched('printoutLoaded');
    }
}
