<?php

declare(strict_types=1);

namespace Tests\Feature\Division;

use App\Classes\eHealth\Api\Division as DivisionApi;
use App\Classes\eHealth\Api\Responses\Collections\DivisionCreate as DivisionResponse;
use App\Classes\eHealth\EHealthResponse;
use App\Auth\EHealth\Services\TokenStorage;
use App\Classes\eHealth\Middleware\CheckSessionToken;
use App\Dto\Division\Model as DivisionData;
use App\Enums\User\Role;
use App\Livewire\Division\DivisionEdit;
use App\Livewire\Division\Forms\DivisionForm;
use App\Models\Division;
use App\Models\LegalEntity;
use App\Models\User;
use App\Repositories\DivisionRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class DivisionCreationRoleTest extends TestCase
{
    use DatabaseTransactions;

    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $app['config']->set([
            'database.default' => 'division_roles',
            'database.connections.division_roles' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'cache.default' => 'array',
            'permission.cache.store' => 'array',
        ]);

        // Exercise real role and permission queries without touching an existing database.
        foreach (['roles', 'permissions'] as $table) {
            Schema::create($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->id();
                $blueprint->string('name');
                $blueprint->string('guard_name');
                if ($table === 'roles') {
                    $blueprint->unsignedBigInteger('legal_entity_id')->nullable();
                }
            });
        }

        Schema::create('role_has_permissions', function (Blueprint $blueprint): void {
            $blueprint->unsignedBigInteger('role_id');
            $blueprint->unsignedBigInteger('permission_id');
        });
        foreach (['model_has_roles' => 'role_id', 'model_has_permissions' => 'permission_id'] as $table => $key) {
            Schema::create($table, function (Blueprint $blueprint) use ($key): void {
                $blueprint->unsignedBigInteger($key);
                $blueprint->unsignedBigInteger('model_id');
                $blueprint->string('model_type');
                $blueprint->unsignedBigInteger('legal_entity_id');
            });
        }

        Schema::create('legal_entity_types', function (Blueprint $blueprint): void {
            $blueprint->id();
        });
        Schema::create('legal_entity_type_permissions', function (Blueprint $blueprint): void {
            $blueprint->unsignedBigInteger('permission_id');
            $blueprint->unsignedBigInteger('legal_entity_type_id');
        });

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        setPermissionsTeamId(1);
        $entity = new LegalEntity();
        $entity->id = 1;
        $this->app->instance('legalEntity', $entity);
        cache()->put('le_type:1', 1);
        cache()->put('le_type:2', 1);
        $this->withSession(['single_role_login' => Role::OWNER->value]);

        DB::table('roles')->insert([
            ['id' => 1, 'name' => 'OWNER', 'guard_name' => 'ehealth'],
            ['id' => 2, 'name' => 'ADMIN', 'guard_name' => 'ehealth'],
            ['id' => 3, 'name' => 'OWNER', 'guard_name' => 'web'],
        ]);
        DB::table('permissions')->insert([
            ['id' => 1, 'name' => 'division:write', 'guard_name' => 'ehealth'],
            ['id' => 2, 'name' => 'employee:read', 'guard_name' => 'ehealth'],
        ]);
        DB::table('role_has_permissions')->insert([
            ['role_id' => 1, 'permission_id' => 1],
            ['role_id' => 1, 'permission_id' => 2],
            ['role_id' => 2, 'permission_id' => 1],
        ]);
        DB::table('legal_entity_types')->insert(['id' => 1]);
        DB::table('legal_entity_type_permissions')->insert([
            ['permission_id' => 1, 'legal_entity_type_id' => 1],
            ['permission_id' => 2, 'legal_entity_type_id' => 1],
        ]);
    }

    private function signIn(int $roleId = 1, int $roleEntityId = 1, bool $write = true): User
    {
        $user = new User();
        $user->id = 101;
        DB::table('model_has_roles')->insert([
            'role_id' => $roleId, 'model_id' => $user->id,
            'model_type' => User::class, 'legal_entity_id' => $roleEntityId,
        ]);
        if ($write) {
            DB::table('model_has_permissions')->insert([
                'permission_id' => 1, 'model_id' => $user->id,
                'model_type' => User::class, 'legal_entity_id' => 1,
            ]);
        }
        $this->actingAs($user, 'ehealth');

        return $user;
    }

    public static function creationAssignments(): array
    {
        return [
            'owner without unrelated permission' => [1, 1, true, true],
            'owner without write scope' => [1, 1, false, false],
            'owner of another legal entity' => [1, 2, true, false],
            'admin with write scope' => [2, 1, true, false],
            'owner from another guard' => [3, 1, true, false],
        ];
    }

    #[DataProvider('creationAssignments')]
    public function test_creation_uses_assigned_role_and_write_scope(int $roleId, int $roleEntityId, bool $write, bool $allowed): void
    {
        $user = $this->signIn($roleId, $roleEntityId, $write);

        $this->assertSame($write, $user->can('division:write'));
        $this->assertFalse($user->hasAllowedRole([Role::OWNER]));
        $response = Gate::forUser($user)->inspect('create', Division::class);

        $this->assertSame($allowed, $response->allowed());
        if (!$allowed) {
            $this->assertSame(404, $response->status());
        }
    }

    public static function loginRoles(): array
    {
        return [
            'selected owner with partial scopes' => ['OWNER', false, true],
            'selected admin with partial scopes' => ['ADMIN', false, false],
            'combined login with partial owner scopes' => [null, false, false],
            'combined login with full owner scopes' => [null, true, true],
            'selected owner with full scopes' => ['OWNER', true, true],
            'selected admin cannot use owner scopes' => ['ADMIN', true, false],
        ];
    }

    #[DataProvider('loginRoles')]
    public function test_creation_respects_the_login_role_for_a_user_assigned_owner_and_admin(?string $loginRole, bool $fullOwnerScopes, bool $allowed): void
    {
        $user = $this->signIn();
        DB::table('model_has_roles')->insert([
            'role_id' => 2, 'model_id' => $user->id,
            'model_type' => User::class, 'legal_entity_id' => 1,
        ]);
        if ($fullOwnerScopes) {
            DB::table('model_has_permissions')->insert([
                'permission_id' => 2, 'model_id' => $user->id,
                'model_type' => User::class, 'legal_entity_id' => 1,
            ]);
        }
        $this->withSession(['single_role_login' => $loginRole]);

        $this->assertTrue($user->hasRole(Role::OWNER, 'ehealth'));
        $this->assertTrue($user->hasRole(Role::ADMIN, 'ehealth'));
        $this->assertTrue($user->can('division:write'));
        $this->assertTrue($user->hasAllowedRole([Role::ADMIN]));
        $this->assertSame($fullOwnerScopes, $user->hasAllowedRole([Role::OWNER]));
        $this->assertSame($allowed, Gate::forUser($user)->allows('create', Division::class));
    }

    public static function draftLoginRoles(): array
    {
        return [
            'owner can register a draft' => ['OWNER', true],
            'admin cannot register a draft' => ['ADMIN', false],
        ];
    }

    public static function legacyRoleSessions(): array
    {
        return [
            'legacy admin' => ['ADMIN', false, null, 'ADMIN'],
            'new admin overrides legacy owner' => ['OWNER', true, 'ADMIN', 'ADMIN'],
            'new owner overrides legacy admin' => ['ADMIN', true, 'OWNER', 'OWNER'],
            'combined login stays combined' => ['ADMIN', true, null, null],
        ];
    }

    #[DataProvider('legacyRoleSessions')]
    public function test_existing_sessions_keep_selected_role_when_key_is_renamed(
        string $legacyRole,
        bool $hasNewKey,
        ?string $newRole,
        ?string $expectedRole
    ): void {
        $user = $this->signIn();
        session()->forget('single_role_login');
        session()->put('first_login_role', $legacyRole);
        if ($hasNewKey) {
            session()->put('single_role_login', $newRole);
        }
        $request = Request::create('/login');
        $route = new Route(['GET'], 'login', static fn () => null)->name('login');
        $request->setRouteResolver(static fn (): Route => $route);

        new CheckSessionToken(app(TokenStorage::class))->handle($request, function () use ($user, $expectedRole) {
            $this->assertTrue(session()->exists('single_role_login'));
            $this->assertSame($expectedRole, session('single_role_login'));
            $this->assertFalse(session()->exists('first_login_role'));
            $this->assertSame($expectedRole === 'OWNER', Gate::forUser($user)->allows('create', Division::class));

            return response('ok');
        });
    }

    #[DataProvider('draftLoginRoles')]
    public function test_draft_registration_respects_the_selected_role(string $loginRole, bool $allowed): void
    {
        $user = $this->signIn();
        DB::table('model_has_roles')->insert([
            'role_id' => 2, 'model_id' => $user->id,
            'model_type' => User::class, 'legal_entity_id' => 1,
        ]);
        $this->withSession(['single_role_login' => $loginRole]);
        $this->assertFalse($user->hasAllowedRole([Role::OWNER]));
        $this->assertTrue($user->hasRole(Role::OWNER, 'ehealth'));
        $division = new Division();
        $response = $this->mock(EHealthResponse::class);
        $response->shouldReceive('validate')->times($allowed ? 1 : 0)->andReturn(new DivisionResponse([
            'uuid' => 'division-uuid', 'name' => 'New division', 'type' => 'CLINIC',
            'email' => 'division@example.com', 'status' => 'ACTIVE', 'addresses' => [], 'phones' => [],
        ]));
        $this->mock(DivisionApi::class)->shouldReceive('update')->times($allowed ? 1 : 0)
            ->with(null, Mockery::type('array'))->andReturn($response);
        $this->mock(DivisionRepository::class)->shouldReceive('saveMappedDivision')->times($allowed ? 1 : 0)
            ->with(Mockery::type(DivisionData::class), $division, legalEntity())->andReturn($division);

        $component = new class extends DivisionEdit
        {
            public function redirect($url, $navigate = false): void
            {
            }
        };
        $component->divisionForm = new DivisionForm($component, 'divisionForm');
        $component->divisionForm->division = [
            'name' => 'New division', 'type' => 'CLINIC', 'email' => null, 'externalId' => null,
            'addresses' => [], 'phones' => [], 'location' => null, 'workingHours' => [],
        ];
        (new ReflectionMethod(DivisionEdit::class, 'divisionUpdate'))->invoke($component, $division);

        if (!$allowed) {
            $this->assertSame(__('divisions.policy.deny.create'), session('error'));
            $this->assertNull(session('success'));

            return;
        }

        $this->assertNull(session('error'));
        $this->assertSame(__('forms.success_response'), session('success'));
    }
}
