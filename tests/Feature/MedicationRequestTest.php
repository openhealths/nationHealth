<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Classes\eHealth\Api\MedicationRequest;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\User;
use App\Services\MedicalEvents\MedicationRequestLifecycleService;
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

    public function test_it_can_prequalify_medication_request()
    {
        $mockResponse = [
            'data' => [
                ['status' => 'valid']
            ]
        ];

        $mockApi = Mockery::mock('alias:' . MedicationRequest::class);
        $mockApi->shouldReceive('preQualify')
            ->once()
            ->with(['person_id' => 'patient-123'])
            ->andReturn($mockResponse);

        $service = app(MedicationRequestLifecycleService::class);
        $result = $service->preQualify(['person_id' => 'patient-123']);

        $this->assertEquals('valid', $result[0]['status']);
    }

    public function test_medication_request_index_component_renders()
    {
        $this->actingAs($this->user);

        Livewire::test(\App\Livewire\MedicationRequest\MedicationRequestIndex::class, ['legalEntity' => $this->legalEntity])
            ->assertStatus(200)
            ->assertSee('Е-Рецепти');
    }

    public function test_medication_request_form_component_prequalify()
    {
        $this->actingAs($this->user);

        $mockService = Mockery::mock(MedicationRequestLifecycleService::class);
        $mockService->shouldReceive('preQualify')->once()->andReturn([]);
        $this->app->instance(MedicationRequestLifecycleService::class, $mockService);

        Livewire::test(\App\Livewire\MedicationRequest\MedicationRequestForm::class, ['legalEntity' => $this->legalEntity])
            ->set('patientId', 'uuid-123')
            ->set('medicalProgram', 'program-123')
            ->set('dosageInstruction', 'Take 1 pill')
            ->set('duration', '30')
            ->call('preQualify')
            ->assertSee('PreQualify успішно пройдено');
    }
}
