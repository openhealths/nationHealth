<?php

declare(strict_types=1);

namespace Tests\Feature\Employee;

use App\Classes\eHealth\Api\EmployeeApi;
use App\Enums\Employee\RequestStatus;
use App\Enums\Status;
use App\Enums\User\Role;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Jobs\EmployeeDetailsUpsert;
use App\Models\Employee\Employee;
use App\Models\Employee\EmployeeRequest;
use App\Models\LegalEntity;
use App\Models\Permission;
use App\Models\Relations\Party;
use App\Models\User;
use App\Repositories\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SyncUserEmployeesAndRolesTest extends TestCase
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

    #[Test]
    public function two_users_in_same_party_receive_only_their_own_employee_roles(): void
    {
        $legalEntity = $this->createLegalEntity();
        $party = $this->createParty();

        $ownerUser = $this->createUser($party, 'owner@example.com', '2026-07-16 23:59:40');
        $hrUser = $this->createUser($party, 'hr@example.com', '2026-07-10 10:00:00');

        $ownerEmployee = $this->createEmployee($legalEntity, $party, Role::OWNER->value, 'P2', $ownerUser->id, '2026-08-05 12:00:00');
        $hrEmployee = $this->createEmployee($legalEntity, $party, Role::HR->value, 'P14', null, '2026-07-01 12:00:00');

        EmployeeRequest::create([
            'uuid' => (string) Str::uuid(),
            'legal_entity_id' => $legalEntity->id,
            'status' => RequestStatus::APPROVED->value,
            'position' => 'P14',
            'start_date' => $hrEmployee->getRawOriginal('start_date'),
            'employee_type' => Role::HR->value,
            'email' => $hrUser->email,
            'party_id' => $party->id,
            'employee_id' => $hrEmployee->id,
            'applied_at' => '2026-07-01 12:00:00',
        ]);

        setPermissionsTeamId($legalEntity->id);

        Repository::party()->syncUserEmployeesAndRoles($party->fresh(), $legalEntity->fresh());

        $ownerUser->unsetRelation('roles');
        $hrUser->unsetRelation('roles');

        $this->assertTrue($ownerUser->hasRole(Role::OWNER->value));
        $this->assertFalse($ownerUser->hasRole(Role::HR->value));
        $this->assertTrue($hrUser->hasRole(Role::HR->value));
        $this->assertFalse($hrUser->hasRole(Role::OWNER->value));

        $this->assertDatabaseHas('employee_users', [
            'employee_id' => $ownerEmployee->id,
            'user_id' => $ownerUser->id,
        ]);
        $this->assertDatabaseHas('employee_users', [
            'employee_id' => $hrEmployee->id,
            'user_id' => $hrUser->id,
        ]);
        $this->assertDatabaseMissing('employee_users', [
            'employee_id' => $hrEmployee->id,
            'user_id' => $ownerUser->id,
        ]);
    }

    #[Test]
    public function older_employee_is_bound_by_request_email_without_date_filter(): void
    {
        $legalEntity = $this->createLegalEntity();
        $party = $this->createParty();

        $user = $this->createUser($party, 'doctor@example.com', '2026-07-16 23:59:40');

        $employee = $this->createEmployee(
            $legalEntity,
            $party,
            Role::SPECIALIST->value,
            'P56',
            null,
            '2026-06-01 10:00:00'
        );

        EmployeeRequest::create([
            'uuid' => (string) Str::uuid(),
            'legal_entity_id' => $legalEntity->id,
            'status' => RequestStatus::APPROVED->value,
            'position' => 'P56',
            'start_date' => $employee->getRawOriginal('start_date'),
            'employee_type' => Role::SPECIALIST->value,
            'email' => $user->email,
            'party_id' => $party->id,
            'applied_at' => '2026-06-01 10:00:00',
        ]);

        setPermissionsTeamId($legalEntity->id);

        Repository::party()->syncUserEmployeesAndRoles($party->fresh(), $legalEntity->fresh());

        $user->unsetRelation('roles');

        $this->assertTrue($user->hasRole(Role::SPECIALIST->value));
        $this->assertDatabaseHas('employee_users', [
            'employee_id' => $employee->id,
            'user_id' => $user->id,
        ]);
    }

    #[Test]
    public function logined_role_without_matching_employee_does_not_throw(): void
    {
        $legalEntity = $this->createLegalEntity();
        $party = $this->createParty();
        $user = $this->createUser($party, 'owner@example.com', '2026-07-16 23:59:40');

        $this->createEmployee($legalEntity, $party, Role::OWNER->value, 'P2', $user->id, '2026-08-05 12:00:00');

        setPermissionsTeamId($legalEntity->id);
        $this->actingAs($user);
        Session::put('first_login_role', Role::HR->value);

        Repository::party()->syncUserEmployeesAndRoles($party->fresh(), $legalEntity->fresh());

        $user->unsetRelation('roles');
        $this->assertTrue($user->hasRole(Role::OWNER->value));
        $this->assertFalse($user->hasRole(Role::HR->value));
    }

    #[Test]
    public function has_permission_to_uses_roles_even_when_first_login_role_is_in_session(): void
    {
        $legalEntity = $this->createLegalEntity();
        $party = $this->createParty();
        $user = $this->createUser($party, 'owner@example.com', '2026-07-16 23:59:40');

        setPermissionsTeamId($legalEntity->id);

        $permission = Permission::findOrCreate('employee:read', 'ehealth');
        $role = \App\Models\Role::findByName(Role::OWNER->value, 'ehealth');
        $role->givePermissionTo($permission);
        $user->assignRole($role);

        Session::put('first_login_role', Role::OWNER->value);

        $this->assertTrue($user->hasPermissionTo('employee:read', 'ehealth'));
    }

    #[Test]
    public function employee_details_upsert_does_not_overwrite_existing_user_id(): void
    {
        $legalEntity = $this->createLegalEntity();
        $party = $this->createParty();
        $boundUser = $this->createUser($party, 'bound@example.com', '2026-07-16 23:59:40');
        $otherUser = $this->createUser($party, 'other@example.com', '2026-07-10 10:00:00');

        $employee = $this->createEmployee(
            $legalEntity,
            $party,
            Role::HR->value,
            'P14',
            $boundUser->id,
            '2026-07-01 12:00:00'
        );

        EmployeeRequest::create([
            'uuid' => (string) Str::uuid(),
            'legal_entity_id' => $legalEntity->id,
            'status' => RequestStatus::APPROVED->value,
            'position' => 'P14',
            'start_date' => $employee->getRawOriginal('start_date'),
            'employee_type' => Role::HR->value,
            'email' => $otherUser->email,
            'party_id' => $party->id,
            'employee_id' => $employee->id,
            'applied_at' => '2026-07-01 12:00:00',
        ]);

        $job = new EmployeeDetailsUpsert($employee, $legalEntity);
        $method = new ReflectionMethod(EmployeeDetailsUpsert::class, 'resolveEmployeeRequest');
        $method->setAccessible(true);

        /** @var EmployeeRequest $request */
        $request = $method->invoke(
            $job,
            $legalEntity->id,
            Role::HR->value,
            null,
            $employee->getRawOriginal('start_date')
        );

        $this->assertNotNull($request);
        $this->assertSame($employee->id, $request->employee_id);

        $resolvedUserId = $employee->userId ?? User::where('email', $request->email)->first()?->id;
        $this->assertSame($boundUser->id, $resolvedUserId);
    }

    #[Test]
    public function employee_api_authenticate_prefers_user_scopes_when_roles_already_exist(): void
    {
        $legalEntity = $this->createLegalEntity();
        $party = $this->createParty();
        $user = $this->createUser($party, 'owner@example.com', '2026-07-16 23:59:40');

        setPermissionsTeamId($legalEntity->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::findOrCreate('division:read', 'ehealth');
        $role = \App\Models\Role::findByName(Role::OWNER->value, 'ehealth');
        $role->givePermissionTo($permission);

        Auth::shouldUse('ehealth');
        $user->assignRole($role);
        $user->givePermissionTo($permission);

        Session::put('first_login_role', Role::HR->value);

        $user->refresh();
        $user->unsetRelation('roles')->unsetRelation('permissions');

        $hasRolesForLegalEntity = $user->roles()->where('roles.guard_name', 'ehealth')->exists();
        $this->assertTrue($hasRolesForLegalEntity);

        // Mirrors EmployeeApi::authenticate: once roles exist, use getScopes() even with first_login_role set
        $role = Session::get('first_login_role');
        $scope = ($role && !$hasRolesForLegalEntity)
            ? ''
            : $user->getScopes();

        $this->assertStringContainsString('division:read', $scope);
    }

    #[Test]
    public function ownerless_employees_are_bound_to_users_by_request_email(): void
    {
        $legalEntity = $this->createLegalEntity();
        $party = $this->createParty();
        $user = $this->createUser($party, 'hr@example.com', '2026-07-16 23:59:40');

        // Synced from eHealth: no user_id, created before the account existed
        $employee = $this->createEmployee($legalEntity, $party, Role::HR->value, 'P14', null, '2026-06-01 10:00:00');

        EmployeeRequest::create([
            'uuid' => (string) Str::uuid(),
            'legal_entity_id' => $legalEntity->id,
            'status' => RequestStatus::APPROVED->value,
            'position' => 'P14',
            'start_date' => $employee->getRawOriginal('start_date'),
            'employee_type' => Role::HR->value,
            'email' => $user->email,
            'party_id' => $party->id,
            'applied_at' => '2026-06-01 10:00:00',
        ]);

        setPermissionsTeamId($legalEntity->id);

        $affectedParties = Repository::employee()->bindOwnerlessEmployeesToUsers($legalEntity);

        $this->assertSame([$party->id], $affectedParties);
        $this->assertSame($user->id, $employee->fresh()->user_id);
        $this->assertDatabaseHas('employee_users', [
            'employee_id' => $employee->id,
            'user_id' => $user->id,
        ]);

        Repository::party()->syncUserEmployeesAndRoles($party->fresh(), $legalEntity->fresh());

        $user->unsetRelation('roles');
        $this->assertTrue($user->hasRole(Role::HR->value));
    }

    #[Test]
    public function binding_does_not_steal_a_user_belonging_to_another_party(): void
    {
        $legalEntity = $this->createLegalEntity();
        $party = $this->createParty();

        $otherParty = Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Olena',
            'last_name' => 'Shevchenko',
            'tax_id' => '1112223334',
            'birth_date' => '1985-05-05',
            'gender' => 'FEMALE',
        ]);

        $foreignUser = $this->createUser($otherParty, 'foreign@example.com', '2026-07-01 10:00:00');

        $employee = $this->createEmployee($legalEntity, $party, Role::HR->value, 'P14', null, '2026-06-01 10:00:00');

        EmployeeRequest::create([
            'uuid' => (string) Str::uuid(),
            'legal_entity_id' => $legalEntity->id,
            'status' => RequestStatus::APPROVED->value,
            'position' => 'P14',
            'start_date' => $employee->getRawOriginal('start_date'),
            'employee_type' => Role::HR->value,
            'email' => $foreignUser->email,
            'party_id' => $party->id,
            'employee_id' => $employee->id,
            'applied_at' => '2026-06-01 10:00:00',
        ]);

        setPermissionsTeamId($legalEntity->id);

        $affectedParties = Repository::employee()->bindOwnerlessEmployeesToUsers($legalEntity);

        $this->assertSame([], $affectedParties);
        $this->assertNull($employee->fresh()->user_id);
        $this->assertSame($otherParty->id, $foreignUser->fresh()->party_id);
    }

    #[Test]
    public function binding_links_party_to_user_that_has_none(): void
    {
        $legalEntity = $this->createLegalEntity();
        $party = $this->createParty();

        $user = User::forceCreate([
            'uuid' => (string) Str::uuid(),
            'email' => 'nobody@example.com',
            'password' => Hash::make('password'),
            'party_id' => null,
            'inserted_at' => '2026-07-16 23:59:40',
            'email_verified_at' => now(),
        ]);

        $employee = $this->createEmployee($legalEntity, $party, Role::SPECIALIST->value, 'P56', null, '2026-06-01 10:00:00');

        EmployeeRequest::create([
            'uuid' => (string) Str::uuid(),
            'legal_entity_id' => $legalEntity->id,
            'status' => RequestStatus::APPROVED->value,
            'position' => 'P56',
            'start_date' => $employee->getRawOriginal('start_date'),
            'employee_type' => Role::SPECIALIST->value,
            'email' => $user->email,
            'party_id' => $party->id,
            'employee_id' => $employee->id,
            'applied_at' => '2026-06-01 10:00:00',
        ]);

        setPermissionsTeamId($legalEntity->id);

        Repository::employee()->bindOwnerlessEmployeesToUsers($legalEntity);

        $this->assertSame($party->id, $user->fresh()->party_id);
        $this->assertSame($user->id, $employee->fresh()->user_id);
    }

    #[Test]
    public function ownerless_employee_is_not_given_to_a_party_member_with_a_foreign_request_email(): void
    {
        $legalEntity = $this->createLegalEntity();
        $party = $this->createParty();

        // The position was created in eHealth under an email we have no account for. Granting it
        // to a party member would claim a role eHealth does not give them, breaking their login.
        $partyUser = $this->createUser($party, 'party-member@example.com', '2026-07-01 10:00:00');

        $employee = $this->createEmployee($legalEntity, $party, Role::HR->value, 'P14', null, '2026-06-01 10:00:00');

        EmployeeRequest::create([
            'uuid' => (string) Str::uuid(),
            'legal_entity_id' => $legalEntity->id,
            'status' => RequestStatus::APPROVED->value,
            'position' => 'P14',
            'start_date' => $employee->getRawOriginal('start_date'),
            'employee_type' => Role::HR->value,
            'email' => 'someone-else@example.com',
            'party_id' => $party->id,
            'employee_id' => $employee->id,
            'applied_at' => '2026-06-01 10:00:00',
        ]);

        setPermissionsTeamId($legalEntity->id);

        $affectedParties = Repository::employee()->bindOwnerlessEmployeesToUsers($legalEntity);

        $this->assertSame([], $affectedParties);
        $this->assertNull($employee->fresh()->user_id);

        Repository::party()->syncUserEmployeesAndRoles($party->fresh(), $legalEntity->fresh());

        $partyUser->unsetRelation('roles');
        $this->assertFalse($partyUser->hasRole(Role::HR->value));
    }

    #[Test]
    public function ownerless_employee_stays_unbound_when_party_has_no_users(): void
    {
        $legalEntity = $this->createLegalEntity();
        $party = $this->createParty();

        $employee = $this->createEmployee($legalEntity, $party, Role::HR->value, 'P14', null, '2026-06-01 10:00:00');

        EmployeeRequest::create([
            'uuid' => (string) Str::uuid(),
            'legal_entity_id' => $legalEntity->id,
            'status' => RequestStatus::APPROVED->value,
            'position' => 'P14',
            'start_date' => $employee->getRawOriginal('start_date'),
            'employee_type' => Role::HR->value,
            'email' => 'unknown@example.com',
            'party_id' => $party->id,
            'employee_id' => $employee->id,
            'applied_at' => '2026-06-01 10:00:00',
        ]);

        setPermissionsTeamId($legalEntity->id);

        $affectedParties = Repository::employee()->bindOwnerlessEmployeesToUsers($legalEntity);

        $this->assertSame([], $affectedParties);
        $this->assertNull($employee->fresh()->user_id);
    }

    #[Test]
    public function scope_rejection_is_detected_and_retried_with_the_last_granted_scopes(): void
    {
        $legalEntity = $this->createLegalEntity();
        $party = $this->createParty();
        $user = $this->createUser($party, 'owner@example.com', '2026-07-16 23:59:40');

        setPermissionsTeamId($legalEntity->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Auth::shouldUse('ehealth');

        $divisionRead = Permission::findOrCreate('division:read', 'ehealth');
        $employeeRead = Permission::findOrCreate('employee:read', 'ehealth');

        $ownerRole = \App\Models\Role::findByName(Role::OWNER->value, 'ehealth');
        $ownerRole->givePermissionTo($divisionRead);
        $ownerRole->givePermissionTo($employeeRead);
        $user->assignRole($ownerRole);

        // What eHealth granted on the previous login, stored as direct permissions
        $user->givePermissionTo($divisionRead);
        $user->givePermissionTo($employeeRead);

        $isScopeRejection = new ReflectionMethod(EmployeeApi::class, 'isScopeRejection');
        $lastGrantedScope = new ReflectionMethod(EmployeeApi::class, 'lastGrantedScope');

        $scopeError = new EHealthValidationException([
            'error' => ['message' => 'User requested scope that is not allowed by role based access policies.'],
        ]);
        $otherError = new EHealthValidationException([
            'error' => ['message' => 'Employee not found'],
        ]);

        $this->assertTrue($isScopeRejection->invoke(null, $scopeError));
        $this->assertFalse($isScopeRejection->invoke(null, $otherError));

        $granted = $lastGrantedScope->invoke(null, $user->fresh());

        $this->assertStringContainsString('division:read', $granted);
        $this->assertStringContainsString('employee:read', $granted);
        $this->assertSame('', $lastGrantedScope->invoke(null, null));
    }

    private function createLegalEntity(string $typeName = 'OUTPATIENT'): LegalEntity
    {
        $typeId = DB::table('legal_entity_types')->where('name', $typeName)->value('id')
            ?? DB::table('legal_entity_types')->insertGetId(['name' => $typeName]);

        return LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);
    }

    private function createParty(): Party
    {
        return Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Andrii',
            'last_name' => 'Kopylets',
            'tax_id' => '3461807396',
            'birth_date' => '1990-01-01',
            'gender' => 'MALE',
        ]);
    }

    private function createUser(Party $party, string $email, string $insertedAt): User
    {
        return User::forceCreate([
            'uuid' => (string) Str::uuid(),
            'email' => $email,
            'password' => Hash::make('password'),
            'party_id' => $party->id,
            'inserted_at' => $insertedAt,
            'email_verified_at' => now(),
        ]);
    }

    private function createEmployee(
        LegalEntity $legalEntity,
        Party $party,
        string $employeeType,
        string $position,
        ?int $userId,
        string $insertedAt
    ): Employee {
        return Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Andrii Kopylets',
            'employee_type' => $employeeType,
            'status' => Status::APPROVED->value,
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => $position,
            'start_date' => '2026-07-01',
            'user_id' => $userId,
            'party_id' => $party->id,
            'inserted_at' => $insertedAt,
        ]);
    }
}
