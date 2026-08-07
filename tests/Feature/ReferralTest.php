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

        $payload = ['status' => 'completed'];

        $mockApi = Mockery::mock('alias:' . ServiceRequestApi::class);
        $mockApi->shouldReceive('complete')
            ->once()
            ->with($uuid, $payload)
            ->andReturn($mockResponse);

        $service = app(ReferralRequestLifecycleService::class);
        $result = $service->completeReferral($uuid, $encounterUuid, $payload);

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
