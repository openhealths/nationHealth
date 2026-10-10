<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Classes\eHealth\Api\Patient\MedicationRequest;
use App\Classes\eHealth\EHealthResponse;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;
use Mockery;

class MedicationRequestTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;
    protected LegalEntity $legalEntity;
    protected Employee $employee;

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
        $signature = Mockery::mock(\App\Services\SignatureService::class);
        $signature->shouldReceive('getCertificateAuthorities')->andReturn([]);
        $this->instance(\App\Services\SignatureService::class, $signature);

        $party = \App\Models\Relations\Party::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'first_name' => 'Іван',
            'last_name' => 'Петренко',
            'tax_id' => '9876543210',
            'birth_date' => '1980-08-08',
            'gender' => 'MALE',
        ]);

        $this->user = User::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'email' => 'test_' . \Illuminate\Support\Str::random(6) . '@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $typeId = \Illuminate\Support\Facades\DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? \Illuminate\Support\Facades\DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        $this->legalEntity = LegalEntity::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);
        $this->instance('legalEntity', $this->legalEntity);

        $this->employee = Employee::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
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
        $this->user->employees()->attach($this->employee->id);
    }

    public function test_invalid_prequalify_verdict_is_blocking()
    {
        $mockResponse = [
            'data' => [
                ['status' => 'INVALID']
            ]
        ];

        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('getData')->andReturn($mockResponse);
        $mockApi = Mockery::mock(MedicationRequest::class)->makePartial();
        $mockApi->shouldReceive('prequalify')->once()->with(['person_id' => 'patient-123'])->andReturn($response);
        $this->instance(MedicationRequest::class, $mockApi);

        $this->expectException(\App\Exceptions\EHealth\EHealthValidationException::class);
        $mockApi->prequalifyAndValidate(['person_id' => 'patient-123']);

    }

    public function test_medication_request_index_component_renders()
    {
        $this->actingAs($this->user);

        Livewire::test(\App\Livewire\MedicationRequest\MedicationRequestIndex::class, ['legalEntity' => $this->legalEntity])
            ->assertStatus(200)
            ->assertSee('Електронні рецепти');
    }

    public function test_medication_request_form_component_prequalify()
    {
        $this->actingAs($this->user);

        $mockService = Mockery::mock(MedicationRequest::class);
        $mockService->shouldReceive('prequalifyAndValidate')->once()->with([
            'person_id' => 'uuid-123', 'medical_program_id' => 'program-123', 'programs' => [['id' => 'program-123']],
        ])->andReturnNull();
        $this->app->instance(MedicationRequest::class, $mockService);

        Livewire::test(\App\Livewire\MedicationRequest\MedicationRequestForm::class, ['legalEntity' => $this->legalEntity])
            ->set('patientId', 'uuid-123')
            ->set('medicalProgram', 'program-123')
            ->set('dosageInstruction', 'Take 1 pill')
            ->set('duration', '30')
            ->call('preQualify')
            ->assertSee(__('care-plan.prequalify_passed'));
    }

    public function test_standalone_create_maps_validated_fields_and_keeps_the_accepted_raw_document(): void
    {
        $this->actingAs($this->user);
        $document = ['id' => 'draft-id', 'dosage_instruction' => 'Take 1 pill', 'unknownClinicalKey' => ['keepMe' => 0]];
        $api = Mockery::mock(MedicationRequest::class);
        $api->shouldReceive('createAndResolve')->once()->with([
            'person_id' => 'uuid-123', 'medical_program_id' => 'program-123', 'dosage_instruction' => 'Take 1 pill',
            'dispense_request' => ['expected_supply_duration' => ['value' => 30, 'system' => 'http://unitsofmeasure.org', 'code' => 'd']],
        ])->andReturn(new \App\Dto\MedicationRequest\DraftResult(['data' => $document], ['data' => $document]));
        $this->instance(MedicationRequest::class, $api);

        Livewire::test(\App\Livewire\MedicationRequest\MedicationRequestForm::class, ['legalEntity' => $this->legalEntity])
            ->set('patientId', 'uuid-123')->set('medicalProgram', 'program-123')
            ->set('dosageInstruction', 'Take 1 pill')->set('duration', '30')->set('form.password', 'synthetic-secret')
            ->call('createDraft')->assertHasNoErrors()->assertSet('draftId', 'draft-id')
            ->assertSet('isDraftCreated', true)->assertSet('draftContent', $document);
    }

    public function test_standalone_validation_runs_before_mapper_or_api(): void
    {
        $this->actingAs($this->user);
        $mapper = Mockery::mock(\Symfony\Component\ObjectMapper\ObjectMapperInterface::class);
        $mapper->shouldNotReceive('map');
        $this->instance(\Symfony\Component\ObjectMapper\ObjectMapperInterface::class, $mapper);
        $api = Mockery::mock(MedicationRequest::class);
        $api->shouldNotReceive('createAndResolve');
        $this->instance(MedicationRequest::class, $api);

        Livewire::test(\App\Livewire\MedicationRequest\MedicationRequestForm::class, ['legalEntity' => $this->legalEntity])
            ->set('patientId', 'uuid-123')->set('medicalProgram', 'program-123')
            ->set('dosageInstruction', 'Take 1 pill')->set('duration', '0')
            ->call('createDraft')->assertHasErrors(['duration' => 'min'])->assertSet('isDraftCreated', false);
    }
}
