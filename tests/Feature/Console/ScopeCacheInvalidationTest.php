<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\Update;
use App\Models\LegalEntity;
use App\Models\LegalEntityType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class ScopeCacheInvalidationTest extends TestCase
{
    use DatabaseTransactions;

    protected const array OLD_SCOPES = ['legal_entity:read', 'employee_request:approve'];

    protected array $entityIds;

    protected User $user;

    public function createApplication(): Application
    {
        $app = parent::createApplication();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null,
            'cache.default' => 'array',
            'permission.cache.store' => 'array',
        ]);

        foreach ([
            '2025_12_10_000012_create_legal_entity_types_table.php',
            '2025_12_10_000014_create_legal_entities_table.php',
            '2025_12_10_000016_create_permission_tables.php',
            '2025_12_10_000018_create_legal_entity_type_roles.php',
            '2025_12_10_000020_create_legal_entity_type_permissions_table.php',
        ] as $migration) {
            (require database_path('migrations/install/' . $migration))->up();
        }

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->uuid();
            $table->string('email');
            $table->string('password');
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $type = LegalEntityType::firstOrCreate(['name' => LegalEntity::TYPE_PRIMARY_CARE]);
        $this->entityIds = LegalEntity::factory()->count(2)
            ->create(['legal_entity_type_id' => $type->id])->modelKeys();
        $this->user = User::factory()->create();
        setPermissionsTeamId($this->entityIds[0]);
        $permissionIds = [];

        foreach (array_keys(config('auth.guards')) as $guard) {
            Auth::shouldUse($guard);
            $permissions = collect(self::OLD_SCOPES)
                ->map(fn (string $scope): Permission => Permission::findOrCreate($scope, $guard));
            $permissionIds = [...$permissionIds, ...$permissions->pluck('id')->all()];
            $role = Role::findOrCreate('OWNER', $guard);
            DB::table('role_has_permissions')->where('role_id', $role->id)->delete();
            DB::table('role_has_permissions')->insert($permissions->map(
                fn (Permission $permission): array => ['role_id' => $role->id, 'permission_id' => $permission->id]
            )->all());
            $this->user->assignRole($role);
        }

        $type->permissions()->sync($permissionIds);

        // Warm both the shared store and its memoized copy, as a login does before scopes change.
        foreach ($this->entityIds as $entityId) {
            cache()->memo()->put("le_type:$entityId", $type->id, 300);
            cache()->memo()->get("le_type:$entityId");
            foreach (array_keys(config('auth.guards')) as $guard) {
                $key = "allowed_permissions:$entityId:$guard";
                cache()->memo()->put($key, collect(self::OLD_SCOPES), 300);
                cache()->memo()->get($key);
            }
        }

        cache()->put('scope-test-unrelated', 'preserved');
    }

    public function test_scope_sync_refreshes_permissions_for_every_entity_and_guard(): void
    {
        $this->syncScopes();

        $this->assertPermissionCachesCleared();
        $this->assertSame('preserved', cache()->get('scope-test-unrelated'));

        setPermissionsTeamId($this->entityIds[0]);
        $scopes = array_values(array_intersect(
            config('ehealth.roles.OWNER'),
            config('ehealth.legal_entity_types.' . LegalEntity::TYPE_PRIMARY_CARE)
        ));

        foreach (array_keys(config('auth.guards')) as $guard) {
            Auth::shouldUse($guard);
            $user = User::without('person')->findOrFail($this->user->id);
            $this->actingAs($user, $guard);
            $user->syncPermissions([...$scopes, 'employee_request:approve']);

            $this->assertEqualsCanonicalizing($scopes, $user->getDirectPermissions()->pluck('name')->all());
            $this->assertTrue($user->getDirectPermissions()->pluck('name')->contains('party_verification:read'));
            $this->assertFalse($user->getDirectPermissions()->pluck('name')->contains('employee_request:approve'));
            $this->assertTrue($user->hasAllowedRole('OWNER'));

            if ($guard === 'ehealth') {
                $this->assertTrue($user->can('viewAny', LegalEntity::class));
            }
        }
    }

    public static function priorityRoleAssignments(): iterable
    {
        foreach ([
            LegalEntity::TYPE_PRIMARY_CARE => 'DOCTOR',
            LegalEntity::TYPE_OUTPATIENT => 'SPECIALIST',
        ] as $type => $medicalRole) {
            foreach ([
                ['OWNER'],
                ['ADMIN'],
                [$medicalRole],
                ['OWNER', $medicalRole],
                ['HR', $medicalRole],
                ['HR', 'ADMIN', 'RECEPTIONIST'],
            ] as $roleNames) {
                yield $type . ': ' . implode(' + ', $roleNames) => [$type, $roleNames];
            }
        }
    }

    #[DataProvider('priorityRoleAssignments')]
    public function test_scope_sync_updates_scopes_for_priority_roles_and_combinations(string $typeName, array $roleNames): void
    {
        $type = LegalEntityType::firstOrCreate(['name' => $typeName]);
        $entityId = $this->entityIds[0];
        DB::table('legal_entities')->where('id', $entityId)->update(['legal_entity_type_id' => $type->id]);

        $this->syncScopes(null);

        $this->assertEmpty(array_diff($roleNames, config('ehealth.legal_entity_employee_types.' . $typeName)));
        $roleScopes = array_unique(array_merge(...array_map(
            fn (string $roleName): array => config('ehealth.roles.' . $roleName),
            $roleNames
        )));
        $expectedScopes = array_values(array_intersect($roleScopes, config('ehealth.legal_entity_types.' . $typeName)));
        $this->assertNotEmpty($expectedScopes);
        setPermissionsTeamId($entityId);
        $guards = array_diff(array_keys(config('auth.guards')), config('auth.scope_sync_excluded_guards', []));

        foreach ($guards as $guard) {
            $user = User::factory()->create();
            $this->actingAs($user, $guard);
            $roles = Role::whereGuardName($guard)->whereIn('name', $roleNames)->get();
            $this->assertCount(count($roleNames), $roles);
            $user->assignRole($roles);

            $this->assertEqualsCanonicalizing($roleNames, $user->getRoleNames()->all());
            $this->assertEmpty($user->getDirectPermissions());
            $this->assertEqualsCanonicalizing($expectedScopes, explode(' ', $user->getScopes()));
        }
    }

    public function test_scope_sync_waits_for_the_enclosing_transaction_to_commit(): void
    {
        DB::beginTransaction();
        $this->syncScopes();

        $this->assertOldPermissionCachesRemain();
        DB::commit();

        $this->assertPermissionCachesCleared();
    }

    public function test_scope_sync_refreshes_scope_config_without_overwriting_other_ehealth_config(): void
    {
        config([
            'ehealth.api.domain' => 'cached.example.test',
            'ehealth.encounter' => ['cached_setting' => true],
            'ehealth.legal_entity_employee_types' => [],
            'ehealth.legal_entity_localized_names' => [],
            'ehealth.legal_entity_types' => [],
            'ehealth.roles' => [],
        ]);

        $this->syncScopes();

        $this->assertSame('cached.example.test', config('ehealth.api.domain'));
        $this->assertSame(['cached_setting' => true], config('ehealth.encounter'));
        $this->assertContains('OWNER', config('ehealth.legal_entity_employee_types.PRIMARY_CARE'));
        $this->assertSame('legal-entity.types.primary_care', config('ehealth.legal_entity_localized_names.PRIMARY_CARE'));
        $this->assertSame(require config_path('scopes/legal_entity_types.php'), config('ehealth.legal_entity_types'));
        $this->assertSame(require config_path('scopes/roles.php'), config('ehealth.roles'));
    }

    public function test_permission_catalog_preserves_scopes_when_type_and_role_names_match(): void
    {
        config([
            'ehealth.legal_entity_types' => ['SAME_NAME' => ['only_type:read', 'shared:read']],
            'ehealth.roles' => ['SAME_NAME' => ['only_role:read', 'shared:read']],
        ]);
        $existingPermission = Permission::findOrCreate('only_type:read', 'web');
        $command = new Update();
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput()));

        new ReflectionMethod($command, 'syncPermissions')->invoke($command);

        $this->assertDatabaseHas('permissions', [
            'id' => $existingPermission->id,
            'name' => 'only_type:read',
            'guard_name' => 'web',
        ]);

        $guards = array_diff(array_keys(config('auth.guards')), config('auth.scope_sync_excluded_guards', []));

        foreach ($guards as $guard) {
            $this->assertEqualsCanonicalizing(
                ['only_type:read', 'only_role:read', 'shared:read'],
                Permission::whereGuardName($guard)->pluck('name')->all()
            );
        }
    }

    public function test_scope_sync_reconciles_roles_permissions_and_links_for_every_guard(): void
    {
        $guards = array_keys(config('auth.guards'));
        $missingGuard = 'api';
        $this->assertContains($missingGuard, $guards);
        $type = LegalEntityType::whereName(LegalEntity::TYPE_PRIMARY_CARE)->firstOrFail();

        foreach ($guards as $guard) {
            $obsolete = Permission::findOrCreate('obsolete_scope:read', $guard);
            $owner = Role::findByName('OWNER', $guard);
            DB::table('role_has_permissions')->insert(['role_id' => $owner->id, 'permission_id' => $obsolete->id]);
            $type->permissions()->attach($obsolete->id);
            Role::findOrCreate('OBSOLETE_ROLE', $guard);
        }

        $retiredRoleId = DB::table('roles')->insertGetId(['name' => 'OWNER', 'guard_name' => 'retired']);
        $retiredPermissionId = DB::table('permissions')->insertGetId(['name' => 'legal_entity:read', 'guard_name' => 'retired']);
        $type->roles()->attach($retiredRoleId);
        $type->permissions()->attach($retiredPermissionId);

        DB::table('roles')->where('guard_name', $missingGuard)->delete();
        DB::table('permissions')->where('guard_name', $missingGuard)->delete();
        $webOwner = Role::findByName('OWNER', 'web');
        $webPermission = Permission::findByName('legal_entity:read', 'web');
        $ehealthPermission = Permission::findByName('legal_entity:read', 'ehealth');
        DB::table('role_has_permissions')->where('role_id', $webOwner->id)
            ->where('permission_id', $webPermission->id)->delete();
        DB::table('role_has_permissions')->insert(['role_id' => $webOwner->id, 'permission_id' => $ehealthPermission->id]);
        $type->permissions()->detach($webPermission->id);

        // Simulate a cache created before the api guard was added to auth.php.
        config(['auth.guards' => array_diff_key(config('auth.guards'), [$missingGuard => true])]);
        $output = $this->syncScopes();
        $this->assertSame($guards, array_keys(config('auth.guards')));
        $this->assertMatchesRegularExpression('/Added roles for guard api: [^\r\n]*\bOWNER\b/', $output);
        $this->assertStringContainsString('Added permissions for guard api:', $output);
        $this->assertStringContainsString('Removed roles for guard retired: OWNER', $output);
        $this->assertStringContainsString('Removed permissions for guard retired: legal_entity:read', $output);
        $this->assertFalse(DB::table('roles')->where('guard_name', 'retired')->exists());
        $this->assertFalse(DB::table('permissions')->where('guard_name', 'retired')->exists());

        foreach (array_diff($guards, [$missingGuard]) as $guard) {
            $this->assertStringContainsString('Removed roles for guard ' . $guard . ': OBSOLETE_ROLE', $output);
            $this->assertStringContainsString('Removed permissions for guard ' . $guard . ': obsolete_scope:read', $output);
        }

        $expectedPermissions = array_values(array_unique(array_merge(
            ...array_values(config('ehealth.legal_entity_types')),
            ...array_values(config('ehealth.roles'))
        )));

        foreach ($guards as $guard) {
            $this->assertEqualsCanonicalizing(array_keys(config('ehealth.roles')),
                Role::whereGuardName($guard)->pluck('name')->all());
            $this->assertEqualsCanonicalizing($expectedPermissions,
                Permission::whereGuardName($guard)->pluck('name')->all());
        }

        foreach (Role::all() as $role) {
            $permissions = DB::table('role_has_permissions as pivot')
                ->join('permissions', 'permissions.id', '=', 'pivot.permission_id')
                ->where('pivot.role_id', $role->id)->get(['permissions.name', 'permissions.guard_name']);
            $this->assertEqualsCanonicalizing(config('ehealth.roles.' . $role->name), $permissions->pluck('name')->all());
            $this->assertSame([$role->guard_name], $permissions->pluck('guard_name')->unique()->values()->all());
        }

        foreach (LegalEntityType::all() as $legalEntityType) {
            foreach ($guards as $guard) {
                $this->assertEqualsCanonicalizing(config('ehealth.legal_entity_types.' . $legalEntityType->name),
                    $legalEntityType->permissions()->where('guard_name', $guard)->pluck('name')->all());
                $this->assertEqualsCanonicalizing(config('ehealth.legal_entity_employee_types.' . $legalEntityType->name),
                    $legalEntityType->roles()->where('guard_name', $guard)->pluck('name')->all());
            }
        }

        $tables = ['roles', 'permissions', 'role_has_permissions', 'legal_entity_type_roles', 'legal_entity_type_permissions'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->get()->map(fn ($row): string => json_encode($row))->sort()->values()->all();
        }

        $repeatOutput = $this->syncScopes();
        $this->assertDoesNotMatchRegularExpression('/(?:Added|Removed) (?:roles|permissions) for guard /', $repeatOutput);

        foreach ($tables as $table) {
            $this->assertSame($before[$table],
                DB::table($table)->get()->map(fn ($row): string => json_encode($row))->sort()->values()->all());
        }
    }

    public function test_rolling_back_scope_sync_preserves_the_previous_caches(): void
    {
        DB::beginTransaction();
        $this->syncScopes();
        DB::rollBack();

        $this->assertOldPermissionCachesRemain();
        $this->assertSame('preserved', cache()->get('scope-test-unrelated'));
    }

    public function test_excluded_api_records_are_removed_and_recreated_when_enabled(): void
    {
        $type = LegalEntityType::whereName(LegalEntity::TYPE_PRIMARY_CARE)->firstOrFail();
        $pausedRole = Role::findOrCreate('PAUSED_ROLE', 'api');
        $pausedPermission = Permission::findOrCreate('paused_scope:read', 'api');
        DB::table('role_has_permissions')->insert(['role_id' => $pausedRole->id, 'permission_id' => $pausedPermission->id]);
        $type->roles()->attach($pausedRole->id);
        $type->permissions()->attach($pausedPermission->id);
        DB::table('model_has_permissions')->insert([
            'permission_id' => $pausedPermission->id,
            'model_type' => $this->user->getMorphClass(),
            'model_id' => $this->user->id,
            'legal_entity_id' => $this->entityIds[0],
        ]);

        $roleIds = DB::table('roles')->where('guard_name', 'api')->pluck('id')->all();
        $permissionIds = DB::table('permissions')->where('guard_name', 'api')->pluck('id')->all();
        $snapshot = static function () use ($roleIds, $permissionIds): array {
            $queries = [
                'roles' => DB::table('roles')->whereIn('id', $roleIds),
                'permissions' => DB::table('permissions')->whereIn('id', $permissionIds),
                'role_permissions' => DB::table('role_has_permissions')->whereIn('role_id', $roleIds),
                'type_roles' => DB::table('legal_entity_type_roles')->whereIn('role_id', $roleIds),
                'type_permissions' => DB::table('legal_entity_type_permissions')->whereIn('permission_id', $permissionIds),
                'model_roles' => DB::table('model_has_roles')->whereIn('role_id', $roleIds),
                'model_permissions' => DB::table('model_has_permissions')->whereIn('permission_id', $permissionIds),
            ];
            $rows = [];

            foreach ($queries as $name => $query) {
                $rows[$name] = $query->get()->map(fn ($row): string => json_encode($row))->sort()->values()->all();
            }

            return $rows;
        };
        $this->assertNotEmpty($snapshot()['roles']);
        $this->assertNotEmpty($snapshot()['permissions']);

        // The current auth.php exclusion must take precedence over an older cached value.
        config(['auth.scope_sync_excluded_guards' => []]);
        $output = $this->syncScopes(null);

        $this->assertStringContainsString('Guards excluded from scope synchronization: api', $output);
        $this->assertStringContainsString('Removed roles for guard api:', $output);
        $this->assertStringContainsString('Removed permissions for guard api:', $output);

        foreach ($snapshot() as $table => $rows) {
            $this->assertSame([], $rows, $table . ' must contain no links to the excluded guard records.');
        }

        $this->assertSame(0, Role::whereGuardName('api')->count());
        $this->assertSame(0, Permission::whereGuardName('api')->count());
        $this->assertSame(['api'], config('auth.scope_sync_excluded_guards'));

        foreach ($this->entityIds as $entityId) {
            $this->assertNull(cache()->get("allowed_permissions:$entityId:api"));
            $this->assertNull(cache()->memo()->get("allowed_permissions:$entityId:api"));
        }

        foreach (['web', 'ehealth'] as $guard) {
            $this->assertGreaterThan(count(self::OLD_SCOPES), Permission::whereGuardName($guard)->count());
        }

        $this->syncScopes(null);
        $this->assertSame(0, Role::whereGuardName('api')->count());
        $this->assertSame(0, Permission::whereGuardName('api')->count());

        $output = $this->syncScopes([]);

        $this->assertSame([], config('auth.scope_sync_excluded_guards'));
        $this->assertStringContainsString('Added roles for guard api:', $output);
        $this->assertStringContainsString('Added permissions for guard api:', $output);
        $this->assertEqualsCanonicalizing(array_keys(config('ehealth.roles')), Role::whereGuardName('api')->pluck('name')->all());

        $expectedPermissions = array_values(array_unique(array_merge(
            ...array_values(config('ehealth.legal_entity_types')),
            ...array_values(config('ehealth.roles'))
        )));
        $this->assertEqualsCanonicalizing($expectedPermissions, Permission::whereGuardName('api')->pluck('name')->all());

        foreach (LegalEntityType::all() as $legalEntityType) {
            $this->assertEqualsCanonicalizing(config('ehealth.legal_entity_types.' . $legalEntityType->name),
                $legalEntityType->permissions()->where('guard_name', 'api')->pluck('name')->all());
            $this->assertEqualsCanonicalizing(config('ehealth.legal_entity_employee_types.' . $legalEntityType->name),
                $legalEntityType->roles()->where('guard_name', 'api')->pluck('name')->all());
        }
    }

    public function test_update_publishes_scope_files_and_rebuilds_config_cache_only_after_commit(): void
    {
        $configPath = $this->app->configPath();
        $testConfigPath = sys_get_temp_dir() . '/mis-scopes-' . bin2hex(random_bytes(8));
        $newScope = 'scope_sync_test:read';
        $scopeFiles = [
            'scopes/legal_entity_types' => LegalEntity::TYPE_PRIMARY_CARE,
            'scopes/roles' => 'OWNER',
        ];

        try {
            File::ensureDirectoryExists($testConfigPath . '/scopes');
            File::copy($configPath . '/auth.php', $testConfigPath . '/auth.php');
            File::copy($configPath . '/ehealth.php', $testConfigPath . '/ehealth.php');
            $oldFiles = [];

            foreach ($scopeFiles as $path => $name) {
                $oldFiles[$path] = File::get($configPath . '/' . $path . '.php');
                File::put($testConfigPath . '/' . $path . '.php', $oldFiles[$path]);
                $scopes = require $configPath . '/' . $path . '.php';
                $scopes[$name][] = $newScope;

                $data = [];
                foreach ($scopes as $key => $scopeList) {
                    $data[] = ['name' => $key, 'scope' => implode(' ', $scopeList)];
                }

                Storage::shouldReceive('exists')->with($path . '.json')->andReturnTrue();
                Storage::shouldReceive('json')->with($path . '.json', JSON_THROW_ON_ERROR)->andReturn(['data' => $data]);
            }

            $this->app->useConfigPath($testConfigPath);
            $command = Mockery::mock(Update::class . '[call]');
            $command->setLaravel($this->app);
            $command->shouldReceive('call')->with('config:cache')->once()->andReturnUsing(function () use ($scopeFiles, $newScope): int {
                foreach ($scopeFiles as $path => $name) {
                    $scopes = require config_path($path . '.php');
                    $this->assertContains($newScope, $scopes[$name]);
                    $this->assertDoesNotMatchRegularExpression('/[ \t]+$/m', File::get(config_path($path . '.php')));
                }

                return Update::SUCCESS;
            });
            setPermissionsTeamId(null);

            DB::unprepared("CREATE TEMP TRIGGER fail_scope_sync BEFORE INSERT ON role_has_permissions
                BEGIN SELECT RAISE(ABORT, 'scope sync failed'); END");

            try {
                $command->run(new ArrayInput(['--scopes' => true]), new BufferedOutput());
                $this->fail('The database should reject the scope sync.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('scope sync failed', $exception->getMessage());
            }

            $this->assertFalse(Permission::query()->where('name', $newScope)->exists());
            $this->assertOldPermissionCachesRemain();
            DB::unprepared('DROP TRIGGER fail_scope_sync');

            DB::beginTransaction();
            $command->run(new ArrayInput(['--scopes' => true]), new BufferedOutput());
            $this->assertTrue(Permission::query()->where('name', $newScope)->exists());

            foreach ($oldFiles as $path => $contents) {
                $this->assertSame($contents, File::get(config_path($path . '.php')));
            }

            DB::commit();
            $this->assertPermissionCachesCleared();
        } finally {
            $this->app->useConfigPath($configPath);
            File::deleteDirectory($testConfigPath);
        }
    }

    public static function failingScopeConfigFiles(): array
    {
        return [
            'legal entity types' => ['legal_entity_types.php'],
            'roles' => ['roles.php'],
        ];
    }

    #[DataProvider('failingScopeConfigFiles')]
    public function test_file_write_failure_clears_permission_caches_and_preserves_the_exception(string $filename): void
    {
        $newScope = 'scope_file_failure_test:read';
        $typeScopes = require config_path('scopes/legal_entity_types.php');
        $roleScopes = require config_path('scopes/roles.php');
        $typeScopes[LegalEntity::TYPE_PRIMARY_CARE] = array_values(array_diff(
            $typeScopes[LegalEntity::TYPE_PRIMARY_CARE], ['employee_request:approve']
        ));
        $typeScopes[LegalEntity::TYPE_PRIMARY_CARE][] = $newScope;
        $roleScopes['OWNER'][] = $newScope;
        $error = new RuntimeException('Scope config write failed.');

        $command = Mockery::mock(Update::class . '[call,readLegalEntityTypeScopes,readRoleScopes]');
        $command->shouldAllowMockingProtectedMethods();
        $command->setLaravel($this->app);
        $command->shouldReceive('readLegalEntityTypeScopes')->once()->andReturn($typeScopes);
        $command->shouldReceive('readRoleScopes')->once()->andReturn($roleScopes);
        $command->shouldNotReceive('call')->with('config:cache');

        if ($filename === 'roles.php') {
            File::shouldReceive('replace')->once()
                ->with(config_path('scopes/legal_entity_types.php'), Mockery::type('string'));
        } else {
            File::shouldReceive('replace')->never()
                ->with(config_path('scopes/roles.php'), Mockery::type('string'));
        }

        File::shouldReceive('replace')->once()
            ->with(config_path('scopes/' . $filename), Mockery::type('string'))->andThrow($error);

        setPermissionsTeamId(null);
        $output = new BufferedOutput();
        $transactionLevel = DB::transactionLevel();

        try {
            $command->run(new ArrayInput(['--scopes' => true]), $output);
            $this->fail('The scope file write exception must stop the command.');
        } catch (RuntimeException $exception) {
            $this->assertSame($error, $exception);
        }

        $this->assertSame($transactionLevel, DB::transactionLevel());
        $this->assertDatabaseHas('permissions', ['name' => $newScope, 'guard_name' => 'web']);
        $type = LegalEntityType::whereName(LegalEntity::TYPE_PRIMARY_CARE)->firstOrFail();
        $this->assertFalse($type->permissions()->where('permissions.name', 'employee_request:approve')->exists());
        $this->assertPermissionCachesCleared();
        $this->assertSame('preserved', cache()->get('scope-test-unrelated'));
        $this->assertStringNotContainsString('Config cache updated', $output->fetch());
    }

    protected function syncScopes(?array $excludedGuards = []): string
    {
        // Keep the real synchronization and transaction; avoid rewriting source files during tests.
        Storage::shouldReceive('exists')->with('scopes/legal_entity_types.json')->once()->andReturnFalse();
        Storage::shouldReceive('exists')->with('scopes/roles.json')->once()->andReturnFalse();
        $configPath = $this->app->configPath();
        $testConfigPath = sys_get_temp_dir() . '/mis-scope-guards-' . bin2hex(random_bytes(8));

        try {
            File::copyDirectory($configPath, $testConfigPath);
            $auth = require $configPath . '/auth.php';

            if ($excludedGuards !== null) {
                $auth['scope_sync_excluded_guards'] = $excludedGuards;
                File::put($testConfigPath . '/auth.php', '<?php return ' . var_export($auth, true) . ';');
            }

            $this->app->useConfigPath($testConfigPath);
            $command = new Update();
            $output = new BufferedOutput();
            $command->setOutput(new OutputStyle(new ArrayInput([]), $output));
            setPermissionsTeamId(null);

            new ReflectionMethod($command, 'syncScopes')->invoke($command);

            return $output->fetch();
        } finally {
            $this->app->useConfigPath($configPath);
            File::deleteDirectory($testConfigPath);
        }
    }

    protected function assertPermissionCachesCleared(): void
    {
        foreach ($this->entityIds as $entityId) {
            $this->assertNull(cache()->get("le_type:$entityId"));
            $this->assertNull(cache()->memo()->get("le_type:$entityId"));
            foreach (array_keys(config('auth.guards')) as $guard) {
                $key = "allowed_permissions:$entityId:$guard";
                $this->assertNull(cache()->get($key));
                $this->assertNull(cache()->memo()->get($key));
            }
        }
    }

    protected function assertOldPermissionCachesRemain(): void
    {
        foreach ($this->entityIds as $entityId) {
            $this->assertNotNull(cache()->get("le_type:$entityId"));
            $this->assertNotNull(cache()->memo()->get("le_type:$entityId"));
            foreach (array_keys(config('auth.guards')) as $guard) {
                $key = "allowed_permissions:$entityId:$guard";
                $this->assertSame(self::OLD_SCOPES, cache()->get($key)->all());
                $this->assertSame(self::OLD_SCOPES, cache()->memo()->get($key)->all());
            }
        }
    }
}
