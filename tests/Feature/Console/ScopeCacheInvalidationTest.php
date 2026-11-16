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
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use ReflectionMethod;
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

    public function test_scope_sync_waits_for_the_enclosing_transaction_to_commit(): void
    {
        DB::beginTransaction();
        $this->syncScopes();

        $this->assertOldPermissionCachesRemain();
        DB::commit();

        $this->assertPermissionCachesCleared();
    }

    public function test_rolling_back_scope_sync_preserves_the_previous_caches(): void
    {
        DB::beginTransaction();
        $this->syncScopes();
        DB::rollBack();

        $this->assertOldPermissionCachesRemain();
        $this->assertSame('preserved', cache()->get('scope-test-unrelated'));
    }

    protected function syncScopes(): void
    {
        // Keep the real synchronization and transaction; avoid rewriting source files during tests.
        $command = Mockery::mock(Update::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $command->shouldReceive('updateLegalEntityTypeScopesConfig')->once();
        $command->shouldReceive('updateRoleScopesConfig')->once();
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput()));
        setPermissionsTeamId(null);

        new ReflectionMethod($command, 'syncScopes')->invoke($command);
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
