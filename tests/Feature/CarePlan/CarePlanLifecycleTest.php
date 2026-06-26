<?php

declare(strict_types=1);

namespace Tests\Feature\CarePlan;

use App\Classes\eHealth\Api\Approval;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\Person\Person;
use App\Models\MedicalEvents\Sql\Encounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;
use Mockery;

use Illuminate\Support\Str;

class CarePlanLifecycleTest extends TestCase
{
    use RefreshDatabase;

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

        $conditionIdentifier = \App\Models\MedicalEvents\Sql\Identifier::create([
            'value' => (string) Str::uuid()
        ]);
        $conditionCc = \App\Models\MedicalEvents\Sql\CodeableConcept::create();
        $conditionCc->save();

        $conditionCoding = new \App\Models\MedicalEvents\Sql\Coding();
        $conditionCoding->code = 'D02';
        $conditionCoding->system = 'eHealth/ICPC2/condition_codes';
        $conditionCoding->codeable_type = \App\Models\MedicalEvents\Sql\CodeableConcept::class;
        $conditionCoding->codeable_id = $conditionCc->id;
        $conditionCoding->save();

        $condition = \App\Models\MedicalEvents\Sql\Condition::create([
            'uuid' => $conditionIdentifier->value,
            'person_id' => $this->person->id,
            'primary_source' => true,
            'clinical_status' => \App\Enums\Person\ConditionClinicalStatus::ACTIVE,
            'verification_status' => \App\Enums\Person\ConditionVerificationStatus::CONFIRMED,
            'code_id' => $conditionCc->id,
            'context_id' => $identifierId,
            'onset_date' => now(),
        ]);

        \App\Models\MedicalEvents\Sql\EncounterDiagnose::create([
            'encounter_id' => $this->encounter->id,
            'condition_id' => $conditionIdentifier->id,
            'role_id' => $ccId,
            'rank' => 1
        ]);

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

        $this->party = \App\Models\Relations\Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'House',
            'last_name' => 'Doctor',
            'tax_id' => '1234567890',
            'birth_date' => '1970-01-01',
            'gender' => 'MALE',
        ]);

        $this->user = \App\Models\User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'house' . Str::random(5) . '@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'party_id' => $this->party->id,
        ]);

        $this->employee = \App\Models\Employee\Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Dr. House',
            'employee_type' => \App\Enums\User\Role::DOCTOR->value,
            'status' => \App\Enums\Status::APPROVED->value,
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
    }

    public function test_full_care_plan_lifecycle_flow(): void
    {
        $this->actingAs($this->user);
        $carePlanUuid = (string) Str::uuid();
        $approvalUuid = (string) Str::uuid();
        $activityUuid = (string) Str::uuid();
        $approvalId = (string) Str::uuid(); // Use different ID for internal approval ID if needed

        $mockCarePlanApi = Mockery::mock(\App\Classes\eHealth\Api\CarePlan::class);
        $mockApprovalApi = Mockery::mock(\App\Classes\eHealth\Api\Approval::class);
        $mockPatientApi = Mockery::mock(\App\Classes\eHealth\Api\Person::class);
        $mockJobApi = Mockery::mock(\App\Classes\eHealth\Api\Job::class);
        $mockActivityApi = Mockery::mock(\App\Classes\eHealth\Api\CarePlanActivity::class);
        $mockSignatureService = Mockery::mock(\App\Services\SignatureService::class);

        // Bind mocks to container
        $this->instance(\App\Classes\eHealth\Api\CarePlan::class, $mockCarePlanApi);
        $this->instance(\App\Classes\eHealth\Api\Approval::class, $mockApprovalApi);
        $this->instance(\App\Classes\eHealth\Api\Person::class, $mockPatientApi);
        $this->instance(\App\Classes\eHealth\Api\Job::class, $mockJobApi);
        $this->instance(\App\Classes\eHealth\Api\CarePlanActivity::class, $mockActivityApi);
        $this->instance(\App\Services\SignatureService::class, $mockSignatureService);

        // Mock signature
        $mockSignatureService->shouldReceive('signData')->andReturn('mock-base64-signature');
        $mockSignatureService->shouldReceive('getCertificateAuthorities')->andReturn([]);

        // 1. Mock Care Plan Creation
        $cpCreateResponse = Mockery::mock(\App\Classes\eHealth\EHealthResponse::class);
        $cpCreateResponse->shouldReceive('getData')->andReturn(['job_id' => 'job-123']);
        $cpCreateResponse->shouldReceive('getStatusCode')->andReturn(202);
        $mockCarePlanApi->shouldReceive('create')->andReturn($cpCreateResponse);

        // Mock Job Details
        $jobResponse = Mockery::mock(\App\Classes\eHealth\EHealthResponse::class);
        $jobResponse->shouldReceive('getData')->andReturn([
            'status' => 'processed',
            'id' => $carePlanUuid,
            'result' => [
                'id' => $carePlanUuid,
                'status' => 'active'
            ]
        ]);
        $mockJobApi->shouldReceive('getDetails')->andReturn($jobResponse);

        // 2. Mock Approval Flow
        $approvalCreateResponse = Mockery::mock(\App\Classes\eHealth\EHealthResponse::class);
        $approvalCreateResponse->shouldReceive('getData')->andReturn(['id' => $approvalUuid, 'status' => 'NEW']);
        $approvalCreateResponse->shouldReceive('getStatusCode')->andReturn(201);
        $mockApprovalApi->shouldReceive('createApproval')->andReturn($approvalCreateResponse);

        $approvalVerifyResponse = Mockery::mock(\App\Classes\eHealth\EHealthResponse::class);
        $approvalVerifyResponse->shouldReceive('getData')->andReturn(['id' => $approvalUuid, 'status' => 'GRANTED']);
        $approvalVerifyResponse->shouldReceive('getStatusCode')->andReturn(200);
        $mockApprovalApi->shouldReceive('verify')->andReturn($approvalVerifyResponse);

        $approvalsResponse = Mockery::mock(\App\Classes\eHealth\EHealthResponse::class);
        $approvalsResponse->shouldReceive('getData')->andReturn([
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
        $authResponse = Mockery::mock(\App\Classes\eHealth\EHealthResponse::class);
        $authResponse->shouldReceive('getData')->andReturn([
            [
                'id' => 'otp-uuid',
                'type' => \App\Enums\Person\AuthenticationMethod::OTP->value,
                'phone_number' => '+380991112233'
            ]
        ]);
        $authResponse->shouldReceive('getStatusCode')->andReturn(200);
        $mockPatientApi->shouldReceive('getAuthMethods')->andReturn($authResponse);

        // 3. Test CarePlanCreate Component
        Livewire::test(\App\Livewire\CarePlan\CarePlanCreate::class, [
            'legalEntity' => \App\Models\LegalEntity::first(),
            'personId' => $this->person->id,
        ])
            ->set('form.encounter', $this->encounter->uuid)
            ->set('form.title', 'Test Plan')
            ->set('form.category', '736382003')
            ->set('form.intent', 'order')
            ->set('form.termsOfService', '736382003')
            ->set('form.periodStart', now()->format('d.m.Y'))
            ->set('form.knedp', '1.2.3.4')
            ->set('form.password', 'secret')
            ->set('form.keyContainerUpload', \Illuminate\Http\UploadedFile::fake()->create('key.jks', 100))
            ->call('sign')
            ->assertHasNoErrors();

        // Verify Care Plan is in DB
        $carePlan = CarePlan::where('uuid', $carePlanUuid)->first();

        $this->assertDatabaseHas('care_plans', [
            'uuid' => $carePlanUuid,
            'person_id' => $this->person->id,
        ]);

        $carePlan = CarePlan::where('uuid', $carePlanUuid)->first();

        // 4. Test CarePlanShow - Adding Activity
        $activityCreateResponse = Mockery::mock(\App\Classes\eHealth\EHealthResponse::class);
        $activityCreateResponse->shouldReceive('getData')->andReturn(['id' => $activityUuid, 'status' => 'scheduled']);
        $activityCreateResponse->shouldReceive('getStatusCode')->andReturn(201);
        $mockActivityApi->shouldReceive('create')->once()->andReturn($activityCreateResponse);

        Livewire::test(\App\Livewire\CarePlan\CarePlanShow::class, ['carePlan' => $carePlan])
            ->call('initActivityForm', 'service_request')
            ->set('activityForm.kind', 'ServiceRequest')
            ->set('activityForm.quantity', 1)
            ->set('activityForm.scheduled_period_start', now()->format('d.m.Y'))
            ->set('activityForm.scheduled_period_end', now()->addDays(7)->format('d.m.Y'))
            ->call('saveActivity')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('care_plan_activities', [
            'care_plan_id' => $carePlan->id,
            'status' => 'draft',
        ]);

        $activity = CarePlanActivity::where('care_plan_id', $carePlan->id)->first();

        // 5. Test CarePlanShow - Signing Activity & Auto-Activation
        $activitySignResponse = Mockery::mock(\App\Classes\eHealth\EHealthResponse::class);
        $activitySignResponse->shouldReceive('getData')->andReturn(['id' => $activityUuid, 'status' => 'scheduled']);
        $activitySignResponse->shouldReceive('getStatusCode')->andReturn(200);
        $mockActivityApi->shouldReceive('create')->andReturn($activitySignResponse);

        $activitySummaryResponse = Mockery::mock(\App\Classes\eHealth\EHealthResponse::class);
        $activitySummaryResponse->shouldReceive('getData')->andReturn(['data' => []]);
        $activitySummaryResponse->shouldReceive('getStatusCode')->andReturn(200);
        $mockActivityApi->shouldReceive('getSummary')->andReturn($activitySummaryResponse);

        // Mock sync response showing plan is now ACTIVE
        $syncResponse = Mockery::mock(\App\Classes\eHealth\EHealthResponse::class);
        $syncResponse->shouldReceive('getData')->andReturn([[
            'uuid' => $carePlanUuid,
            'status' => 'active',
            'title' => 'Test Plan',
            'period' => ['start' => now()->toIso8601String()],
            'terms_of_service' => ['coding' => [['code' => '736382003']]]
        ]]);
        $syncResponse->shouldReceive('getStatusCode')->andReturn(200);
        $mockCarePlanApi->shouldReceive('getBySearchParams')->andReturn($syncResponse);

        Livewire::test(\App\Livewire\CarePlan\CarePlanShow::class, ['carePlan' => $carePlan])
            ->set('form.knedp', '1.2.3.4')
            ->set('form.password', 'secret')
            ->set('form.keyContainerUpload', \Illuminate\Http\UploadedFile::fake()->create('key.jks', 100))
            ->call('openSignatureModal', 'sign_activity', $activity->id)
            ->call('sign')
            ->assertHasNoErrors();

        // Verify Activity is now SIGNED in local DB
        $this->assertDatabaseHas('care_plan_activities', [
            'uuid' => $activityUuid,
            'id' => $activity->id,
            'status' => 'scheduled',
        ]);

        // Verify Plan is now ACTIVE in local DB
        $this->assertDatabaseHas('care_plans', [
            'id' => $carePlan->id,
            'status' => 'active',
        ]);
    }

    public function test_create_service_activity_with_linked_grounds(): void
    {
        $this->actingAs($this->user);

        $carePlan = CarePlan::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->employee->legal_entity_id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Service Plan',
            'status' => 'draft',
        ]);

        $condition = \App\Models\MedicalEvents\Sql\Condition::first();

        // Bind mock APIs to satisfy dependencies
        $mockActivityApi = Mockery::mock(\App\Classes\eHealth\Api\CarePlanActivity::class);
        $this->instance(\App\Classes\eHealth\Api\CarePlanActivity::class, $mockActivityApi);

        $activityCreateResponse = Mockery::mock(\App\Classes\eHealth\EHealthResponse::class);
        $activityCreateResponse->shouldReceive('getData')->andReturn(['id' => (string) Str::uuid(), 'status' => 'scheduled']);
        $activityCreateResponse->shouldReceive('getStatusCode')->andReturn(201);
        $mockActivityApi->shouldReceive('create')->andReturn($activityCreateResponse);

        Livewire::test(\App\Livewire\CarePlan\CarePlanShow::class, ['carePlan' => $carePlan])
            ->call('initActivityForm', 'service_request')
            ->set('selectedProduct', ['code' => 'A01001', 'name' => 'General medical consultation'])
            ->set('activityForm.product_reference', 'A01001')
            ->call('addLinkedGround', 'Condition', $condition->uuid)
            ->assertSet('linkedGrounds.0.uuid', $condition->uuid)
            ->set('activityForm.quantity', 2)
            ->set('activityForm.scheduled_period_start', now()->format('d.m.Y'))
            ->set('activityForm.scheduled_period_end', now()->addDays(7)->format('d.m.Y'))
            ->call('saveActivity')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('care_plan_activities', [
            'care_plan_id' => $carePlan->id,
            'product_reference' => 'A01001',
            'quantity' => 2,
        ]);
    }

    public function test_create_medication_activity_with_program_and_linked_grounds(): void
    {
        $this->actingAs($this->user);

        $carePlan = CarePlan::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->employee->legal_entity_id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Medication Plan',
            'status' => 'draft',
        ]);

        $condition = \App\Models\MedicalEvents\Sql\Condition::first();

        $mockActivityApi = Mockery::mock(\App\Classes\eHealth\Api\CarePlanActivity::class);
        $this->instance(\App\Classes\eHealth\Api\CarePlanActivity::class, $mockActivityApi);

        $activityCreateResponse = Mockery::mock(\App\Classes\eHealth\EHealthResponse::class);
        $activityCreateResponse->shouldReceive('getData')->andReturn(['id' => (string) Str::uuid(), 'status' => 'scheduled']);
        $activityCreateResponse->shouldReceive('getStatusCode')->andReturn(201);
        $mockActivityApi->shouldReceive('create')->andReturn($activityCreateResponse);

        $medicationId = '02b5e4de-22ec-429d-81f2-8faf44bd8c92';

        Livewire::test(\App\Livewire\CarePlan\CarePlanShow::class, ['carePlan' => $carePlan])
            ->call('initActivityForm', 'medication_request')
            ->set('selectedProgram', 'program-id')
            ->set('activityForm.program', 'program-id')
            ->set('selectedProduct', [
                'id' => $medicationId,
                'name' => 'Aspirin',
                'ingredients' => [
                    ['dosage' => ['denumerator_unit' => 'PIECE']],
                ],
            ])
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

        $this->assertDatabaseHas('care_plan_activities', [
            'care_plan_id' => $carePlan->id,
            'product_reference' => $medicationId,
            'program' => 'program-id',
            'quantity' => 30,
        ]);
    }

    public function test_create_device_activity_with_positive_quantity_validation(): void
    {
        $this->actingAs($this->user);

        $carePlan = CarePlan::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->employee->legal_entity_id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Device Plan',
            'status' => 'draft',
        ]);

        $mockActivityApi = Mockery::mock(\App\Classes\eHealth\Api\CarePlanActivity::class);
        $this->instance(\App\Classes\eHealth\Api\CarePlanActivity::class, $mockActivityApi);

        $activityCreateResponse = Mockery::mock(\App\Classes\eHealth\EHealthResponse::class);
        $activityCreateResponse->shouldReceive('getData')->andReturn(['id' => (string) Str::uuid(), 'status' => 'scheduled']);
        $activityCreateResponse->shouldReceive('getStatusCode')->andReturn(201);
        $mockActivityApi->shouldReceive('create')->andReturn($activityCreateResponse);

        $deviceUuid = (string) Str::uuid();
        $deviceProgram = 'c0ee515e-bdcc-4613-91cf-22d7d8e82efc';

        // Validation error for negative or zero quantity
        Livewire::test(\App\Livewire\CarePlan\CarePlanShow::class, ['carePlan' => $carePlan])
            ->call('initActivityForm', 'device_request')
            ->set('selectedProgram', $deviceProgram)
            ->set('activityForm.program', $deviceProgram)
            ->set('selectedProduct', ['id' => $deviceUuid, 'code' => 'DEV-456', 'name' => 'Test strips'])
            ->set('activityForm.product_reference', $deviceUuid)
            ->set('activityForm.quantity', -5)
            ->set('activityForm.scheduled_period_start', now()->format('d.m.Y'))
            ->set('activityForm.scheduled_period_end', now()->addDays(7)->format('d.m.Y'))
            ->call('saveActivity')
            ->assertHasErrors(['activityForm.quantity']);

        // Success when positive integer
        Livewire::test(\App\Livewire\CarePlan\CarePlanShow::class, ['carePlan' => $carePlan])
            ->call('initActivityForm', 'device_request')
            ->set('selectedProgram', $deviceProgram)
            ->set('activityForm.program', $deviceProgram)
            ->set('selectedProduct', ['id' => $deviceUuid, 'code' => 'DEV-456', 'name' => 'Test strips'])
            ->set('activityForm.product_reference', $deviceUuid)
            ->set('activityForm.quantity', 10)
            ->set('activityForm.scheduled_period_start', now()->format('d.m.Y'))
            ->set('activityForm.scheduled_period_end', now()->addDays(7)->format('d.m.Y'))
            ->call('saveActivity')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('care_plan_activities', [
            'care_plan_id' => $carePlan->id,
            'product_reference' => $deviceUuid,
            'quantity' => 10,
        ]);
    }

    public function test_cancel_and_complete_care_plan_activity(): void
    {
        $this->actingAs($this->user);

        $carePlan = CarePlan::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $this->employee->id,
            'legal_entity_id' => $this->employee->legal_entity_id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Lifecycle Plan',
            'status' => 'active',
        ]);

        $activity = CarePlanActivity::create([
            'uuid' => (string) Str::uuid(),
            'care_plan_id' => $carePlan->id,
            'status' => 'scheduled',
            'kind' => 'ServiceRequest',
            'scheduled_period_start' => now(),
            'scheduled_period_end' => now()->addDays(7),
            'author_id' => $this->employee->id,
        ]);

        $mockActivityApi = Mockery::mock(\App\Classes\eHealth\Api\CarePlanActivity::class);
        $mockSignatureService = Mockery::mock(\App\Services\SignatureService::class);
        $mockJobApi = Mockery::mock(\App\Classes\eHealth\Api\Job::class);

        $this->instance(\App\Classes\eHealth\Api\CarePlanActivity::class, $mockActivityApi);
        $this->instance(\App\Services\SignatureService::class, $mockSignatureService);
        $this->instance(\App\Classes\eHealth\Api\Job::class, $mockJobApi);

        // Sign Mocking
        $mockSignatureService->shouldReceive('signData')->andReturn('mock-base64-signature');
        $mockSignatureService->shouldReceive('getCertificateAuthorities')->andReturn([]);

        // 1. Test Cancel Activity
        $activityCancelResponse = Mockery::mock(\App\Classes\eHealth\EHealthResponse::class);
        $activityCancelResponse->shouldReceive('getData')->andReturn([
            'links' => [['href' => '/jobs/cancel-123']]
        ]);
        $activityCancelResponse->shouldReceive('getStatusCode')->andReturn(202);
        $mockActivityApi->shouldReceive('cancel')->once()->andReturn($activityCancelResponse);

        $cancelJobResponse = Mockery::mock(\App\Classes\eHealth\EHealthResponse::class);
        $cancelJobResponse->shouldReceive('getData')->andReturn([
            'status' => 'processed',
            'id' => $activity->uuid,
            'result' => [
                'id' => $activity->uuid,
                'status' => 'cancelled'
            ]
        ]);
        $mockJobApi->shouldReceive('getDetails')->with('cancel-123')->andReturn($cancelJobResponse);

        Livewire::test(\App\Livewire\CarePlan\CarePlanShow::class, ['carePlan' => $carePlan])
            ->set('form.knedp', '1.2.3.4')
            ->set('form.password', 'secret')
            ->set('form.keyContainerUpload', \Illuminate\Http\UploadedFile::fake()->create('key.jks', 100))
            ->call('openSignatureModal', 'cancel_activity', $activity->id)
            ->set('statusReason', 'typo')
            ->call('sign')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('care_plan_activities', [
            'id' => $activity->id,
            'status' => 'cancelled',
        ]);
    }
}
