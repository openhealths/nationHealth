<?php

declare(strict_types=1);

namespace Tests\Feature\CarePlan;

use App\Classes\eHealth\Api\Approval;
use App\Classes\eHealth\Api\CarePlan as CarePlanApi;
use App\Classes\eHealth\Api\CarePlanActivity as CarePlanActivityApi;
use App\Classes\eHealth\Api\DeviceDefinition;
use App\Classes\eHealth\Api\Job;
use App\Classes\eHealth\Api\Person as PersonApi;
use App\Classes\eHealth\EHealthResponse;
use App\Core\Arr;
use App\Enums\Person\AuthenticationMethod;
use App\Enums\Person\ConditionClinicalStatus;
use App\Enums\Person\ConditionVerificationStatus;

use App\Enums\Status;
use App\Enums\User\Role;
use App\Jobs\RemoteEHealthLinksProcessing;
use App\Livewire\CarePlan\CarePlanCreate;
use App\Livewire\CarePlan\CarePlanShow;
use App\Livewire\CarePlan\CarePlanUpdate;
use App\Livewire\DeviceRequest\DeviceRequestForm;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Coding;
use App\Models\MedicalEvents\Sql\Condition;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\EncounterDiagnose;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\Person\Person;
use App\Models\Relations\Party;
use App\Models\User;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use App\Repositories\Repository;
use App\Services\Dictionary\Dictionaries\MedicalProgramDictionary;
use App\Services\Dictionary\DictionaryManager;
use App\Services\MedicalEvents\DeviceRequestLifecycleService;
use App\Services\SignatureService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Concerns\CreatesFhirCarePlans;
use Tests\Concerns\MocksBasicDictionaries;
use Tests\TestCase;

class CarePlanLifecycleTest extends TestCase
{
    use CreatesFhirCarePlans;

    use DatabaseTransactions;
    use MocksBasicDictionaries;

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
        $this->mockBasicDictionaries();
        Bus::fake();
        Notification::fake();

        config(['cipher.api.domain' => 'https://cipher.invalid']);
        Cache::put('knedp_certificate_authority', [], 60);

        // Setup initial data
        $this->person = Person::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'John',
            'last_name' => 'Doe',
            'birth_date' => '1990-01-01',
            'gender' => 'MALE',
            'patient_signed' => true,
            'process_disclosure_data_consent' => true,
        ]);

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

        $conditionIdentifier = Identifier::create([
            'value' => (string) Str::uuid()
        ]);
        $conditionCc = CodeableConcept::create();
        $conditionCc->save();

        $conditionCoding = new Coding();
        $conditionCoding->code = 'D02';
        $conditionCoding->system = 'eHealth/ICPC2/condition_codes';
        $conditionCoding->codeable_type = CodeableConcept::class;
        $conditionCoding->codeable_id = $conditionCc->id;
        $conditionCoding->save();

        $condition = Condition::create([
            'uuid' => $conditionIdentifier->value,
            'person_id' => $this->person->id,
            'primary_source' => true,
            'clinical_status' => ConditionClinicalStatus::ACTIVE,
            'verification_status' => ConditionVerificationStatus::CONFIRMED,
            'code_id' => $conditionCc->id,
            'context_id' => $identifierId,
            'onset_date' => now(),
        ]);

        $diagnosisRole = MedicalEventsRepository::codeableConcept()->store([
            'coding' => [['system' => 'eHealth/diagnosis_roles', 'code' => 'primary']],
        ]);
        EncounterDiagnose::create([
            'encounter_id' => $this->encounter->id,
            'condition_id' => $conditionIdentifier->id,
            'role_id' => $diagnosisRole->id,
            'rank' => 1
        ]);

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

        $this->party = Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'House',
            'last_name' => 'Doctor',
            'tax_id' => '1234567890',
            'birth_date' => '1970-01-01',
            'gender' => 'MALE',
        ]);

        $this->user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'house' . Str::random(5) . '@example.com',
            'password' => Hash::make('password'),
            'party_id' => $this->party->id,
        ]);

        $this->employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Dr. House',
            'employee_type' => Role::DOCTOR->value,
            'status' => Status::APPROVED->value,
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => 'Doctor',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $this->user->id,
            'party_id' => $this->party->id,
        ]);

        $this->user->employees()->attach($this->employee->id);

        if (config('permission.teams')) {
            setPermissionsTeamId($legalEntity->id);
        }

        $this->grantMedicalEventAbilities($this->user);
        $this->grantCarePlanEmployeeRole($this->employee, 'OUTPATIENT');
        $this->encounter->update([
            'performer_id' => Identifier::create(['value' => $this->employee->uuid])->id,
        ]);
        Episode::create([
            'uuid' => $this->encounter->episode->value,
            'person_id' => $this->person->id,
            'status' => 'active',
            'name' => 'Амбулаторне лікування',
            'managing_organization_id' => Identifier::create(['value' => $legalEntity->uuid])->id,
        ]);
        $this->fakeMedicalPrograms();
    }

    public function test_category_mismatch_is_rejected_before_opening_kep_and_before_signing(): void
    {
        $this->actingAs($this->user);
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('getData', 'validate')->andReturn([]);
        $this->mock(PersonApi::class)->shouldReceive('getAuthMethods')->andReturn($response);
        $signature = $this->mock(SignatureService::class);
        $signature->shouldReceive('getCertificateAuthorities')->andReturn([]);
        $signature->shouldNotReceive('signData');
        $this->mock(CarePlanApi::class)->shouldNotReceive('create');

        Livewire::test(CarePlanCreate::class, [
            'legalEntity' => legalEntity(), 'personId' => $this->person->id,
        ])->set('form.encounter', $this->encounter->uuid)
            ->assertSet('form.category', 'class_18')
            ->assertSet('allowedCategoryCodes', ['class_18'])
            ->set('form.title', 'Invalid category plan')
            ->set('form.category', 'class_1')
            ->call('startSigningProcess')->assertHasErrors(['form.category'])
            ->assertSet('showSignatureModal', false)
            ->set('form.knedp', 'test-authority')->set('form.password', 'test-password')
            ->set('form.keyContainerUpload', UploadedFile::fake()->create('key.jks', 100))
            ->call('sign')->assertHasErrors(['form.category'])
            ->assertSet('showSignatureModal', false);

        $this->assertSame(0, CarePlan::wherePersonId($this->person->id)->count());
    }

    public function test_full_care_plan_lifecycle_flow(): void
    {
        $this->actingAs($this->user);
        $carePlanUuid = (string) Str::uuid();
        $approvalUuid = (string) Str::uuid();
        $activityUuid = (string) Str::uuid();
        $approvalId = (string) Str::uuid(); // Use different ID for internal approval ID if needed

        $mockCarePlanApi = Mockery::mock(CarePlanApi::class)->makePartial();
        $mockApprovalApi = Mockery::mock(Approval::class);
        $mockPatientApi = Mockery::mock(PersonApi::class);
        $mockJobApi = Mockery::mock(Job::class);
        $mockJobApi->shouldReceive('withToken')->andReturnSelf();
        $mockActivityApi = Mockery::mock(CarePlanActivityApi::class)->makePartial();
        $mockSignatureService = Mockery::mock(SignatureService::class);

        // Bind mocks to container
        $this->instance(CarePlanApi::class, $mockCarePlanApi);
        $this->instance(Approval::class, $mockApprovalApi);
        $this->instance(PersonApi::class, $mockPatientApi);
        $this->instance(Job::class, $mockJobApi);
        $this->instance(CarePlanActivityApi::class, $mockActivityApi);
        $this->instance(SignatureService::class, $mockSignatureService);

        // Mock signature
        $mockSignatureService->shouldReceive('signData')->andReturn('mock-base64-signature');
        $mockSignatureService->shouldReceive('getCertificateAuthorities')->andReturn([]);

        // 1. Mock Care Plan Creation
        $cpCreateResponse = Mockery::mock(EHealthResponse::class);
        $cpCreateResponse->shouldReceive('getData', 'validate')->andReturn(['job_id' => 'job-123']);
        $cpCreateResponse->shouldReceive('getStatusCode')->andReturn(202);
        $mockCarePlanApi->shouldReceive('create')->andReturn($cpCreateResponse);

        // Mock Job Details
        $jobResponse = Mockery::mock(EHealthResponse::class);
        $jobResponse->shouldReceive('getStatusCode')->andReturn(200);
        $jobResponse->shouldReceive('json')->andReturn([]);
        $jobResponse->shouldReceive('isNotLast')->andReturn(false);
        $jobResponse->shouldReceive('getData', 'validate')->andReturn([
            'status' => 'processed',
            'id' => $carePlanUuid,
            'result' => [
                'id' => $carePlanUuid,
                'status' => 'active'
            ]
        ]);
        $mockJobApi->shouldReceive('getDetails')->andReturn($jobResponse);

        // 2. Mock Approval Flow
        $approvalCreateResponse = Mockery::mock(EHealthResponse::class);
        $approvalCreateResponse->shouldReceive('getData', 'validate')->andReturn(['id' => $approvalUuid, 'status' => 'NEW']);
        $approvalCreateResponse->shouldReceive('getStatusCode')->andReturn(201);
        $mockApprovalApi->shouldReceive('createApproval')->andReturn($approvalCreateResponse);

        $approvalVerifyResponse = Mockery::mock(EHealthResponse::class);
        $approvalVerifyResponse->shouldReceive('getData', 'validate')->andReturn(['id' => $approvalUuid, 'status' => 'GRANTED']);
        $approvalVerifyResponse->shouldReceive('getStatusCode')->andReturn(200);
        $mockApprovalApi->shouldReceive('verify')->andReturn($approvalVerifyResponse);

        $approvalsResponse = Mockery::mock(EHealthResponse::class);
        $approvalsResponse->shouldReceive('getData', 'validate')->andReturn([
            [
                'id' => $approvalUuid,
                'status' => 'NEW',
                'reason' => 'treatment_plan',
                'granted_to' => ['identifier' => ['value' => $this->employee->uuid]],
            ]
        ]);
        $approvalsResponse->shouldReceive('getStatusCode')->andReturn(200);
        $mockApprovalApi->shouldReceive('getMany')->andReturn($approvalsResponse);

        // Mock auth methods
        $authResponse = Mockery::mock(EHealthResponse::class);
        $authResponse->shouldReceive('getData', 'validate')->andReturn([
            [
                'id' => 'otp-uuid',
                'type' => AuthenticationMethod::OTP->value,
                'phone_number' => '+380991112233'
            ]
        ]);
        $authResponse->shouldReceive('getStatusCode')->andReturn(200);
        $mockPatientApi->shouldReceive('getAuthMethods')->andReturn($authResponse);

        // 3. Test CarePlanCreate Component
        Livewire::test(CarePlanCreate::class, [
            'legalEntity' => LegalEntity::first(),
            'personId' => $this->person->id,
        ])
            ->set('form.encounter', $this->encounter->uuid)
            ->set('carePlanUuid', $carePlanUuid)
            ->set('form.title', 'Test Plan')
            ->set('form.category', 'class_18')
            ->set('form.intent', 'order')
            ->set('form.termsOfService', '736382003')
            ->set('form.periodStart', now()->format('d.m.Y'))
            ->set('form.knedp', '1.2.3.4')
            ->set('form.password', 'secret')
            ->set('form.keyContainerUpload', UploadedFile::fake()->create('key.jks', 100))
            ->call('sign')
            ->assertHasNoErrors();

        // Verify Care Plan is in DB
        $carePlan = CarePlan::where('uuid', $carePlanUuid)->first();

        $this->assertFhirCarePlanStored([
            'uuid' => $carePlanUuid,
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'terms_of_service' => 'OUTPATIENT',
        ]);

        $carePlan = CarePlan::where('uuid', $carePlanUuid)->first();

        $submission = $carePlan->ehealthLinks()->latest('id')->firstOrFail();
        $this->assertSame('draft', $carePlan->status);
        $this->assertSame('PENDING', $submission->status);
        RemoteEHealthLinksProcessing::finishSubmission($submission, [
            'status' => 'processed', 'result' => ['id' => $carePlanUuid, 'status' => 'active'],
        ], $this->user);
        $carePlan->refresh();
        $this->grantCarePlanWriteApproval($carePlan, $this->employee);

        // 4. Test CarePlanShow - Adding Activity
        Livewire::test(CarePlanShow::class, ['carePlan' => $carePlan])
            ->call('initActivityForm', 'service_request')
            ->set('activityForm.kind', 'service_request')
            ->set('activityForm.product_reference', '4fbe6a29-5ffb-4bde-be83-7c968ee12e25')
            ->set('activityForm.quantity', 1)
            ->set('activityForm.scheduled_period_start', now()->format('d.m.Y'))
            ->set('activityForm.scheduled_period_end', now()->addDays(7)->format('d.m.Y'))
            ->call('saveActivity')
            ->assertHasNoErrors();

        $this->assertFhirActivityStored([
            'care_plan_id' => $carePlan->id,
            'status' => 'draft',
        ]);

        $activity = CarePlanActivity::where('care_plan_id', $carePlan->id)->first();
        $activityUuid = $activity->uuid;

        // 5. Test CarePlanShow - Signing Activity & Auto-Activation
        $activitySignResponse = Mockery::mock(EHealthResponse::class);
        $activitySignResponse->shouldReceive('getData', 'validate')->andReturn(['id' => $activityUuid, 'status' => 'scheduled']);
        $activitySignResponse->shouldReceive('getStatusCode')->andReturn(200);
        $mockActivityApi->shouldReceive('create')->once()->andReturn($activitySignResponse);

        $activitySummaryResponse = Mockery::mock(EHealthResponse::class);
        $activitySummaryResponse->shouldReceive('getData', 'validate')->andReturn(['data' => []]);
        $activitySummaryResponse->shouldReceive('getStatusCode')->andReturn(200);
        $mockActivityApi->shouldReceive('getSummary')->andReturn($activitySummaryResponse);

        // Mock sync response showing plan is now ACTIVE
        $syncResponse = Mockery::mock(EHealthResponse::class);
        $syncResponse->shouldReceive('getData', 'validate')->andReturn([[
            'uuid' => $carePlanUuid,
            'status' => 'active',
            'title' => 'Test Plan',
            'period' => ['start' => now()->toIso8601String()],
            'terms_of_service' => ['coding' => [['code' => 'OUTPATIENT']]]
        ]]);
        $syncResponse->shouldReceive('getStatusCode')->andReturn(200);
        $mockCarePlanApi->shouldReceive('getBySearchParams')->andReturn($syncResponse);

        Livewire::test(CarePlanShow::class, ['carePlan' => $carePlan])
            ->set('form.knedp', '1.2.3.4')
            ->set('form.password', 'secret')
            ->set('form.keyContainerUpload', UploadedFile::fake()->create('key.jks', 100))
            ->call('openSignatureModal', 'sign_activity', $activity->id)
            ->call('sign')
            ->assertHasNoErrors();

        // Verify Activity is now SIGNED in local DB
        $this->assertFhirActivityStored([
            'uuid' => $activityUuid,
            'id' => $activity->id,
            'status' => 'scheduled',
        ]);

        // Verify Plan is now ACTIVE in local DB
        $this->assertFhirCarePlanStored([
            'id' => $carePlan->id,
            'status' => 'active',
        ]);
    }

    public function test_create_service_activity_with_linked_grounds(): void
    {
        $this->actingAs($this->user);

        $carePlan = $this->createFhirCarePlan([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->employee->legal_entity_id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Service Plan',
            'status' => 'active',
        ]);
        $this->grantCarePlanWriteApproval($carePlan, $this->employee);

        $condition = Condition::first();

        // Bind mock APIs to satisfy dependencies
        $mockActivityApi = Mockery::mock(CarePlanActivityApi::class)->makePartial();
        $this->instance(CarePlanActivityApi::class, $mockActivityApi);

        $activityCreateResponse = Mockery::mock(EHealthResponse::class);
        $activityCreateResponse->shouldReceive('getData', 'validate')->andReturn(['id' => (string) Str::uuid(), 'status' => 'scheduled']);
        $activityCreateResponse->shouldReceive('getStatusCode')->andReturn(201);
        $mockActivityApi->shouldReceive('create')->andReturn($activityCreateResponse);

        Livewire::test(CarePlanShow::class, ['carePlan' => $carePlan])
            ->call('initActivityForm', 'service_request')
            ->call('selectProduct', ['code' => 'A01001', 'name' => 'General medical consultation'], 'service_request')
            ->set('activityForm.product_reference', 'A01001')
            ->call('addLinkedGround', 'Condition', $condition->uuid)
            ->assertSet('linkedGrounds.0.uuid', $condition->uuid)
            ->set('activityForm.quantity', 2)
            ->set('activityForm.scheduled_period_start', now()->format('d.m.Y'))
            ->set('activityForm.scheduled_period_end', now()->addDays(7)->format('d.m.Y'))
            ->call('saveActivity')
            ->assertHasNoErrors();

        $this->assertFhirActivityStored([
            'care_plan_id' => $carePlan->id,
            'product_reference' => 'A01001',
            'quantity' => 2,
        ]);
    }

    public function test_create_medication_activity_with_program_and_linked_grounds(): void
    {
        $this->actingAs($this->user);

        $carePlan = $this->createFhirCarePlan([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->employee->legal_entity_id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Medication Plan',
            'status' => 'active',
        ]);
        $this->grantCarePlanWriteApproval($carePlan, $this->employee);

        $condition = Condition::first();

        $mockActivityApi = Mockery::mock(CarePlanActivityApi::class)->makePartial();
        $this->instance(CarePlanActivityApi::class, $mockActivityApi);

        $activityCreateResponse = Mockery::mock(EHealthResponse::class);
        $activityCreateResponse->shouldReceive('getData', 'validate')->andReturn(['id' => (string) Str::uuid(), 'status' => 'scheduled']);
        $activityCreateResponse->shouldReceive('getStatusCode')->andReturn(201);
        $mockActivityApi->shouldReceive('create')->andReturn($activityCreateResponse);

        $medicationId = '02b5e4de-22ec-429d-81f2-8faf44bd8c92';

        Livewire::test(CarePlanShow::class, ['carePlan' => $carePlan])
            ->call('initActivityForm', 'medication_request')
            ->set('selectedProgram', '1318eabc-1a1a-42f6-8450-61e11c19eede')
            ->set('activityForm.program', '1318eabc-1a1a-42f6-8450-61e11c19eede')
            ->call('selectProduct', [
                'id' => $medicationId,
                'name' => 'Aspirin',
                'ingredients' => [
                    ['dosage' => ['denumerator_unit' => 'PIECE']],
                ],
            ], 'medication_request')
            ->set('activityForm.product_reference', $medicationId)
            ->set('activityForm.quantity_system', 'MEDICATION_UNIT')
            ->set('activityForm.quantity_code', 'PIECE')
            ->call('addLinkedGround', 'Condition', $condition->uuid)
            ->set('activityForm.quantity', 30)
            ->set('activityForm.daily_amount', 1.5)
            ->set('activityForm.scheduled_period_start', now()->format('d.m.Y'))
            ->set('activityForm.scheduled_period_end', now()->addDays(7)->format('d.m.Y'))
            ->call('saveActivity')
            ->assertHasNoErrors();

        $this->assertFhirActivityStored([
            'care_plan_id' => $carePlan->id,
            'product_reference' => $medicationId,
            'program' => '1318eabc-1a1a-42f6-8450-61e11c19eede',
            'quantity' => 30,
        ]);
    }

    public function test_create_device_activity_with_positive_quantity_validation(): void
    {
        $this->actingAs($this->user);

        $carePlan = $this->createFhirCarePlan([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->employee->legal_entity_id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Device Plan',
            'status' => 'active',
        ]);
        $this->grantCarePlanWriteApproval($carePlan, $this->employee);

        $mockActivityApi = Mockery::mock(CarePlanActivityApi::class)->makePartial();
        $this->instance(CarePlanActivityApi::class, $mockActivityApi);

        $activityCreateResponse = Mockery::mock(EHealthResponse::class);
        $activityCreateResponse->shouldReceive('getData', 'validate')->andReturn(['id' => (string) Str::uuid(), 'status' => 'scheduled']);
        $activityCreateResponse->shouldReceive('getStatusCode')->andReturn(201);
        $mockActivityApi->shouldReceive('create')->andReturn($activityCreateResponse);

        $deviceUuid = (string) Str::uuid();
        $deviceProgram = '85953838-1834-4ed6-8bf4-3f83057380ec';
        $device = [
            'id' => $deviceUuid, 'code' => 'DEV-456', 'name' => 'Test strips',
            'classification_types' => [['system' => 'device_definition_classification_type', 'code' => '30221']],
            'packaging' => ['packaging_count' => 10, 'packaging_type' => 'box', 'packaging_unit' => 'piece'],
            'program_devices' => [['id' => (string) Str::uuid(), 'care_plan_activity_allowed' => true, 'max_daily_count' => 10]],
        ];
        $response = $this->deviceDefinitionResponse(['data' => [$device]]);
        $this->mock(DeviceDefinition::class)->shouldReceive('getMany')->andReturn($response);

        // Validation error for negative or zero quantity
        Livewire::test(CarePlanShow::class, ['carePlan' => $carePlan])
            ->call('initActivityForm', 'device_request')
            ->set('selectedProgram', $deviceProgram)
            ->set('activityForm.program', $deviceProgram)
            ->call('selectProduct', $device, 'device_request')
            ->set('activityForm.product_reference', $deviceUuid)
            ->set('activityForm.quantity', -5)
            ->set('activityForm.scheduled_period_start', now()->format('d.m.Y'))
            ->set('activityForm.scheduled_period_end', now()->addDays(7)->format('d.m.Y'))
            ->call('saveActivity')
            ->assertHasErrors(['activityForm.quantity']);

        // Success when positive integer
        Livewire::test(CarePlanShow::class, ['carePlan' => $carePlan])
            ->call('initActivityForm', 'device_request')
            ->set('selectedProgram', $deviceProgram)
            ->set('activityForm.program', $deviceProgram)
            ->call('selectProduct', $device, 'device_request')
            ->set('activityForm.product_reference', $deviceUuid)
            ->set('activityForm.quantity', 10)
            ->set('activityForm.scheduled_period_start', now()->format('d.m.Y'))
            ->set('activityForm.scheduled_period_end', now()->addDays(7)->format('d.m.Y'))
            ->call('saveActivity')
            ->assertHasNoErrors();

        $this->assertFhirActivityStored([
            'care_plan_id' => $carePlan->id,
            'product_reference' => $deviceUuid,
            'quantity' => 10,
        ]);
    }

    public function test_create_device_activity_without_medical_program(): void
    {
        $this->actingAs($this->user);

        $carePlan = $this->createFhirCarePlan([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->employee->legal_entity_id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Device Without Program Plan',
            'status' => 'active',
        ]);
        $this->grantCarePlanWriteApproval($carePlan, $this->employee);

        $mockActivityApi = Mockery::mock(CarePlanActivityApi::class)->makePartial();
        $this->instance(CarePlanActivityApi::class, $mockActivityApi);

        $activityCreateResponse = Mockery::mock(EHealthResponse::class);
        $activityCreateResponse->shouldReceive('getData', 'validate')->andReturn(['id' => (string) Str::uuid(), 'status' => 'scheduled']);
        $activityCreateResponse->shouldReceive('getStatusCode')->andReturn(201);
        $mockActivityApi->shouldReceive('create')->andReturn($activityCreateResponse);

        $deviceUuid = (string) Str::uuid();

        Livewire::test(CarePlanShow::class, ['carePlan' => $carePlan])
            ->call('initActivityForm', 'device_request')
            ->assertSet('selectedProgram', '')
            ->call('selectProduct', [
                'id' => $deviceUuid,
                'code' => 'DEV-NO-PROGRAM',
                'name' => 'Strips without program',
                'is_active' => true,
                'packaging' => ['packaging_count' => 50, 'packaging_unit' => 'piece'],
            ], 'device_request')
            ->assertSet('activityForm.program', null)
            ->set('activityForm.product_reference', $deviceUuid)
            ->set('activityForm.quantity', 50)
            ->set('activityForm.scheduled_period_start', now()->format('d.m.Y'))
            ->set('activityForm.scheduled_period_end', now()->addMonths(3)->format('d.m.Y'))
            ->call('saveActivity')
            ->assertHasNoErrors();

        $this->assertFhirActivityStored([
            'care_plan_id' => $carePlan->id,
            'product_reference' => $deviceUuid,
            'quantity' => 50,
            'program' => null,
        ]);
    }

    public function test_cancel_and_complete_care_plan_activity(): void
    {
        $this->actingAs($this->user);

        $carePlan = $this->createFhirCarePlan([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->employee->legal_entity_id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Lifecycle Plan',
            'status' => 'active',
        ]);
        $this->grantCarePlanWriteApproval($carePlan, $this->employee);

        $activity = $this->createFhirCarePlanActivity([
            'uuid' => (string) Str::uuid(),
            'care_plan_id' => $carePlan->id,
            'status' => 'scheduled',
            'kind' => 'ServiceRequest',
            'scheduled_period_start' => now(),
            'scheduled_period_end' => now()->addDays(7),
            'author_id' => $this->employee->id,
        ]);

        $mockActivityApi = Mockery::mock(CarePlanActivityApi::class)->makePartial();
        $mockSignatureService = Mockery::mock(SignatureService::class);
        $mockJobApi = Mockery::mock(Job::class);
        $mockJobApi->shouldReceive('withToken')->andReturnSelf();

        $this->instance(CarePlanActivityApi::class, $mockActivityApi);
        $this->instance(SignatureService::class, $mockSignatureService);
        $this->instance(Job::class, $mockJobApi);

        $snapshot = Arr::toSnakeCase(Repository::carePlanActivity()->getResource($activity->id));
        $snapshotResponse = Mockery::mock(EHealthResponse::class);
        $snapshotResponse->shouldReceive('getData', 'validate')->andReturn($snapshot);
        $mockActivityApi->shouldReceive('getDetails')->once()->with($this->person->uuid, $carePlan->uuid, $activity->uuid, true)->andReturn($snapshotResponse);

        // API-007-006-0005: cancel signs create-shaped activity + detail.status_reason; PATCH is signed_data only
        $mockSignatureService->shouldReceive('signData')
            ->once()
            ->withArgs(function (array $payload): bool {
                return ($payload['detail']['kind'] ?? null) === 'service_request'
                    && ($payload['detail']['status'] ?? null) === 'scheduled'
                    && ($payload['detail']['do_not_perform'] ?? null) === false
                    && ($payload['detail']['status_reason']['coding'][0]['code'] ?? null) === 'typo'
                    && isset($payload['author'][0]['identifier']['value']);
            })
            ->andReturn('mock-base64-signature');
        $mockSignatureService->shouldReceive('getCertificateAuthorities')->andReturn([]);

        // 1. Test Cancel Activity
        $activityCancelResponse = Mockery::mock(EHealthResponse::class);
        $activityCancelResponse->shouldReceive('getData', 'validate')->andReturn([
            'links' => [['href' => '/jobs/cancel-123']]
        ]);
        $activityCancelResponse->shouldReceive('getStatusCode')->andReturn(202);
        $mockActivityApi->shouldReceive('cancel')
            ->once()
            ->withArgs(function (string $personUuid, string $planUuid, string $activityUuid, array $payload): bool {
                return isset($payload['signed_data'])
                    && ($payload['signed_data_encoding'] ?? null) === 'base64'
                    && !array_key_exists('status_reason', $payload)
                    && !array_key_exists('detail', $payload);
            })
            ->andReturn($activityCancelResponse);

        $cancelJobResponse = Mockery::mock(EHealthResponse::class);
        $cancelJobResponse->shouldReceive('getStatusCode')->andReturn(200);
        $cancelJobResponse->shouldReceive('json')->andReturn([]);
        $cancelJobResponse->shouldReceive('isNotLast')->andReturn(false);
        $cancelJobResponse->shouldReceive('getData', 'validate')->andReturn([
            'status' => 'processed',
            'id' => $activity->uuid,
            'result' => [
                'id' => $activity->uuid,
                'status' => 'cancelled'
            ]
        ]);
        $mockJobApi->shouldReceive('getDetails')->with('cancel-123')->andReturn($cancelJobResponse);

        Livewire::test(CarePlanShow::class, ['carePlan' => $carePlan])
            ->set('form.knedp', '1.2.3.4')
            ->set('form.password', 'secret')
            ->set('form.keyContainerUpload', UploadedFile::fake()->create('key.jks', 100))
            ->call('openSignatureModal', 'cancel_activity', $activity->id)
            ->set('statusReason', 'typo')
            ->call('sign')
            ->assertHasNoErrors();

        $this->assertSame('scheduled', $activity->fresh()->status);
        $submission = $activity->ehealthLinks()->latest('id')->firstOrFail();
        $this->assertSame('PENDING', $submission->status);
        RemoteEHealthLinksProcessing::finishSubmission($submission, [
            'status' => 'processed', 'result' => ['id' => $activity->uuid, 'status' => 'cancelled'],
        ], $this->user);

        $this->assertFhirActivityStored([
            'id' => $activity->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_local_persistence_and_payload_formatting_without_instantiates_protocol(): void
    {
        $this->actingAs($this->user);
        $carePlanUuid = (string) Str::uuid();

        $mockCarePlanApi = Mockery::mock(CarePlanApi::class)->makePartial();
        $mockApprovalApi = Mockery::mock(Approval::class);
        $mockPatientApi = Mockery::mock(PersonApi::class);
        $mockJobApi = Mockery::mock(Job::class);
        $mockJobApi->shouldReceive('withToken')->andReturnSelf();
        $mockSignatureService = Mockery::mock(SignatureService::class);

        $this->instance(CarePlanApi::class, $mockCarePlanApi);
        $this->instance(Approval::class, $mockApprovalApi);
        $this->instance(PersonApi::class, $mockPatientApi);
        $this->instance(Job::class, $mockJobApi);
        $this->instance(SignatureService::class, $mockSignatureService);

        $capturedPayload = null;
        $mockSignatureService->shouldReceive('signData')
            ->andReturnUsing(function ($payload) use (&$capturedPayload) {
                $capturedPayload = $payload;

                return 'mock-base64-signature';
            });
        $mockSignatureService->shouldReceive('getCertificateAuthorities')->andReturn([]);

        $cpCreateResponse = Mockery::mock(EHealthResponse::class);
        $cpCreateResponse->shouldReceive('getData', 'validate')->andReturn(['job_id' => 'job-123']);
        $cpCreateResponse->shouldReceive('getStatusCode')->andReturn(202);
        $mockCarePlanApi->shouldReceive('create')->andReturn($cpCreateResponse);

        $jobResponse = Mockery::mock(EHealthResponse::class);
        $jobResponse->shouldReceive('getStatusCode')->andReturn(200);
        $jobResponse->shouldReceive('json')->andReturn([]);
        $jobResponse->shouldReceive('isNotLast')->andReturn(false);
        $jobResponse->shouldReceive('getData', 'validate')->andReturn([
            'status' => 'processed',
            'id' => $carePlanUuid,
            'result' => [
                'id' => $carePlanUuid,
                'status' => 'active'
            ]
        ]);
        $mockJobApi->shouldReceive('getDetails')->andReturn($jobResponse);

        $authResponse = Mockery::mock(EHealthResponse::class);
        $authResponse->shouldReceive('getData', 'validate')->andReturn([]);
        $authResponse->shouldReceive('getStatusCode')->andReturn(200);
        $mockPatientApi->shouldReceive('getAuthMethods')->andReturn($authResponse);

        // 1. Test Save Draft on Create Component
        Livewire::test(CarePlanCreate::class, [
            'legalEntity' => LegalEntity::first(),
            'personId' => $this->person->id,
        ])
            ->set('form.encounter', $this->encounter->uuid)
            ->set('form.title', 'Draft Plan')
            ->set('form.category', 'class_18')
            ->set('form.context', 'context-value')
            ->set('form.termsOfService', 'PROVIDING_CONDITION')
            ->set('form.description', 'My Description')
            ->set('form.note', 'My Note')
            ->set('form.informWith', 'SMS')
            ->set('form.periodStart', now()->format('d.m.Y'))
            ->call('save')
            ->assertHasNoErrors();

        // Check local draft is persisted with terms_of_service
        $this->assertFhirCarePlanStored([
            'title' => 'Draft Plan',
            'status' => 'draft',
            'context' => 'context-value',
            'terms_of_service' => 'OUTPATIENT',
            'description' => 'My Description',
            'note' => 'My Note',
            'inform_with' => 'SMS',
        ]);

        $draft = CarePlan::where('title', 'Draft Plan')->firstOrFail();

        // 2. Test Sign on Create Component
        Livewire::test(CarePlanCreate::class, [
            'legalEntity' => LegalEntity::first(),
            'personId' => $this->person->id,
        ])
            ->set('form.encounter', $this->encounter->uuid)
            ->set('carePlanUuid', $carePlanUuid)
            ->set('form.title', 'Signed Plan')
            ->set('form.category', 'class_18')
            ->set('form.context', 'context-value')
            ->set('form.termsOfService', 'PROVIDING_CONDITION')
            ->set('form.description', 'My Description')
            ->set('form.note', 'My Note')
            ->set('form.informWith', 'SMS')
            ->set('form.periodStart', now()->format('d.m.Y'))
            ->set('form.knedp', '1.2.3.4')
            ->set('form.password', 'secret')
            ->set('form.keyContainerUpload', UploadedFile::fake()->create('key.jks', 100))
            ->call('sign')
            ->assertHasNoErrors();

        // Check payload did NOT contain instantiates_protocol, but did contain inform_with
        $this->assertNotNull($capturedPayload);
        $this->assertFalse(array_key_exists('instantiates_protocol', $capturedPayload));
        $this->assertStringNotContainsString('"instantiates_protocol"', json_encode($capturedPayload));
        $this->assertEquals('SMS', $capturedPayload['inform_with']['auth_method_id'] ?? null);

        $submitted = CarePlan::whereUuid($carePlanUuid)->firstOrFail();
        $this->assertSame('draft', $submitted->status);
        $submission = $submitted->ehealthLinks()->latest('id')->firstOrFail();
        $this->assertSame('PENDING', $submission->status);
        RemoteEHealthLinksProcessing::finishSubmission($submission, [
            'status' => 'processed', 'result' => ['id' => $carePlanUuid, 'status' => 'active'],
        ], $this->user);

        // Check signed Care Plan is in DB with all fields persisted locally
        $this->assertFhirCarePlanStored([
            'uuid' => $carePlanUuid,
            'status' => 'active',
            'context' => 'context-value',
            'terms_of_service' => 'OUTPATIENT',
            'description' => 'My Description',
            'note' => 'My Note',
            'inform_with' => 'SMS',
        ]);

        // 3. Test Save Draft on Update Component
        Livewire::test(CarePlanUpdate::class, [
            'legalEntity' => LegalEntity::first(),
            'carePlan' => $draft,
        ])
            ->set('form.title', 'Updated Draft Plan')
            ->set('form.termsOfService', 'PROVIDING_CONDITION_NEW')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFhirCarePlanStored([
            'id' => $draft->id,
            'title' => 'Updated Draft Plan',
            'terms_of_service' => 'OUTPATIENT',
        ]);

        // 4. Test Sign on Update Component
        $capturedPayload = null;
        Livewire::test(CarePlanUpdate::class, [
            'legalEntity' => LegalEntity::first(),
            'carePlan' => $draft,
        ])
            ->set('form.title', 'Signed Updated Plan')
            ->set('form.termsOfService', 'PROVIDING_CONDITION_SIGNED')
            ->set('form.knedp', '1.2.3.4')
            ->set('form.password', 'secret')
            ->set('form.keyContainerUpload', UploadedFile::fake()->create('key.jks', 100))
            ->call('sign')
            ->assertHasNoErrors();

        // Check payload did NOT contain instantiates_protocol
        $this->assertNotNull($capturedPayload);
        $this->assertFalse(array_key_exists('instantiates_protocol', $capturedPayload));

        // Check updated Care Plan is in DB with all fields updated locally
        $this->assertFhirCarePlanStored([
            'id' => $draft->id,
            'title' => 'Signed Updated Plan',
            'terms_of_service' => 'OUTPATIENT',
        ]);
    }

    public function test_init_medication_activity_form_sets_default_program_for_search(): void
    {
        $this->actingAs($this->user);

        $carePlan = $this->createFhirCarePlan([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->employee->legal_entity_id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Program Default Plan',
            'status' => 'active',
        ]);
        $this->grantCarePlanWriteApproval($carePlan, $this->employee);

        Livewire::test(CarePlanShow::class, ['carePlan' => $carePlan])
            ->call('initActivityForm', 'medication_request')
            ->assertSet('selectedProgram', '1318eabc-1a1a-42f6-8450-61e11c19eede')
            ->assertSet('activityForm.program', '1318eabc-1a1a-42f6-8450-61e11c19eede');
    }

    public function test_init_device_activity_form_leaves_program_empty(): void
    {
        $this->actingAs($this->user);

        $carePlan = $this->createFhirCarePlan([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->employee->legal_entity_id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Device Program Optional Plan',
            'status' => 'active',
        ]);
        $this->grantCarePlanWriteApproval($carePlan, $this->employee);

        $this->fakeMedicalPrograms();

        $component = Livewire::test(CarePlanShow::class, ['carePlan' => $carePlan]);

        $devicePrograms = $component->get('dictionaries')['medical_programs_device'] ?? [];
        $this->assertNotEmpty($devicePrograms, 'Device medical programs must load even if an optional basic dictionary is missing');
        $this->assertArrayHasKey('85953838-1834-4ed6-8bf4-3f83057380ec', $devicePrograms);

        $component
            ->call('initActivityForm', 'device_request')
            ->assertSet('selectedProgram', '')
            ->assertSet('activityForm.program', '')
            ->assertSee(__('care-plan.without_medical_program'));
    }

    public function test_draft_activity_can_be_deleted(): void
    {
        $this->actingAs($this->user);

        $carePlan = $this->createFhirCarePlan([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->employee->legal_entity_id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Delete Draft Plan',
            'status' => 'active',
        ]);
        $this->grantCarePlanWriteApproval($carePlan, $this->employee);

        $activity = $this->createFhirCarePlanActivity([
            'care_plan_id' => $carePlan->id,
            'author_id' => $this->employee->id,
            'status' => 'draft',
            'kind' => 'medication_request',
            'product_reference' => '008d4cbd-beb0-4e56-8b3a-5e472c54d93b',
            'program' => '1318eabc-1a1a-42f6-8450-61e11c19eede',
            'scheduled_period_start' => now()->format('Y-m-d'),
            'scheduled_period_end' => now()->addWeek()->format('Y-m-d'),
        ]);

        Livewire::test(CarePlanShow::class, ['carePlan' => $carePlan])
            ->call('confirmDeleteActivity', $activity->id)
            ->assertSet('confirmingActivityDeletion', true)
            ->assertSet('activityToDelete', $activity->id)
            ->call('deleteActivity', $activity->id)
            ->assertSet('confirmingActivityDeletion', false)
            ->assertSet('activityToDelete', null);

        $this->assertDatabaseMissing('care_plan_activities', ['id' => $activity->id]);
    }

    public function test_scheduled_activity_cannot_be_deleted(): void
    {
        $this->actingAs($this->user);

        $carePlan = $this->createFhirCarePlan([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->employee->legal_entity_id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'No Delete Scheduled Plan',
            'status' => 'active',
        ]);
        $this->grantCarePlanWriteApproval($carePlan, $this->employee);

        $activity = $this->createFhirCarePlanActivity([
            'care_plan_id' => $carePlan->id,
            'author_id' => $this->employee->id,
            'status' => 'scheduled',
            'kind' => 'medication_request',
            'product_reference' => '008d4cbd-beb0-4e56-8b3a-5e472c54d93b',
            'scheduled_period_start' => now()->format('Y-m-d'),
            'scheduled_period_end' => now()->addWeek()->format('Y-m-d'),
        ]);

        Livewire::test(CarePlanShow::class, ['carePlan' => $carePlan])
            ->call('deleteActivity', $activity->id);

        $this->assertFhirActivityStored(['id' => $activity->id]);
    }

    public function test_edit_activity_sets_selected_program_for_program_change(): void
    {
        $this->actingAs($this->user);

        $carePlan = $this->createFhirCarePlan([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->employee->legal_entity_id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Edit Program Plan',
            'status' => 'active',
        ]);
        $this->grantCarePlanWriteApproval($carePlan, $this->employee);

        $activity = $this->createFhirCarePlanActivity([
            'care_plan_id' => $carePlan->id,
            'author_id' => $this->employee->id,
            'status' => 'draft',
            'kind' => 'device_request',
            'product_reference' => '0fa1e6cd-7066-4881-92a5-6d747a1128f7',
            'program' => '85953838-1834-4ed6-8bf4-3f83057380ec',
            'scheduled_period_start' => now()->format('Y-m-d'),
            'scheduled_period_end' => now()->addWeek()->format('Y-m-d'),
        ]);

        Livewire::test(CarePlanShow::class, ['carePlan' => $carePlan])
            ->call('editActivity', $activity->id)
            ->assertSet('selectedProgram', '85953838-1834-4ed6-8bf4-3f83057380ec')
            ->set('selectedProgram', '3e56c84a-808c-46a9-94d1-df4a439a50d2')
            ->assertSet('activityForm.product_reference', '');
    }

    public function test_open_medical_device_search_loads_program_catalog(): void
    {
        $this->actingAs($this->user);

        $targetId = '0fa1e6cd-7066-4881-92a5-6d747a1128f7';

        $devices = [
            [
                'id' => '11111111-1111-1111-1111-111111111111',
                'device_names' => [['name' => 'OneTouch Ultra']],
                'model_number' => 'ULTRA',
                'classification_types' => [['code' => '10001', 'name' => 'Глюкометр']],
                'packaging' => ['packaging_count' => 1, 'packaging_type' => 'piece', 'packaging_unit' => 'piece'],
                'program_devices' => [[
                    'care_plan_activity_allowed' => true,
                    'device_request_allowed' => true,
                    'max_daily_count' => 5,
                    'start_date' => '2024-07-01',
                    'end_date' => null,
                ]],
            ],
            [
                'id' => $targetId,
                'device_names' => [['name' => 'Accu-Chek Active тест-смужки']],
                'model_number' => 'AC-TS',
                'classification_types' => [['code' => '30221', 'name' => 'Тест-смужки']],
                'packaging' => ['packaging_count' => 50, 'packaging_type' => 'box', 'packaging_unit' => 'piece'],
                'program_devices' => [[
                    'care_plan_activity_allowed' => true,
                    'device_request_allowed' => true,
                    'max_daily_count' => 5,
                    'start_date' => '2024-07-01',
                    'end_date' => null,
                ]],
            ],
            [
                'id' => '22222222-2222-2222-2222-222222222222',
                'device_names' => [['name' => 'Blocked Device']],
                'model_number' => 'BLOCKED',
                'classification_types' => [['code' => '99999', 'name' => 'Blocked']],
                'packaging' => ['packaging_count' => 1, 'packaging_type' => 'piece', 'packaging_unit' => 'piece'],
                'program_devices' => [[
                    'care_plan_activity_allowed' => false,
                    'device_request_allowed' => true,
                    'max_daily_count' => 1,
                    'start_date' => '2024-07-01',
                    'end_date' => null,
                ]],
            ],
        ];

        $response = $this->deviceDefinitionResponse([
                'data' => $devices,
                'paging' => [
                    'page_number' => 1,
                    'total_pages' => 1,
                    'total_entries' => 2,
                ],
            ], true);

        $devicesById = collect($devices)->keyBy('id');

        $this->mock(DeviceDefinition::class, function ($mock) use ($response, $devicesById): void {
            $mock->shouldReceive('getMany')->andReturn($response);
            $mock->shouldReceive('getById')->andReturnUsing(function (string $id) use ($devicesById) {
                $device = $devicesById->get($id);
                if ($device === null) {
                    return $this->deviceDefinitionResponse(['data' => []], true);
                }

                return $this->deviceDefinitionResponse(['data' => $device], false);
            });
        });

        $carePlan = $this->createFhirCarePlan([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->employee->legal_entity_id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Device Search Catalog Plan',
            'status' => 'active',
        ]);
        $this->grantCarePlanWriteApproval($carePlan, $this->employee);

        Livewire::test(CarePlanShow::class, ['carePlan' => $carePlan])
            ->call('initActivityForm', 'device_request')
            ->set('selectedProgram', '85953838-1834-4ed6-8bf4-3f83057380ec')
            ->call('openMedicalDeviceSearch')
            ->assertSet('showMedicalDeviceSearchDrawer', true)
            ->assertSet('deviceSearchTotalEntries', 2)
            ->assertSee('Accu-Chek Active тест-смужки')
            ->assertSee('OneTouch Ultra')
            ->assertSee($targetId)
            ->assertDontSee('Blocked Device')
            ->set('searchQuery', 'Accu-Chek')
            ->assertSet('deviceSearchTotalEntries', 1)
            ->assertSee('Accu-Chek Active тест-смужки')
            ->assertDontSee('OneTouch Ultra')
            ->set('searchQuery', $targetId)
            ->assertSet('deviceSearchTotalEntries', 1)
            ->assertSet('searchResults.0.id', $targetId)
            ->assertSee('Accu-Chek Active тест-смужки')
            ->assertSee($targetId)
            ->assertDontSee('OneTouch Ultra')
            ->set('searchQuery', strtoupper($targetId))
            ->assertSet('deviceSearchTotalEntries', 1)
            ->assertSet('searchResults.0.id', $targetId)
            ->assertSee('Accu-Chek Active тест-смужки')
            ->set('searchQuery', '22222222-2222-2222-2222-222222222222')
            ->assertSet('deviceSearchTotalEntries', 1)
            ->assertSet('searchResults.0.id', '22222222-2222-2222-2222-222222222222')
            ->assertSee('Blocked Device');
    }

    public function test_medical_device_search_looks_up_uuid_when_missing_from_program_catalog(): void
    {
        $this->actingAs($this->user);

        $targetId = '0fa1e6cd-7066-4881-92a5-6d747a1128f7';
        $targetDevice = [
            'id' => $targetId,
            'device_names' => [['name' => 'Accu-Chek Active тест-смужки']],
            'model_number' => 'AC-TS',
            'classification_types' => [['code' => '30221', 'name' => 'Тест-смужки']],
            'packaging' => ['packaging_count' => 50, 'packaging_type' => 'box', 'packaging_unit' => 'piece'],
            'program_devices' => [[
                'care_plan_activity_allowed' => true,
                'device_request_allowed' => true,
                'max_daily_count' => 5,
                'start_date' => '2024-07-01',
                'end_date' => null,
            ]],
        ];

        $catalogResponse = $this->deviceDefinitionResponse([
                'data' => [[
                    'id' => '11111111-1111-1111-1111-111111111111',
                    'device_names' => [['name' => 'OneTouch Ultra']],
                    'model_number' => 'ULTRA',
                    'classification_types' => [['code' => '10001', 'name' => 'Глюкометр']],
                    'packaging' => ['packaging_count' => 1, 'packaging_type' => 'piece', 'packaging_unit' => 'piece'],
                    'program_devices' => [[
                        'care_plan_activity_allowed' => true,
                        'device_request_allowed' => true,
                        'max_daily_count' => 5,
                        'start_date' => '2024-07-01',
                        'end_date' => null,
                    ]],
                ]],
                'paging' => [
                    'page_number' => 1,
                    'total_pages' => 1,
                    'total_entries' => 1,
                ],
            ], true);

        $byIdResponse = $this->deviceDefinitionResponse([
                'data' => $targetDevice,
            ], false);

        $this->mock(DeviceDefinition::class, function ($mock) use ($catalogResponse, $byIdResponse, $targetId): void {
            $mock->shouldReceive('getMany')->andReturn($catalogResponse);
            $mock->shouldReceive('getById')->with($targetId)->andReturn($byIdResponse);
        });

        $carePlan = $this->createFhirCarePlan([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->employee->legal_entity_id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Device UUID Lookup Plan',
            'status' => 'active',
        ]);
        $this->grantCarePlanWriteApproval($carePlan, $this->employee);

        Livewire::test(CarePlanShow::class, ['carePlan' => $carePlan])
            ->call('initActivityForm', 'device_request')
            ->set('selectedProgram', '85953838-1834-4ed6-8bf4-3f83057380ec')
            ->call('openMedicalDeviceSearch')
            ->assertDontSee('Accu-Chek Active тест-смужки')
            ->set('searchQuery', $targetId)
            ->assertSet('deviceSearchTotalEntries', 1)
            ->assertSet('searchResults.0.id', $targetId)
            ->assertDontSee('OneTouch Ultra')
            ->assertSee('Accu-Chek Active тест-смужки')
            ->assertSee($targetId);
    }

    public function test_selecting_searched_device_opens_form_drawer(): void
    {
        $this->actingAs($this->user);

        $targetId = '0fa1e6cd-7066-4881-92a5-6d747a1128f7';
        $devices = [
            [
                'id' => $targetId,
                'device_names' => [['name' => 'Accu-Chek Active тест-смужки']],
                'model_number' => 'AC-TS',
                'classification_types' => [['code' => '30221', 'name' => 'Тест-смужки']],
                'packaging' => ['packaging_count' => 50, 'packaging_type' => 'box', 'packaging_unit' => 'piece'],
                'program_devices' => [[
                    'care_plan_activity_allowed' => true,
                    'device_request_allowed' => true,
                    'max_daily_count' => 5,
                    'start_date' => '2024-07-01',
                    'end_date' => null,
                ]],
            ],
        ];

        $response = $this->deviceDefinitionResponse([
                'data' => $devices,
                'paging' => [
                    'page_number' => 1,
                    'total_pages' => 1,
                    'total_entries' => 1,
                ],
            ], true);

        $this->mock(DeviceDefinition::class, function ($mock) use ($response): void {
            $mock->shouldReceive('getMany')->andReturn($response);
            $mock->shouldReceive('getById')->andReturn($response);
        });

        $carePlan = $this->createFhirCarePlan([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->employee->legal_entity_id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Select Device Plan',
            'status' => 'active',
        ]);
        $this->grantCarePlanWriteApproval($carePlan, $this->employee);

        Livewire::test(CarePlanShow::class, ['carePlan' => $carePlan])
            ->call('initActivityForm', 'device_request')
            ->call('openMedicalDeviceSearch')
            ->call('selectSearchedDevice', $targetId)
            ->assertSet('showMedicalDeviceSearchDrawer', false)
            ->assertSet('showMedicalDeviceFormDrawer', true)
            ->assertSet('activityForm.product_reference', $targetId)
            ->assertSee('Accu-Chek Active тест-смужки');
    }

    public function test_selecting_searched_device_refetches_by_uuid_when_catalog_is_empty(): void
    {
        $this->actingAs($this->user);

        $targetId = '0fa1e6cd-7066-4881-92a5-6d747a1128f7';
        $targetDevice = [
            'id' => $targetId,
            'device_names' => [['name' => 'Accu-Chek Active тест-смужки']],
            'model_number' => 'AC-TS',
            'classification_types' => [['code' => '30221', 'name' => 'Тест-смужки']],
            'packaging' => ['packaging_count' => 50, 'packaging_type' => 'box', 'packaging_unit' => 'piece'],
            'program_devices' => [[
                'care_plan_activity_allowed' => true,
                'device_request_allowed' => true,
                'max_daily_count' => 5,
                'start_date' => '2024-07-01',
                'end_date' => null,
            ]],
        ];

        $emptyCatalog = $this->deviceDefinitionResponse([
                'data' => [],
                'paging' => [
                    'page_number' => 1,
                    'total_pages' => 1,
                    'total_entries' => 0,
                ],
            ], true);

        $byIdResponse = $this->deviceDefinitionResponse([
                'data' => $targetDevice,
            ], false);

        $this->mock(DeviceDefinition::class, function ($mock) use ($emptyCatalog, $byIdResponse, $targetId): void {
            $mock->shouldReceive('getMany')->andReturn($emptyCatalog);
            $mock->shouldReceive('getById')->with($targetId)->andReturn($byIdResponse);
        });

        $carePlan = $this->createFhirCarePlan([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->employee->legal_entity_id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Select Device Refetch Plan',
            'status' => 'active',
        ]);
        $this->grantCarePlanWriteApproval($carePlan, $this->employee);

        Livewire::test(CarePlanShow::class, ['carePlan' => $carePlan])
            ->call('initActivityForm', 'device_request')
            ->call('selectSearchedDevice', $targetId)
            ->assertSet('showMedicalDeviceSearchDrawer', false)
            ->assertSet('showMedicalDeviceFormDrawer', true)
            ->assertSet('activityForm.product_reference', $targetId)
            ->assertSet('selectedProduct.id', $targetId)
            ->assertSee('Accu-Chek Active тест-смужки');
    }

    public function test_device_activity_opens_request_form_with_patient_plan_and_activity_context(): void
    {
        $this->actingAs($this->user);

        $deviceUuid = '0fa1e6cd-7066-4881-92a5-6d747a1128f7';
        $programId = '85953838-1834-4ed6-8bf4-3f83057380ec';

        $carePlan = $this->createFhirCarePlan([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->employee->legal_entity_id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Device Request Plan',
            'status' => 'active',
        ]);
        $this->grantCarePlanWriteApproval($carePlan, $this->employee);

        $activity = $this->createFhirCarePlanActivity([
            'uuid' => (string) Str::uuid(),
            'care_plan_id' => $carePlan->id,
            'author_id' => $this->employee->id,
            'status' => 'scheduled',
            'kind' => 'device_request',
            'product_reference' => $deviceUuid,
            'quantity' => 100,
            'program' => $programId,
            'scheduled_period_start' => now(),
            'scheduled_period_end' => now()->addDays(30),
        ]);

        $this->mock(CarePlanActivityApi::class, function ($mock) use ($activity, $carePlan): void {
            $response = Mockery::mock(EHealthResponse::class);
            $response->shouldReceive('successful')->andReturnTrue();
            $response->shouldReceive('validate')->andReturn(Repository::carePlanActivity()->getResource($activity->id));
            $mock->shouldReceive('getDetails')->once()->with($this->person->uuid, $carePlan->uuid, $activity->uuid)
                ->andReturn($response);
        });

        Livewire::test(CarePlanShow::class, ['carePlan' => $carePlan->fresh()])
            ->call('initReferralForm', $activity->id)
            ->assertRedirect(route('persons.device-requests.create', [
                'legalEntity' => legalEntity(), 'person' => $this->person,
                'carePlan' => $carePlan->id, 'activity' => $activity->id,
            ]));
    }

    public function test_device_request_form_resolves_local_context_ids_to_esoz_uuids(): void
    {
        [$plan, $activity, $service] = $this->deviceRequestFormContext();
        $service->shouldReceive('carePlanContext')->once()
            ->with($this->person, $plan->uuid, $activity->uuid, $this->employee->uuid)
            ->andReturn(['detail' => ['quantity' => ['code' => 'piece']]]);

        Livewire::withQueryParams(['carePlan' => $plan->id, 'activity' => $activity->id])
            ->test(DeviceRequestForm::class, ['legalEntity' => legalEntity(), 'person' => $this->person])
            ->assertOk()->assertHasNoErrors()
            ->assertSet('carePlanUuid', $plan->uuid)
            ->assertSet('activityUuid', $activity->uuid);
        $this->assertNull(session('error'));
    }

    public function test_device_request_form_rejects_activity_from_another_plan(): void
    {
        [$plan, $activity, $service] = $this->deviceRequestFormContext();
        $otherPlan = $this->createFhirCarePlan([
            'person_id' => $this->person->id, 'author_id' => $this->employee->id,
            'legal_entity_id' => $this->employee->legalEntityId,
        ]);
        $service->shouldNotReceive('carePlanContext');
        $this->withoutExceptionHandling();
        $this->expectException(ModelNotFoundException::class);

        Livewire::withQueryParams(['carePlan' => $otherPlan->id, 'activity' => $activity->id])
            ->test(DeviceRequestForm::class, ['legalEntity' => legalEntity(), 'person' => $this->person]);
    }

    public function test_device_request_form_rejects_context_from_another_patient(): void
    {
        [$plan, $activity, $service] = $this->deviceRequestFormContext();
        $otherPerson = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']);
        $service->shouldNotReceive('carePlanContext');
        $this->withoutExceptionHandling();
        $this->expectException(NotFoundHttpException::class);

        Livewire::withQueryParams(['carePlan' => $plan->id, 'activity' => $activity->id])
            ->test(DeviceRequestForm::class, ['legalEntity' => legalEntity(), 'person' => $otherPerson]);
    }

    private function deviceRequestFormContext(): array
    {
        $this->actingAs($this->user);
        Cache::put(MedicalProgramDictionary::KEY, [], now()->addDay());
        Cache::put(MedicalProgramDictionary::KEY.':fresh', true, now()->addDay());
        $this->mockBasicDictionaries(['device_unit' => ['piece' => 'Штука']]);
        $this->app->forgetInstance(DictionaryManager::class);
        $this->fakeMedicalPrograms();
        $plan = $this->createFhirCarePlan([
            'person_id' => $this->person->id, 'author_id' => $this->employee->id,
            'legal_entity_id' => $this->employee->legalEntityId, 'status' => 'active',
        ]);
        $activity = $this->createFhirCarePlanActivity([
            'care_plan_id' => $plan->id, 'author_id' => $this->employee->id,
            'kind' => 'device_request', 'status' => 'scheduled',
        ]);
        $service = Mockery::mock(DeviceRequestLifecycleService::class);
        $service->shouldReceive('authorizeAction')->andReturn($this->employee);
        $service->shouldReceive('authMethods')->andReturn([]);
        $service->shouldReceive('permittedTypes')->andReturn([]);
        $this->instance(DeviceRequestLifecycleService::class, $service);

        return [$plan, $activity, $service];
    }

    /**
     * The medical programs dictionary is fetched from eHealth, so tests that assert on
     * its contents have to supply it rather than depend on whatever the environment holds.
     */
    private function fakeMedicalPrograms(): void
    {
        $basics = dictionary()->basics();
        $manager = Mockery::mock(DictionaryManager::class)->makePartial();
        $manager->shouldReceive('basics')->andReturn($basics);
        $manager->shouldReceive('medicalPrograms')->andReturn(collect([
            [
                'id' => '1318eabc-1a1a-42f6-8450-61e11c19eede',
                'name' => 'Доступні ліки',
                'type' => 'MEDICATION',
                'is_active' => true,
            ],
            [
                'id' => '85953838-1834-4ed6-8bf4-3f83057380ec',
                'name' => 'Медичні вироби',
                'type' => 'DEVICE',
                'is_active' => true,
            ],
        ]));

        $this->instance(DictionaryManager::class, $manager);
    }
    /** Build a complete native V2 receipt through the owning API validator. */
    private function deviceDefinitionResponse(array $body, bool $collection = true): EHealthResponse
    {
        $records = $collection ? $body['data'] : [$body['data']];
        foreach ($records as &$record) {
            $record += [
                'is_active' => true,
                'device_names' => [['name' => $record['name'] ?? 'Тестовий виріб', 'type' => 'user_friendly']],
                'classification_types' => [],
                'inserted_at' => '2026-01-01T00:00:00Z', 'updated_at' => '2026-01-01T00:00:00Z',
                'inserted_by' => 'e1453f4c-1077-4e85-8c98-c13ffca0063e', 'updated_by' => '2922a240-63db-404e-b730-09222bfeb2dd',
            ];
            foreach ($record['device_names'] as &$name) {
                $name += ['type' => 'user_friendly'];
            }
            unset($name);
            foreach ($record['classification_types'] as &$classification) {
                $classification += ['system' => 'device_definition_classification_type'];
            }
            unset($classification);
            foreach ($record['program_devices'] ?? [] as $index => $programDevice) {
                $programDevice += ['id' => 'e477a845-972b-47a1-8ca3-1c71e4fd00c2'];
                if (($programDevice['end_date'] ?? null) === null) {
                    unset($programDevice['end_date']);
                }
                $record['program_devices'][$index] = $programDevice;
            }
        }
        unset($record);
        $body['data'] = $collection ? $records : $records[0];
        if ($collection) {
            $body['paging'] = [
                'page_number' => 1, 'page_size' => 50,
                'total_entries' => count($records), 'total_pages' => count($records) === 0 ? 0 : 1,
            ];
        }
        config(['ehealth.api.domain' => 'https://ehealth.test']);
        $factory = new Factory();
        $factory->fake(['ehealth.test/*' => $factory->response($body)]);
        $stubs = (function () {
            return $this->stubCallbacks;
        })->call($factory);
        $api = new DeviceDefinition($factory);
        $api->stub($stubs)->preventStrayRequests();

        return $collection ? $api->getMany() : $api->getById($records[0]['id']);
    }

}
