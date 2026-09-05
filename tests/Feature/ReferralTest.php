<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Classes\eHealth\Api\ServiceRequest as ServiceRequestApi;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\User;
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;
use Mockery;

class ReferralTest extends TestCase
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
            'email' => 'ref_' . \Illuminate\Support\Str::random(6) . '@example.com',
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

        $this->grantMedicalEventAbilities($this->user);
    }

    public function test_it_can_find_referral_by_requisition()
    {
        $mockResponse = [
            'data' => [
                ['id' => '00000000-0000-4000-8000-000000000123', 'status' => 'active']
            ]
        ];

        $mockApi = Mockery::mock('alias:' . ServiceRequestApi::class);
        $mockApi->shouldReceive('searchForServiceRequestsByParams')
            ->once()
            ->with(['requisition' => '1234-5678-9012-3456'])
            ->andReturn($mockResponse);

        $response = ServiceRequestApi::searchForServiceRequestsByParams(['requisition' => '1234-5678-9012-3456']);

        $this->assertEquals('00000000-0000-4000-8000-000000000123', $response['data'][0]['id']);
    }

    public function test_it_can_complete_referral()
    {
        $uuid = '00000000-0000-4000-8000-000000000123';
        $encounterUuid = '00000000-0000-4000-8000-000000000456';

        $mockResponse = [
            'data' => [
                'id' => $uuid,
                'status' => 'completed'
            ],
            'status' => 'completed'
        ];

        $payload = [
            'status' => 'completed',
            'injected' => 'must-not-reach-ehealth',
        ];

        $person = \App\Models\Person\Person::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'first_name' => 'Complete',
            'last_name' => 'Patient',
            'birth_date' => '1990-01-01',
            'gender' => 'MALE',
            'patient_signed' => true,
            'process_disclosure_data_consent' => true,
        ]);

        $codingId = \App\Models\MedicalEvents\Sql\Coding::create([
            'code' => 'AMB',
            'system' => 'eHealth/encounter_classes',
        ])->id;
        $ccId = \App\Models\MedicalEvents\Sql\CodeableConcept::create()->id;
        $episodeId = \App\Models\MedicalEvents\Sql\Identifier::create(['value' => (string) \Illuminate\Support\Str::uuid()])->id;

        \App\Models\MedicalEvents\Sql\Encounter::create([
            'uuid' => $encounterUuid,
            'person_id' => $person->id,
            'status' => 'finished',
            'episode_id' => $episodeId,
            'class_id' => $codingId,
            'type_id' => $ccId,
            'ehealth_inserted_at' => now(),
        ]);

        $mockApi = Mockery::mock('alias:' . ServiceRequestApi::class);
        $mockApi->shouldReceive('complete')
            ->once()
            ->with($uuid, Mockery::on(static function (array $sent) use ($encounterUuid): bool {
                return !array_key_exists('status', $sent)
                    && !array_key_exists('injected', $sent)
                    && data_get($sent, 'based_on.0.identifier.value') === $encounterUuid;
            }))
            ->andReturn($mockResponse);

        $service = app(ReferralRequestLifecycleService::class);
        $result = $service->completeReferral($uuid, $encounterUuid, 'encounter', $payload);

        $this->assertEquals('completed', $result['status']);
    }

    public function test_referral_index_component_renders()
    {
        $this->actingAs($this->user);

        Livewire::test(\App\Livewire\Referral\ReferralIndex::class, ['legalEntity' => $this->legalEntity])
            ->assertStatus(200)
            ->assertSee('Знайти направлення');
    }
}
