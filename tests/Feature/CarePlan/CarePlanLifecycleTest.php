<?php

namespace Tests\Feature\CarePlan;

use App\Classes\eHealth\Api\Approval;
use App\Classes\eHealth\Api\CarePlan as CarePlanApi;
use App\Classes\eHealth\Api\CarePlanActivity as ActivityApi;
use App\Classes\eHealth\EHealthResponse;
use App\Enums\CarePlanStatus;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\Person\Person;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\Employees\Sql\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;
use Mockery;

use Illuminate\Support\Str;
use App\Classes\eHealth\EHealth;
use Illuminate\Support\Facades\Log;

class CarePlanLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        // Ensure we are using the pgsql connection for tests
        putenv('DB_CONNECTION=pgsql');
        putenv('DB_HOST=127.0.0.1');
        putenv('DB_DATABASE=mis_dev');
        putenv('DB_USERNAME=sail');
        putenv('DB_PASSWORD=password');

        parent::setUp();
        
        \Illuminate\Support\Facades\DB::beginTransaction();

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

    protected function tearDown(): void
    {
        \Illuminate\Support\Facades\DB::rollBack();
        parent::tearDown();
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
}
