<?php

declare(strict_types=1);

namespace Tests\Feature\Party;

use App\Classes\eHealth\Api\Party as PartyApi;
use App\Classes\eHealth\EHealthResponse;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\Relations\Party;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use Spatie\Permission\Models\Permission;
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

    private function grantPartyVerificationPermissions(User $user, LegalEntity $legalEntity): void
    {
        if (config('permission.teams')) {
            setPermissionsTeamId($legalEntity->id);
        }

        $user->givePermissionToParent(
            Permission::findOrCreate('party_verification:details', 'web'),
            Permission::findOrCreate('party_verification:write', 'web'),
        );
    }

    public function test_verification_index_renders_and_filters_using_details_scope(): void
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
            'verification_status' => 'NOT_VERIFIED',
        ]);

        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'hr@example.com',
            'password' => Hash::make('password'),
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

        $this->grantPartyVerificationPermissions($user, $legalEntity);
        $this->actingAs($user);

        session()->put(config('ehealth.api.oauth.bearer_token'), 'test-token');

        $mockPartyApi = Mockery::mock(PartyApi::class);
        $mockPartyApi->shouldReceive('withToken')->andReturnSelf();
        $this->instance(PartyApi::class, $mockPartyApi);

        $detailPayload = [
            'verification_status' => 'NOT_VERIFIED',
            'details' => [
                'drfo' => ['verification_status' => 'VERIFIED'],
                'dracs_death' => ['verification_status' => 'NOT_VERIFIED'],
            ],
        ];

        $mockResponse = Mockery::mock(EHealthResponse::class);
        $mockResponse->shouldReceive('json')->andReturn($detailPayload);

        $mockPartyApi->shouldReceive('getDetails')
            ->with($party->uuid)
            ->andReturn($mockResponse);

        Livewire::test(\App\Livewire\Party\PartyVerificationIndex::class, ['legalEntity' => $legalEntity])
            ->assertSee($party->fullName)
            ->assertSeeHtml('NOT_VERIFIED')
            ->set('dracsDeathStatus', 'NOT_VERIFIED')
            ->assertHasNoErrors();
    }

    public function test_verification_index_shows_local_parties_when_details_api_fails(): void
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
            'first_name' => 'Admin',
            'last_name' => 'Local',
            'tax_id' => '0987654321',
            'birth_date' => '1985-05-05',
            'gender' => 'MALE',
            'verification_status' => 'VERIFICATION_NEEDED',
        ]);

        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'admin-verif@example.com',
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Admin Local',
            'employee_type' => \App\Enums\User\Role::ADMIN->value,
            'status' => \App\Enums\Status::APPROVED->value,
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => 'Administrator',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $user->id,
            'party_id' => $party->id,
        ]);
        $user->employees()->attach($employee->id);

        $this->grantPartyVerificationPermissions($user, $legalEntity);
        $this->actingAs($user);

        session()->put(config('ehealth.api.oauth.bearer_token'), 'test-token');

        $mockPartyApi = Mockery::mock(PartyApi::class);
        $mockPartyApi->shouldReceive('withToken')->andReturnSelf();
        $this->instance(PartyApi::class, $mockPartyApi);

        $mockPartyApi->shouldReceive('getDetails')
            ->with($party->uuid)
            ->andThrow(new \RuntimeException('API unavailable'));

        Livewire::test(\App\Livewire\Party\PartyVerificationIndex::class, ['legalEntity' => $legalEntity])
            ->assertSee($party->fullName)
            ->assertSeeHtml('VERIFICATION_NEEDED');
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
            'verification_status' => 'NOT_VERIFIED',
        ]);

        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'hr@example.com',
            'password' => Hash::make('password'),
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

        $this->grantPartyVerificationPermissions($user, $legalEntity);
        $this->actingAs($user);

        $mockPartyApi = Mockery::mock(PartyApi::class);
        $this->instance(PartyApi::class, $mockPartyApi);

        $detailResponse = [
            'verification_status' => 'NOT_VERIFIED',
            'details' => [
                'drfo' => [
                    'verification_status' => 'VERIFIED',
                    'verification_reason' => 'RULES_PASSED',
                    'result' => 100,
                ],
                'dracs_death' => [
                    'verification_status' => 'NOT_VERIFIED',
                    'verification_reason' => 'RULES_TRIGGERED',
                    'verification_comment' => 'Triggered',
                ],
                'mvs_passport' => [
                    'verification_status' => 'VERIFIED',
                ],
            ],
        ];

        $mockResponse = Mockery::mock(EHealthResponse::class);
        $mockResponse->shouldReceive('json')->andReturn($detailResponse);
        $mockResponse->shouldReceive('getData')->andReturn($detailResponse);

        $mockPartyApi->shouldReceive('getDetails')
            ->with($party->uuid)
            ->andReturn($mockResponse);

        $updateResponse = Mockery::mock(EHealthResponse::class);
        $mockPartyApi->shouldReceive('update')
            ->with($party->uuid, [
                'dracs_death' => [
                    'verification_status' => 'VERIFIED',
                    'verification_reason' => 'MANUAL_NOT_CONFIRMED',
                    'verification_comment' => 'Everything is fine',
                ],
            ])
            ->once()
            ->andReturn($updateResponse);

        Livewire::test(\App\Livewire\Party\PartyVerify::class, ['legalEntity' => $legalEntity, 'party' => $party])
            ->assertSet('canUpdateVerification', true)
            ->call('checkAndOpenModal')
            ->assertSet('showUpdateModal', true)
            ->set('status', 'VERIFIED')
            ->assertSet('reason', '')
            ->set('reason', 'MANUAL_NOT_CONFIRMED')
            ->set('comment', 'Everything is fine')
            ->call('updateStatus')
            ->assertHasNoErrors();
    }

    public function test_party_verify_shows_dms_passport_warning_when_not_verified(): void
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
            'email' => 'hr-dms@example.com',
            'password' => Hash::make('password'),
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

        $this->grantPartyVerificationPermissions($user, $legalEntity);
        $this->actingAs($user);

        $mockPartyApi = Mockery::mock(PartyApi::class);
        $this->instance(PartyApi::class, $mockPartyApi);

        $detailResponse = [
            'verification_status' => 'NOT_VERIFIED',
            'details' => [
                'drfo' => [
                    'verification_status' => 'VERIFIED',
                ],
                'dracs_death' => [
                    'verification_status' => 'VERIFIED',
                ],
                'dms_passport' => [
                    'verification_status' => 'NOT_VERIFIED',
                    'verification_reason' => 'AUTO_NOT_VALID',
                ],
            ],
        ];

        $mockResponse = Mockery::mock(EHealthResponse::class);
        $mockResponse->shouldReceive('json')->andReturn($detailResponse);
        $mockResponse->shouldReceive('getData')->andReturn($detailResponse);

        $mockPartyApi->shouldReceive('getDetails')
            ->with($party->uuid)
            ->andReturn($mockResponse);

        Livewire::test(\App\Livewire\Party\PartyVerify::class, ['legalEntity' => $legalEntity, 'party' => $party])
            ->assertSee('Увага! Персональні дані працівника потребують перевірки:', false)
            ->assertSee('Зазначений паспорт працівника не дійсний за даними ДМС', false)
            ->assertSee('ДПС, ДРАЦСГ або ДМС', false)
            ->assertDontSee('РНОКПП, дата народження або ПІБ не відповідають даним в реєстрі ДПС', false)
            ->assertDontSee('Зафіксовано актовий запис про смерть', false);
    }
}
