<?php

declare(strict_types=1);

namespace App\Console\Commands;

use RuntimeException;
use JsonException;
use App\Core\Arr;
use App\Models\Role;
use App\Models\Permission;
use Illuminate\Support\Carbon;
use App\Models\LegalEntityType;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Database\Migrations\Migrator;

abstract class MigrationsCommand extends Command
{
    // Source paths for scopes and configuration files
    protected const string LE_TYPES_SCOPE_PATH = 'scopes/legal_entity_types.json';
    protected const string LE_ROLES_SCOPE_PATH = 'scopes/roles.json';

    // Configuration paths for scopes and settings
    protected const string CONFIG_TYPES_SCOPE_PATH = 'scopes/legal_entity_types.php';
    protected const string CONFIG_ROLES_SCOPE_PATH = 'scopes/roles.php';

    protected const int CHUNK_SIZE = 1000;

    // Flag to display the command help if no additional arguments were provided
    protected bool $showHelpWhenEmpty = false;

    // Flag indicating whether the command is performing a rollback operation.
    protected bool $isRollback = false;

    // Paths to migration files
    protected string $migrations_path;

    // Path to migration seeders
    protected string $migrations_seeders_path;

    // Array of migration files to be processed
    protected array $migrationFiles = [];

    // Options passed to the command
    protected array $options;

    // Arguments passed to the command
    protected array $arguments;

    // Set the migrations path and seeders path
    abstract protected function setMigrationsPath(): void;

    // Execute actions before running database migrations
    abstract protected function beforeMigrations(): void;

    // Execute actions after running database migrations
    abstract protected function afterMigrations(): void;

    /**
     * Execute the console command.
     */
    public function handle(Migrator $migrator): void
    {
        if ($this->showHelpIfNeeded()) {
            return;
        }

        if ($this->hasOption('scopes') && $this->option('scopes')) {
            $this->syncScopes();

            DB::afterCommit(function (): void {
                if ($this->call('config:cache') !== self::SUCCESS) {
                    throw new RuntimeException(__('Could not rebuild the configuration cache.'));
                }

                $this->info(__('Config cache updated'));
            });

            return;
        }

        $this->cacheClear();

        $this->beforeMigrations();

        if (!$this->setMigrationsFiles($migrator)) {
            return;
        }

        // Rollback mode: if --rollback provided, perform rollback and exit
        if ($this->isRollback) {
            $this->doRollback($migrator);

            return;
        }

        $this->doMigrations($migrator);

        $this->afterMigrations();
    }

    protected function cacheClear(): void
    {
        $isCacheCleared = (bool) ($this->option('clear') ?? false);

        if (!$isCacheCleared) {
            return;
        }

        $this->info('Clearing cache...');

        $this->call('cache:clear');
        $this->call('config:clear');
        $this->call('config:cache');
        $this->call('route:clear');
        $this->call('view:clear');

        $this->info('Cache cleared.');
    }

    // Get the migration files to be processed
    protected function getMigrationFiles(): array
    {
        return $this->migrationFiles;
    }

    // Set the migration files to be processed (only those that have not been run yet)
    protected function setMigrationsFiles(Migrator $migrator): bool
    {
        $this->setMigrationsPath();

        // Get migration files from the specified path
        $this->migrationFiles = $migrator->getMigrationFiles($this->migrations_path);

        if (empty($this->migrationFiles)) {
            $this->warn("No migration files found in the path: {$this->migrations_path}");

            return false;
        }

        return true;
    }

    protected function doRollback(Migrator $migrator): void
    {
        $this->setMigrationsPath();

        $step = $this->option('step');

        if (!empty($step)) {
            $this->info("Rolling back {$step} batch(es) of migrations...");

            // Laravel's rollback supports --step; call the artisan command to leverage options
            $this->call('migrate:rollback', [
                '--path' => $this->migrations_path,
                '--step' => (int) $step,
            ]);
        } else {
            $this->info('Rolling back last batch of migrations...');

            // Use Migrator directly for the given path (last batch)
            $migrator->rollback($this->migrations_path);
        }
    }

    // Perform the migrations (depends on the $migrationFiles set previously)
    protected function doMigrations(Migrator $migrator): void
    {
        if ($migrator->hasRunAnyMigrations()) {
            // Filter out already run migrations
            $migrationsAlreadyCompleted = $migrator?->getRepository()?->getRan() ?? [];

            $migrationsToRun = \array_diff(\array_keys($this->migrationFiles), $migrationsAlreadyCompleted);

            if (empty($migrationsToRun)) {
                $this->warn('All migrations have already been run.');
                $this->newLine();

                return;
            };
        } else {
            $migrationsToRun = \array_keys($this->migrationFiles);
        }

        $pendingFiles = [];

        /**
         * $migrationFiles - is a key-value array where key is the migration class name and value is the full path to the migration file
         *
         *  Here we prepare an array of pending migration files to be run.
         *  Pass to migrator only those files that have not been run yet.
         */
        foreach ($migrationsToRun as $key) {
            $pendingFiles[] = $this->migrationFiles[$key];
        }

        natsort($pendingFiles);

        $this->info("Running all the new migrations:");
        $this->newLine();

        // Apply all the migrations at one time
        $migrator->run($pendingFiles);

        // Run the pending migrations
        foreach ($pendingFiles as $file) {
            $this->line("<comment>   Proceeded: </comment>{$file}");
        }
    }

    /**
     *********** SCOPE AND PERMISSIONS SYNC METHODS **********
     */

    /**
     * Update permissions dependencies for all concerns tables if scopes has been updated in the config file
     *
     * @return void
     */
    protected function syncScopes(): void
    {
        $this->warn("\nUpdating scopes...\n");

        // Refresh guards and legal entity settings before synchronizing from scope sources.
        $auth = require config_path('auth.php');
        $ehealth = require config_path('ehealth.php');

        // Load guards, sync exclusions, eligible employee roles and localized entity type names into memory from the config files.
        config([
            'auth.guards' => $auth['guards'],
            'auth.scope_sync_excluded_guards' => $auth['scope_sync_excluded_guards'] ?? [],
            'ehealth.legal_entity_employee_types' => $ehealth['legal_entity_employee_types'],
            'ehealth.legal_entity_localized_names' => $ehealth['legal_entity_localized_names'],
        ]);

        $excludedGuards = array_intersect(array_keys($auth['guards']), config('auth.scope_sync_excluded_guards'));

        // Just inform the user about the guards that are excluded from scope synchronization.
        if ($excludedGuards !== []) {
            $this->info(__('Guards excluded from scope synchronization: :guards', [
                'guards' => implode(', ', $excludedGuards),
            ]));
        }

        $typeScopes = $this->readLegalEntityTypeScopes(); // Scopes by legal entity type from legal_entity_types.json, or null.
        $roleScopes = $this->readRoleScopes(); // Scopes by eligible role from roles.json, or null.

        // Set scopes in memory for database sync, using the existing PHP config if a JSON source is missing.
        config([
            'ehealth.legal_entity_types' => $typeScopes ?? $ehealth['legal_entity_types'],
            'ehealth.roles' => $roleScopes ?? $ehealth['roles'],
        ]);

        DB::transaction(function () use ($typeScopes, $roleScopes): void {
            $this->syncLegalEntityTypes();
            $this->syncLegalEntityRoles();
            $this->syncLegalEntityTypeRoles();
            $this->syncPermissions();
            $this->syncLegalEntityTypePermissions();
            $this->syncRolePermissions();

            // Save generated scope files and clear permission caches only after the database transaction commits.
            DB::afterCommit(function () use ($typeScopes, $roleScopes): void {
                try {
                    if ($typeScopes !== null) {
                        $this->saveScopesToFile(config_path(self::CONFIG_TYPES_SCOPE_PATH), $typeScopes);
                    }

                    if ($roleScopes !== null) {
                        $this->saveScopesToFile(config_path(self::CONFIG_ROLES_SCOPE_PATH), $roleScopes);
                    }
                } finally {
                    // The committed permissions must take effect even when publishing a scope file fails.
                    $this->invalidatePermissionCaches();
                }
            });
        });
    }

    /**
     * Guards whose roles and permissions must match the scope sources.
     *
     * @return list<string>
     */
    protected function scopeSyncGuards(): array
    {
        return array_values(array_diff(
            array_keys(config('auth.guards', [])),
            config('auth.scope_sync_excluded_guards', [])
        ));
    }

    /**
     * Clear cached legal entity types and allowed permission names for every entity and guard,
     * including the shared store and this process's memoized copies, then reset Spatie's permission cache.
     * Called after scope synchronization commits so subsequent logins use the updated permissions.
     */
    protected function invalidatePermissionCaches(): void
    {
        $cache = cache()->memo();

        $guards = array_keys(config('auth.guards', []));

        $legalEntities = DB::table('legal_entities')
            ->select('id')
            ->lazyById(self::CHUNK_SIZE);

        foreach ($legalEntities as $legalEntity) {
            $legalEntityId = $legalEntity->id;

            // Forget through memo() to clear both the shared store and this process's copy.
            $cache->forget('le_type:' . $legalEntityId);

            foreach ($guards as $guard) {
                // User::allowedPermissionNamesForCurrentTeam() caches this list.
                // Clearing it forces the next read to fetch updated scopes from the DB.
                $cache->forget('allowed_permissions:' . $legalEntityId . ':' . $guard);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Synchronize the initial data for the legal_entity_types table.
     *
     * @return void
     */
    protected function syncLegalEntityTypes(): void
    {
        $this->info("\n-- Synchronize Legal Entity Types --\n");

        $now = Carbon::now()->format('Y-m-d H:i:s');

        $availableTypes = [];

        foreach (config('ehealth.legal_entity_localized_names') as $typeName => $localizedName) {
            $availableTypes[$typeName] = $localizedName;
        }

        $configuredTypes = array_keys(config('ehealth.legal_entity_types', []));

        $configuredTypes = array_filter(array_keys($availableTypes), function ($typeName) use ($configuredTypes) {
            if (\in_array($typeName, $configuredTypes, true)) {
                return $typeName;
            }
        });

        $storedTypes = DB::table('legal_entity_types')
            ->pluck('name')
            ->all();

        // Missing (need to add): types that are in config but not in DB
        $missingTypes = array_diff($configuredTypes, $storedTypes);
        // Extra (in DB but not in config): types that are in DB but not in config
        $extraTypes = array_diff($storedTypes, $configuredTypes);

        if (!empty($missingTypes)) {
            $this->warn("\nFound types missing in the database: " . json_encode(array_values($missingTypes)) . "\n");

            // Prepare Type's data to insert into DB
            foreach ($missingTypes as $typeName) {
                $typesToInsert[] = [
                    'name' => $typeName,
                    'localized_name' => $availableTypes[$typeName] ?? '',
                    'created_at' => $now,
                    'updated_at' => $now
                ];
            }

            DB::table('legal_entity_types')->insert($typesToInsert);
        } else {
            $this->info("\nAll types from config are present in the database.");
        }

        if (!empty($extraTypes)) {
            $this->warn("\nFound types are not defined in the config: " . json_encode(array_values($extraTypes)) . "\n");

            DB::table('legal_entity_types')
                ->whereIn('name', $extraTypes)
                ->delete();
        } else {
            $this->info("\nNo extra types found in the database that are not defined in the config.\n");
        }
    }

    /**
     * Synchronize up roles specific to legal entities.
     *
     * @return void
     */
    protected function syncLegalEntityRoles(): void
    {
        $this->info("\n-- Synchronize Legal Entity and Roles --\n");

        $now = Carbon::now()->format('Y-m-d H:i:s');

        $guards = $this->scopeSyncGuards();

        $roleNames = array_keys(config('ehealth.roles'));

        $storedRoles = Role::whereIn('guard_name', $guards)
            ->get(['name', 'guard_name'])
            ->groupBy('guard_name');

        $rolesToInsert = [];

        foreach ($guards as $guard) {
            $storedNames = $storedRoles->get($guard, collect())
                ->pluck('name')
                ->all();

            $missingRoles = array_diff($roleNames, $storedNames);

            foreach ($missingRoles as $roleName) {
                $rolesToInsert[] = [
                    'name' => $roleName,
                    'guard_name' => $guard,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($rolesToInsert !== []) {
            DB::table('roles')->insert($rolesToInsert);
        }

        $insertedRolesByGuard = collect($rolesToInsert)->groupBy('guard_name');

        foreach ($insertedRolesByGuard as $guard => $roles) {
            $this->warn(__('Added roles for guard :guard: :names', [
                'guard' => $guard,
                'names' => $roles->pluck('name')->unique()->implode(', '),
            ]));
        }

        $rolesToRemove = DB::table('roles')
            ->whereNotIn('guard_name', $guards)
            ->orWhereNotIn('name', $roleNames)
            ->get(['id', 'name', 'guard_name']);

        DB::table('roles')
            ->whereIn('id', $rolesToRemove->pluck('id'))
            ->delete();

        foreach ($rolesToRemove->groupBy('guard_name') as $guard => $roles) {
            $this->warn(__('Removed roles for guard :guard: :names', [
                'guard' => $guard,
                'names' => $roles->pluck('name')->unique()->implode(', '),
            ]));
        }

        $this->info(__('Roles synchronized for participating guards.'));
    }

    /**
     * Synchronize up the legal entity type roles in the database.
     *
     * @return void
     */
    protected function syncLegalEntityTypeRoles(): void
    {
        $this->info("\n-- Synchronize Legal Entity Type and Roles --\n");

        $availableRoles = Role::whereIn('guard_name', $this->scopeSyncGuards())->get(['id', 'name']);

        foreach (LegalEntityType::all() as $legalEntityType) {
            $roleNames = config('ehealth.legal_entity_employee_types.' . $legalEntityType->name, []);

            $roleIds = $availableRoles
                ->whereIn('name', $roleNames)
                ->pluck('id')
                ->all();

            // Compare pivot IDs so an existing role for one guard cannot hide a missing link for another.
            $changes = $legalEntityType
                ->roles()
                ->withTimestamps()
                ->sync($roleIds);

            if ($changes['attached'] !== [] || $changes['detached'] !== []) {
                $this->warn(__('Roles for legal entity type :type: added :added, removed :removed.', [
                    'type' => $legalEntityType->name,
                    'added' => count($changes['attached']),
                    'removed' => count($changes['detached']),
                ]));
            }
        }

        $this->info(__('Legal entity type roles synchronized for enabled guards.'));
    }

    /**
     * Synchronize up the default permissions for the application.
     *
     * @return void
     */
    protected function syncPermissions(): void
    {
        $this->info("\n-- Synchronize Permissions --\n");

        $now = Carbon::now()->format('Y-m-d H:i:s');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Unique scope names from all legal entity types and roles, used as the shared permissions catalog for each enabled guard.
        $permissionNames = collect(config('ehealth.legal_entity_types'))
            ->concat(config('ehealth.roles'))
            ->flatten()
            ->filter(fn ($scope) => \is_string($scope) && trim($scope) !== '')
            ->map(fn ($scope) => trim($scope))
            ->unique()
            ->values()
            ->all();

        $guards = $this->scopeSyncGuards();
        $storedPermissions = Permission::whereIn('guard_name', $guards)
            ->get(['name', 'guard_name'])
            ->groupBy('guard_name');
        $permissionsToInsert = [];

        foreach ($guards as $guard) {
            $storedNames = $storedPermissions
                ->get($guard, collect())
                ->pluck('name')
                ->all();

            foreach (array_diff($permissionNames, $storedNames) as $permissionName) {
                $permissionsToInsert[] = [
                    'name' => $permissionName,
                    'guard_name' => $guard,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($permissionsToInsert, self::CHUNK_SIZE) as $chunk) {
            DB::table('permissions')->insert($chunk);
        }

        $insertedPermissionsByGuard = collect($permissionsToInsert)->groupBy('guard_name');

        foreach ($insertedPermissionsByGuard as $guard => $permissions) {
            $this->warn(__('Added permissions for guard :guard: :names', [
                'guard' => $guard,
                'names' => $permissions->pluck('name')->unique()->implode(', '),
            ]));
        }

        $permissionsToRemove = DB::table('permissions')
            ->whereNotIn('guard_name', $guards)
            ->orWhereNotIn('name', $permissionNames)
            ->get(['id', 'name', 'guard_name']);

        DB::table('permissions')
            ->whereIn('id', $permissionsToRemove->pluck('id'))
            ->delete();

        $removedPermissionsByGuard = $permissionsToRemove->groupBy('guard_name');

        foreach ($removedPermissionsByGuard as $guard => $permissions) {
            $this->warn(__('Removed permissions for guard :guard: :names', [
                'guard' => $guard,
                'names' => $permissions->pluck('name')->unique()->implode(', '),
            ]));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->info(__('Permissions synchronized for enabled guards.'));
    }

    /**
     * Synchronize up default permissions for legal entity types
     *
     * @return void
     */
    protected function syncLegalEntityTypePermissions(): void
    {
        $this->info("\n-- Synchronize Legal Entity Type and Permissions --\n");

        $availablePermissions = Permission::whereIn('guard_name', $this->scopeSyncGuards())->get(['id', 'name']);

        foreach (LegalEntityType::all() as $legalEntityType) {
            $permissionNames = config('ehealth.legal_entity_types.' . $legalEntityType->name, []);

            $permissionIds = $availablePermissions
                ->whereIn('name', $permissionNames)
                ->pluck('id')
                ->all();

            // Include each configured guard's permission ID, even when the same scope already exists for another guard.
            $changes = $legalEntityType
                ->permissions()
                ->withTimestamps()
                ->sync($permissionIds);

            if ($changes['attached'] !== [] || $changes['detached'] !== []) {
                $this->warn(__('Permissions for legal entity type :type: added :added, removed :removed.', [
                    'type' => $legalEntityType->name,
                    'added' => \count($changes['attached']),
                    'removed' => \count($changes['detached']),
                ]));
            }
        }

        $this->info(__('Legal entity type permissions synchronized for enabled guards.'));
    }

    /**
     * Synchronize default role permissions for the application.
     *
     * @return void
     */
    protected function syncRolePermissions(): void
    {
        $this->info("\n-- Synchronize Role and Permissions --\n");

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guards = $this->scopeSyncGuards();

        $availablePermissions = Permission::whereIn('guard_name', $guards)
            ->get(['id', 'name', 'guard_name'])
            ->groupBy('guard_name');

        $roles = Role::whereIn('guard_name', $guards)->get();

        foreach ($roles as $role) {
            $permissionNames = config('ehealth.roles.' . $role->name, []);

            $permissionIds = $availablePermissions
                ->get($role->guard_name, collect())
                ->whereIn('name', $permissionNames)
                ->pluck('id')
                ->all();

            // Synchronize each role record separately and remove links to permissions from other guards.
            $changes = $role->permissions()->sync($permissionIds);

            if ($changes['attached'] !== [] || $changes['detached'] !== []) {
                $this->warn(__('Permissions for role :role and guard :guard: added :added, removed :removed.', [
                    'role' => $role->name,
                    'guard' => $role->guard_name,
                    'added' => count($changes['attached']),
                    'removed' => count($changes['detached']),
                ]));
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->info(__('Role permissions synchronized for enabled guards.'));
    }

    /**
     * Read legal entity type scopes from the source file, or return null when absent.
        *
        * Base source structure for scopes/legal_entity_types.json:
        * {
        *   "data": [
        *     {
        *       "name": "PRIMARY_CARE",
        *       "scope": "employee:read employee:write",
        *       "missed": "division:read",
        *       "extra": "contract:read"
        *     },
        *     {
        *       "name": "MSP",
        *       "scope": "employee:read employee:write"
        *     }
        *        ...
        *   ]
        * }
        *
        * Note:
        * - "scope" is the base list of scopes separated by spaces.
        * - "missed" adds scopes that should be appended to the final set.
        * - "extra" also adds scopes to the final set.
        * - "missed" and "extra" are optional and may be ommitted.
     *
     * @return array<string, list<string>>|null
     */
    protected function readLegalEntityTypeScopes(): ?array
    {
        if (!Storage::exists(self::LE_TYPES_SCOPE_PATH)) {
            $this->warn("\nNo source scopes file for legal entity types found. Skipping update config...\n");

            return null;
        }

        $config = [];
        $data = $this->readScopesSource(self::LE_TYPES_SCOPE_PATH);

        foreach ($data as $item) {
            $typeName = Arr::get($item, 'name');

            if (!array_key_exists($typeName, config('ehealth.legal_entity_localized_names'))) {
                throw new RuntimeException(__('Unknown legal entity type :name in scopes file :path.', [
                    'name' => $typeName,
                    'path' => self::LE_TYPES_SCOPE_PATH,
                ]));
            }

            $scopes = explode(" ", Arr::get($item, 'scope', ""));
            $missed = explode(" ", Arr::get($item, 'missed', ""));
            $extra = explode(" ", Arr::get($item, 'extra', ""));

            $config[$typeName] = collect([...$scopes, ...$missed, ...$extra])
                ->filter(fn ($scope) => !empty($scope))
                ->unique()
                ->values()
                ->toArray();
        }

        return $config;
    }

    /**
     * Read role scopes from the source file, or return null when absent.
        *
        * Base source structure for scopes/roles.json:
        * {
        *   "data": [
        *     {
        *       "name": "DOCTOR",
        *       "scope": "encounter:read encounter:write"
        *     },
        *     {
        *       "name": "ADMIN",
        *       "scope": "employee:read employee:write"
        *     }
        *       ...
        *   ]
        * }
        *
        * Note:
        * - "name" must match a role from ehealth.legal_entity_employee_types.
        * - "scope" is a space-delimited list.
     *
     * @return array<string, list<string>>|null
     */
    protected function readRoleScopes(): ?array
    {
        if (!Storage::exists(self::LE_ROLES_SCOPE_PATH)) {
            $this->warn("\nNo source scopes file for legal entity roles found. Skipping update config...\n");

            return null;
        }

        $roles = collect(array_values(config('ehealth.legal_entity_employee_types')))->flatten()->unique();

        $config = [];
        $data = $this->readScopesSource(self::LE_ROLES_SCOPE_PATH);

        foreach ($data as $item) {
            $roleName = Arr::get($item, 'name');

            if (!$roles->contains($roleName)) {
                continue;
            }

            $scopes = explode(" ", Arr::get($item, 'scope', ""));

            $config[$roleName] = collect($scopes)
                ->filter(fn ($scope) => !empty($scope))
                ->unique()
                ->values()
                ->toArray();
        }

        if ($config === []) {
            throw new RuntimeException(__('No eligible roles found in scopes file :path.', [
                'path' => self::LE_ROLES_SCOPE_PATH,
            ]));
        }

        return $config;
    }

    /**
     * Read and validate a nonempty list of scope records before any database changes.
     *
     * @return list<array{name: string, scope: string, missed?: string, extra?: string}>
     */
    protected function readScopesSource(string $path): array
    {
        try {
            $source = Storage::json($path, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(__('Invalid JSON in scopes file :path.', ['path' => $path]), 0, $exception);
        }

        $validator = Validator::make(['data' => $source['data'] ?? null], [
            'data' => ['required', 'array', 'list', 'min:1'],
            'data.*' => ['required', 'array'],
            'data.*.name' => ['required', 'string', 'distinct:strict'],
            'data.*.scope' => ['required', 'string'],
            'data.*.missed' => ['sometimes', 'string'],
            'data.*.extra' => ['sometimes', 'string'],
        ]);

        if ($validator->fails()) {
            throw new RuntimeException(__('Invalid scopes file :path. :error', [
                'path' => $path,
                'error' => $validator->errors()->first(),
            ]));
        }

        return $source['data'];
    }

    /**
     * Save generated scopes as a PHP configuration file.
     *
     * @param  array<string, list<string>>  $config
     */
    protected function saveScopesToFile(string $path, array $config): void
    {
        $contents = '<?php' . PHP_EOL . PHP_EOL . 'return [' . PHP_EOL;

        foreach ($config as $name => $scopes) {
            $name = var_export($name, true);
            $contents .= "\t{$name} => [" . PHP_EOL . "\t\t";

            foreach ($scopes as $scope) {
                $contents .= var_export($scope, true) . ', ';
            }

            $contents = rtrim($contents) . PHP_EOL . "\t]," . PHP_EOL;
        }

        File::replace($path, $contents . '];' . PHP_EOL);
    }

    /**
     * Display the command help if no additional arguments were provided and the flag is enabled.
     *
     * @return bool True if help was displayed and execution should stop, false otherwise.
     */
    protected function showHelpIfNeeded(): bool
    {
        if ($this->showHelpWhenEmpty && !array_filter($this->options())) {
            $this->call('help', [
                'command_name' => $this->getName(),
            ]);

            return true;
        }

        return false;
    }
}
