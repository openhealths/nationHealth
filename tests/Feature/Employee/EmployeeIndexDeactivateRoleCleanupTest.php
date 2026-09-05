<?php

declare(strict_types=1);

namespace Tests\Feature\Employee;

use App\Classes\eHealth\Api\Employee as EmployeeApi;
use App\Classes\eHealth\EHealthResponse;
use App\Enums\Status;
use App\Enums\User\Role;
use App\Livewire\Actions\Logout;
use App\Livewire\Employee\EmployeeIndex;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\Permission;
use App\Models\Relations\Party;
use App\Models\Role as ModelsRole;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmployeeIndexDeactivateRoleCleanupTest extends TestCase
{
    use DatabaseTransactions;

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
    public function deactivate_keeps_role_when_user_has_another_approved_employee_of_same_type(): void
    {
        [$legalEntity, $user, $employeeToDeactivate, $remainingEmployee] = $this->createSameTypeScenario();
        $this->assignRoleForLegalEntity($user, $legalEntity, Role::ADMIN->value);
        $this->instance('legalEntity', $legalEntity);
        $this->mockSuccessfulDeactivate();
        $this->mockLogout(shouldBeCalled: true);
        Auth::shouldUse('ehealth');

        Livewire::actingAs($user, 'ehealth')
            ->test(EmployeeIndex::class, ['legalEntity' => $legalEntity])
            ->set('employeeIdToDeactivate', $employeeToDeactivate->id)
            ->set('deactivationEndDate', now('Europe/Kyiv')->format(config('app.date_format')))
            ->call('deactivate');

        setPermissionsTeamId($legalEntity->id);
        $this->assertTrue($user->fresh()->hasRole(Role::ADMIN->value));
        $this->assertSame(Status::APPROVED->value, $remainingEmployee->fresh()->status->value);
    }

    #[Test]
    public function deactivate_removes_role_when_no_other_approved_employee_of_same_type_exists(): void
    {
        [$legalEntity, $user, $employee] = $this->createSingleEmployeeScenario(Role::HR->value);
        $this->assignRoleForLegalEntity($user, $legalEntity, Role::HR->value);
        $this->assignRoleForLegalEntity($user, $legalEntity, Role::ADMIN->value);
        $this->instance('legalEntity', $legalEntity);
        $this->mockSuccessfulDeactivate();
        $this->mockLogout(shouldBeCalled: true);
        Auth::shouldUse('ehealth');

        Livewire::actingAs($user, 'ehealth')
            ->test(EmployeeIndex::class, ['legalEntity' => $legalEntity])
            ->set('employeeIdToDeactivate', $employee->id)
            ->set('deactivationEndDate', now('Europe/Kyiv')->format(config('app.date_format')))
            ->call('deactivate');

        setPermissionsTeamId($legalEntity->id);
        $this->assertFalse($user->fresh()->hasRole(Role::HR->value));
        $this->assertTrue($user->fresh()->hasRole(Role::ADMIN->value));
    }

    #[Test]
    public function deactivate_clears_direct_permissions_for_linked_user(): void
    {
        [$legalEntity, $user, $employee] = $this->createSingleEmployeeScenario(Role::RECEPTIONIST->value);
        $this->assignDirectPermission($user, $legalEntity, 'receptionist:write');
        $this->instance('legalEntity', $legalEntity);
        $this->mockSuccessfulDeactivate();
        $this->mockLogout(shouldBeCalled: true);
        Auth::shouldUse('ehealth');

        Livewire::actingAs($user, 'ehealth')
            ->test(EmployeeIndex::class, ['legalEntity' => $legalEntity])
            ->set('employeeIdToDeactivate', $employee->id)
            ->set('deactivationEndDate', now('Europe/Kyiv')->format(config('app.date_format')))
            ->call('deactivate');

        setPermissionsTeamId($legalEntity->id);
        $this->assertCount(0, $user->fresh()->getDirectPermissions());
    }

    #[Test]
    public function deactivate_accepts_display_format_end_date_when_start_date_uses_app_format(): void
    {
        [$legalEntity, $user, $employee] = $this->createSingleEmployeeScenario(Role::RECEPTIONIST->value);
        $employee->update(['start_date' => now('Europe/Kyiv')->subMonth()->format('Y-m-d')]);
        $this->instance('legalEntity', $legalEntity);
        $this->mockSuccessfulDeactivate();
        $this->mockLogout(shouldBeCalled: true);
        Auth::shouldUse('ehealth');

        Livewire::actingAs($user, 'ehealth')
            ->test(EmployeeIndex::class, ['legalEntity' => $legalEntity])
            ->set('employeeIdToDeactivate', $employee->id)
            ->set('deactivationEndDate', now('Europe/Kyiv')->format(config('app.date_format')))
            ->call('deactivate');
    }

    /**
     * @return array{0: LegalEntity, 1: User, 2: Employee, 3: Employee}
     */
    private function createSameTypeScenario(): array
    {
        [$legalEntity, $party, $user] = $this->createBaseFixture();

        $employeeToDeactivate = $this->createEmployee($legalEntity, $party, Role::ADMIN->value, $user->id);
        $remainingEmployee = $this->createEmployee($legalEntity, $party, Role::ADMIN->value, $user->id);

        return [$legalEntity, $user, $employeeToDeactivate, $remainingEmployee];
    }

    /**
     * @return array{0: LegalEntity, 1: User, 2: Employee}
     */
    private function createSingleEmployeeScenario(string $employeeType): array
    {
        [$legalEntity, $party, $user] = $this->createBaseFixture();
        $employee = $this->createEmployee($legalEntity, $party, $employeeType, $user->id);

        return [$legalEntity, $user, $employee];
    }

    /**
     * @return array{0: LegalEntity, 1: Party, 2: User}
     */
    private function createBaseFixture(): array
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
            'first_name' => 'Ivan',
            'last_name' => 'Petrenko',
            'tax_id' => '1234567890',
            'birth_date' => '1990-01-01',
            'gender' => 'MALE',
        ]);

        $user = User::forceCreate([
            'email' => 'deactivate-role-'.Str::random(6).'@example.com',
            'password' => bcrypt('password'),
            'party_id' => $party->id,
            'uuid' => (string) Str::uuid(),
            'email_verified_at' => now(),
        ]);

        return [$legalEntity, $party, $user];
    }

    private function createEmployee(
        LegalEntity $legalEntity,
        Party $party,
        string $employeeType,
        int $userId,
    ): Employee {
        return Employee::create([
            'uuid' => (string) Str::uuid(),
            'employee_type' => $employeeType,
            'status' => Status::APPROVED->value,
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => 'P10',
            'start_date' => '2020-01-01',
            'user_id' => $userId,
            'party_id' => $party->id,
        ]);
    }

    private function assignDirectPermission(User $user, LegalEntity $legalEntity, string $permissionName): void
    {
        if (config('permission.teams')) {
            setPermissionsTeamId($legalEntity->id);
        }

        $permission = Permission::findOrCreate($permissionName, 'web');

        \Illuminate\Support\Facades\DB::table('model_has_permissions')->insert([
            'permission_id' => $permission->id,
            'model_type' => User::class,
            'model_id' => $user->id,
            'legal_entity_id' => $legalEntity->id,
        ]);
    }

    private function assignRoleForLegalEntity(User $user, LegalEntity $legalEntity, string $roleName): void
    {
        if (config('permission.teams')) {
            setPermissionsTeamId($legalEntity->id);
        }

        foreach (array_keys((array) config('auth.guards')) as $guard) {
            ModelsRole::findOrCreate($roleName, $guard);
            $user->assignRole($roleName, $guard);
        }
    }

    private function mockSuccessfulDeactivate(): void
    {
        $response = Mockery::mock(EHealthResponse::class);

        $employeeApi = Mockery::mock(EmployeeApi::class);
        $employeeApi->shouldReceive('deactivate')->once()->andReturn($response);
        $this->instance(EmployeeApi::class, $employeeApi);
    }

    private function mockLogout(bool $shouldBeCalled): void
    {
        $logout = Mockery::mock(Logout::class);

        if ($shouldBeCalled) {
            $logout->shouldReceive('__invoke')
                ->once()
                ->with(true, __('employees.dismissalSuccess'))
                ->andReturn(redirect()->route('login'));
        } else {
            $logout->shouldNotReceive('__invoke');
        }

        $this->instance(Logout::class, $logout);
    }
}
