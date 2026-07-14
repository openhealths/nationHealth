<?php

declare(strict_types=1);

namespace Tests\Feature\Party;

use App\Classes\eHealth\Api\Party as PartyApi;
use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\EHealthResponse;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\Relations\Party;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class PartyVerificationTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_verification_index_renders_and_filters(): void
    {
        $typeId = \Illuminate\Support\Facades\DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? \Illuminate\Support\Facades\DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        $legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);

        $party = Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'John',
            'last_name' => 'Doe',
            'tax_id' => '1234567890',
            'birth_date' => '1990-01-01',
            'gender' => 'MALE',
        ]);

        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'hr@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'John Doe',
            'employee_type' => \App\Enums\User\Role::HR->value,
            'status' => \App\Enums\Status::APPROVED->value,
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => 'HR Manager',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $user->id,
            'party_id' => $party->id,
        ]);
        $user->employees()->attach($employee->id);

        if (config('permission.teams')) {
            setPermissionsTeamId($legalEntity->id);
        }

        $this->actingAs($user);

        // Mock EHealth Party API
        $mockPartyApi = Mockery::mock(PartyApi::class);
        $mockPartyApi->shouldReceive('withToken')->andReturnSelf();
        $this->instance(PartyApi::class, $mockPartyApi);

        // Expect getMany call for list
        $responseList = [
            'data' => [
                [
                    'party_id' => $party->uuid,
                    'verification_status' => 'NOT_VERIFIED',
                    'details' => [
                        'drfo' => ['verification_status' => 'VERIFIED'],
                        'dracs_death' => ['verification_status' => 'NOT_VERIFIED'],
                    ]
                ]
            ],
            'paging' => [
                'total_entries' => 1
            ]
        ];

        $mockResponse = Mockery::mock(EHealthResponse::class);
        $mockResponse->shouldReceive('json')->andReturn($responseList);
        $mockResponse->shouldReceive('getData')->andReturn($responseList);

        $mockPartyApi->shouldReceive('getMany')
            ->with([], 1)
            ->twice()
            ->andReturn($mockResponse);

        // Livewire test the index component
        Livewire::test(\App\Livewire\Party\PartyVerificationIndex::class, ['legalEntity' => $legalEntity])
            ->assertSee($party->fullName)
            ->assertSeeHtml('NOT_VERIFIED')
            ->set('dracsDeathStatus', 'NOT_VERIFIED')
            ->assertHasNoErrors();
    }

    public function test_party_verify_allows_updating_status(): void
    {
        $typeId = \Illuminate\Support\Facades\DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? \Illuminate\Support\Facades\DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        $legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);

        $party = Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'John',
            'last_name' => 'Doe',
            'tax_id' => '1234567890',
            'birth_date' => '1990-01-01',
            'gender' => 'MALE',
        ]);

        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'hr@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'John Doe',
            'employee_type' => \App\Enums\User\Role::HR->value,
            'status' => \App\Enums\Status::APPROVED->value,
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => 'HR Manager',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $user->id,
            'party_id' => $party->id,
        ]);
        $user->employees()->attach($employee->id);

        if (config('permission.teams')) {
            setPermissionsTeamId($legalEntity->id);
        }

        $this->actingAs($user);

        // Mock EHealth Party API
        $mockPartyApi = Mockery::mock(PartyApi::class);
        $this->instance(PartyApi::class, $mockPartyApi);

        // Detail response
        $detailResponse = [
            'verification_status' => 'NOT_VERIFIED',
            'details' => [
                'drfo' => [
                    'verification_status' => 'VERIFIED',
                    'verification_reason' => 'RULES_PASSED',
                    'result' => 100
                ],
                'dracs_death' => [
                    'verification_status' => 'NOT_VERIFIED',
                    'verification_reason' => 'RULES_TRIGGERED',
                    'verification_comment' => 'Triggered'
                ],
                'mvs_passport' => [
                    'verification_status' => 'VERIFIED',
                ]
            ]
        ];

        $mockResponse = Mockery::mock(EHealthResponse::class);
        $mockResponse->shouldReceive('json')->andReturn($detailResponse);
        $mockResponse->shouldReceive('getData')->andReturn($detailResponse);

        $mockPartyApi->shouldReceive('getDetails')
            ->with($party->uuid)
            ->andReturn($mockResponse);

        // Expect update call
        $updateResponse = Mockery::mock(EHealthResponse::class);
        $mockPartyApi->shouldReceive('update')
             ->with($party->uuid, [
                 'dracs_death' => [
                     'verification_status' => 'VERIFIED',
                     'verification_reason' => 'MANUAL_NOT_CONFIRMED',
                     'verification_comment' => 'Everything is fine',
                 ]
             ])
             ->once()
             ->andReturn($updateResponse);

        // Livewire test the detail component
        Livewire::test(\App\Livewire\Party\PartyVerify::class, ['legalEntity' => $legalEntity, 'party' => $party])
            ->assertSet('canUpdateVerification', true)
            ->call('checkAndOpenModal')
            ->assertSet('showUpdateModal', true)
            ->set('status', 'VERIFIED')
            ->assertSet('reason', '') // Assert reactivity resets reason
            ->set('reason', 'MANUAL_NOT_CONFIRMED')
            ->set('comment', 'Everything is fine')
            ->call('updateStatus')
            ->assertHasNoErrors();
    }
}
