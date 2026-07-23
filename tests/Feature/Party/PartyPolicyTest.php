<?php

declare(strict_types=1);

namespace Tests\Feature\Party;

use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\Relations\Party;
use App\Models\User;
use App\Policies\PartyPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PartyPolicyTest extends TestCase
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

    /**
     * @return array{legalEntity: LegalEntity, party: Party, user: User}
     */
    private function createFixture(): array
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

        return compact('legalEntity', 'party', 'user');
    }

    public function test_policy_denies_verification_access_without_scope(): void
    {
        ['legalEntity' => $legalEntity, 'party' => $party, 'user' => $user] = $this->createFixture();
        $this->instance('legalEntity', $legalEntity);

        $policy = new PartyPolicy();

        $this->assertTrue($policy->viewAnyVerification($user)->denied());
        $this->assertTrue($policy->viewVerification($user, $party)->denied());
        $this->assertTrue($policy->syncVerification($user)->denied());
        $this->assertTrue($policy->updateVerification($user, $party)->denied());
    }

    public function test_policy_allows_verification_access_with_details_scope(): void
    {
        ['legalEntity' => $legalEntity, 'party' => $party, 'user' => $user] = $this->createFixture();
        $this->instance('legalEntity', $legalEntity);

        if (config('permission.teams')) {
            setPermissionsTeamId($legalEntity->id);
        }

        $user->givePermissionToParent(
            Permission::findOrCreate('party_verification:details', 'web'),
        );

        $policy = new PartyPolicy();

        $this->assertTrue($policy->viewAnyVerification($user)->allowed());
        $this->assertTrue($policy->viewVerification($user, $party)->allowed());
        $this->assertTrue($policy->syncVerification($user)->allowed());
        $this->assertTrue($policy->updateVerification($user, $party)->denied());
    }

    public function test_policy_allows_update_with_write_scope(): void
    {
        ['legalEntity' => $legalEntity, 'party' => $party, 'user' => $user] = $this->createFixture();
        $this->instance('legalEntity', $legalEntity);

        if (config('permission.teams')) {
            setPermissionsTeamId($legalEntity->id);
        }

        $user->givePermissionToParent(
            Permission::findOrCreate('party_verification:details', 'web'),
            Permission::findOrCreate('party_verification:write', 'web'),
        );

        $policy = new PartyPolicy();

        $this->assertTrue($policy->updateVerification($user, $party)->allowed());
    }

    public function test_policy_denies_foreign_party_with_404(): void
    {
        ['legalEntity' => $legalEntity, 'party' => $party, 'user' => $user] = $this->createFixture();

        $foreignEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $legalEntity->legal_entity_type_id,
            'is_active' => true,
        ]);

        $this->instance('legalEntity', $foreignEntity);

        if (config('permission.teams')) {
            setPermissionsTeamId($legalEntity->id);
        }

        $user->givePermissionToParent(
            Permission::findOrCreate('party_verification:details', 'web'),
            Permission::findOrCreate('party_verification:write', 'web'),
        );

        $policy = new PartyPolicy();

        $this->assertSame(404, $policy->viewVerification($user, $party)->status());
        $this->assertSame(404, $policy->updateVerification($user, $party)->status());
    }
}
