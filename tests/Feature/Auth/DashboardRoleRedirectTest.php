<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Auth\SessionBinder;
use App\Classes\eHealth\Api\Auth as AuthApi;
use App\Livewire\Employee\EmployeeIndex;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\LegalEntityType;
use App\Models\Permission;
use App\Models\Relations\Party;
use App\Models\Role;
use App\Models\User;
use App\Services\Dictionary\Dictionaries\BasicDictionary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DashboardRoleRedirectTest extends TestCase
{
    use DatabaseTransactions;

    #[DataProvider('singleRoleLogins')]
    public function test_single_role_login_redirects_to_a_page_that_opens(
        string $type,
        string $roleName,
        string $expectedRoute
    ): void {
        Http::preventStrayRequests();
        $this->mock(AuthApi::class)->shouldNotReceive('logout');
        config(['cache.default' => 'array', 'permission.cache.store' => 'array']);
        $dictionaryNames = [...new EmployeeIndex()->dictionaryNames, 'LANGUAGE'];
        Cache::put(BasicDictionary::KEY, array_map(fn (string $name): array => [
            'name' => $name,
            'values' => [['code' => 'TEST', 'description' => 'Тест']],
        ], $dictionaryNames));
        Cache::put(BasicDictionary::KEY . ':fresh', true);
        [$legalEntity, $user] = $this->createLogin($type, $roleName);
        $this->actingAs($user, 'ehealth');
        $this->withSession([
            'single_role_login' => $roleName,
            'ehealth_legal_entity_uuid' => $legalEntity->uuid,
            'auth_token_expires_at' => now()->addHour()->timestamp,
            config('ehealth.api.oauth.bearer_token') => 'dashboard-test-token',
            config('ehealth.api.oauth.token_scopes') => $user->getDirectPermissions()->pluck('name')->all(),
        ]);
        new SessionBinder()->bind($user);
        session()->save();
        $this->withCookies([
            config('session.cookie') => session()->getId(),
            config('session.cookie') . '_binding' => session('session_binding.token'),
        ]);

        $this->assertSame([$roleName], $user->allowedRoles->all());
        $this->assertSame([$roleName], $user->roles->pluck('name')->all());
        $target = route($expectedRoute, ['legalEntity' => $legalEntity]);

        $this->get(route('dashboard', ['legalEntity' => $legalEntity]))->assertRedirect($target);
        $this->get($target)->assertOk();
        Http::assertNothingSent();
    }

    public static function singleRoleLogins(): array
    {
        $cases = [];
        $destinations = [
            'PRIMARY_CARE' => [
                'REORGANIZATION_OWNER' => 'employee.index',
                'OWNER' => 'employee.index',
                'HR' => 'employee.index',
                'DOCTOR' => 'persons.index',
                'ASSISTANT' => 'persons.index',
                'ADMIN' => 'employee.index',
                'RECEPTIONIST' => 'persons.index',
                'MED_ADMIN' => 'persons.index',
                'LABORANT' => 'employee.index',
            ],
            'OUTPATIENT' => [
                'REORGANIZATION_OWNER' => 'employee.index',
                'OWNER' => 'employee.index',
                'HR' => 'employee.index',
                'ASSISTANT' => 'persons.index',
                'SPECIALIST' => 'persons.index',
                'ADMIN' => 'employee.index',
                'RECEPTIONIST' => 'persons.index',
                'MED_ADMIN' => 'persons.index',
                'LABORANT' => 'employee.index',
                'MED_COORDINATOR' => 'persons.index',
            ],
        ];

        foreach ($destinations as $type => $roles) {
            foreach ($roles as $role => $route) {
                $cases[$type . ' / ' . $role] = [$type, $role, $route];
            }
        }

        return $cases;
    }

    public function test_matrix_covers_every_allowed_role_for_both_legal_entity_types(): void
    {
        foreach (['PRIMARY_CARE', 'OUTPATIENT'] as $type) {
            $testedRoles = collect(self::singleRoleLogins())->filter(fn (array $case): bool => $case[0] === $type)
                ->pluck(1)->all();

            $this->assertEqualsCanonicalizing(config('ehealth.legal_entity_employee_types.' . $type), $testedRoles);
        }
    }

    protected function createLogin(string $type, string $roleName): array
    {
        Auth::shouldUse('ehealth');
        $typeScopes = config('ehealth.legal_entity_types.' . $type);
        $roleScopes = config('ehealth.roles.' . $roleName);
        $scopeNames = array_values(array_unique(array_merge($typeScopes, $roleScopes)));
        DB::table('permissions')->insertOrIgnore(array_map(
            fn (string $scope): array => ['name' => $scope, 'guard_name' => 'ehealth'],
            $scopeNames
        ));
        $permissions = Permission::whereGuardName('ehealth')->whereIn('name', $scopeNames)->pluck('id', 'name');
        $entityType = LegalEntityType::firstOrCreate(['name' => $type]);
        $entityType->permissions()->sync($permissions->only($typeScopes)->values()->all());
        $legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'legal_entity_type_id' => $entityType->id,
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'employee_sync_status' => 'COMPLETED',
            'division_sync_status' => 'COMPLETED',
            'is_active' => true,
        ]);
        setPermissionsTeamId($legalEntity->id);
        $role = Role::findOrCreate($roleName, 'ehealth');
        $role->legalEntityTypes()->syncWithoutDetaching([$entityType->id]);
        DB::table('role_has_permissions')->where('role_id', $role->id)->delete();
        DB::table('role_has_permissions')->insert(array_map(
            fn (int $permissionId): array => ['role_id' => $role->id, 'permission_id' => $permissionId],
            $permissions->only($roleScopes)->values()->all()
        ));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $party = Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Тест',
            'last_name' => 'Вхід',
            'birth_date' => '1990-01-01',
            'gender' => 'MALE',
        ]);
        $user = User::forceCreate([
            'uuid' => (string) Str::uuid(),
            'email' => 'dashboard-' . Str::uuid() . '@example.com',
            'password' => 'unused',
            'party_id' => $party->id,
            'email_verified_at' => now(),
            'inserted_at' => now(),
        ]);
        $user->assignRole($role);
        $user->syncPermissions(array_intersect($typeScopes, $roleScopes));
        Employee::create([
            'uuid' => (string) Str::uuid(),
            'employee_type' => $roleName,
            'legal_entity_id' => $legalEntity->id,
            'legal_entity_uuid' => $legalEntity->uuid,
            'party_id' => $party->id,
            'user_id' => $user->id,
            'status' => 'APPROVED',
            'is_active' => true,
            'position' => 'P1',
            'start_date' => '2025-01-01',
            'inserted_at' => now(),
        ]);

        return [$legalEntity, $user->fresh()];
    }
}
