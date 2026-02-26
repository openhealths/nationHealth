<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\Permission;
use Illuminate\Support\Carbon;
use App\Models\LegalEntityType;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Database\Migrations\Migrator;

abstract class MigrationsCommand extends Command
{
    protected const int CHUNK_SIZE = 1000;

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
        if ($this->hasOption('scopes') && $this->option('scopes')) {
            $this->call('config:clear');

            $this->call('config:cache');

            $this->info('Config cache updated');

            $this->syncScopes();

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

        if (! $isCacheCleared) {
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
        if($migrator->hasRunAnyMigrations()) {
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
        $this->warn("Updating scopes...\n");

        DB::transaction(function() {
            $this->syncLegalEntityTypes();
            $this->syncLegalEntityRoles();
            $this->syncLegalEntityTypeRoles();
            $this->syncPermissions();
            $this->syncLegalEntityTypePermissions();
            $this->syncRolePermissions();
        });
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
            if (in_array($typeName, $configuredTypes, true)) {
                return $typeName;
            }
        });

        $storedTypes = DB::table('legal_entity_types')->pluck('name')->all();

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

        $rolesToInsert = [];

        // Get all specified guards from section 'guards' from file config/auth.php
        $guards = collect(array_keys((array) config('auth.guards')))
            ->values();

        $roleList = array_keys(config('ehealth.roles'));

        $availableRoles = Role::get(['id', 'name'])
            ->groupBy('name')
            ->map(fn ($group) => $group->pluck('id')->values()->all())
            ->toArray();

        $availableRoleNames = array_keys($availableRoles);

        // Missing (need to add): roles that are in config but not in DB
        $missingRoles = array_diff($roleList, $availableRoleNames);
        // Extra (in DB but not in config): roles that are in DB but not in config
        $extraRoles = array_diff($availableRoleNames, $roleList);

        if (!empty($missingRoles)) {
            $this->warn("\nFound roles missing in the database: " . json_encode(array_values($missingRoles)) . "\n");

            // Prepare Role's and Permission's data to insert into DB
            foreach ($guards as $guard) {
                foreach ($missingRoles as $roleName) {
                    $rolesToInsert[] = [
                        'name' => $roleName,
                        'guard_name' => $guard,
                        'created_at' => $now,
                        'updated_at' => $now
                    ];
                }
            }

            DB::table('roles')->insert($rolesToInsert);
        } else {
            $this->info("\nAll roles from config are present in the database.");
        }

        if (!empty($extraRoles)) {
            $this->warn("\nFound roles are not defined in the config: " . json_encode(array_values($extraRoles)) . "\n");

            DB::table('roles')
                ->whereIn('name', $extraRoles)
                ->whereIn('guard_name', $guards)
                ->delete();
        } else {
            $this->info("\nNo extra roles found in the database that are not defined in the config.\n");
        }
    }

    /**
     * Synchronize up the legal entity type roles in the database.
     *
     * @return void
     */
    protected function syncLegalEntityTypeRoles(): void
    {
        $this->info("\n-- Synchronize Legal Entity Type and Roles --\n");

        $now = Carbon::now()->format('Y-m-d H:i:s');

        $availableRoles = Role::get(['id', 'name'])
                ->groupBy('name')
                ->map(fn ($group) => $group->pluck('id')->values()->all())
                ->toArray();

        $legalEntityTypes = LegalEntityType::all();

        $rolesToAdd = [];
        $rolesToRemove = [];

        foreach ($legalEntityTypes as $legalEntityType) {
            $legalEntityTypeName = $legalEntityType->name;
            $legalEntityTypeRoles = $legalEntityType->roles()->get(['name'])->pluck('name')->unique()->toArray();

            if (isset(config('ehealth.legal_entity_employee_types')[$legalEntityTypeName])) {
                $roles = config('ehealth.legal_entity_employee_types')[$legalEntityTypeName];

                 // Missing (need to add): types and roles relation that are in config but not in DB
                $missingRoles= array_values((array_diff($roles, $legalEntityTypeRoles)));
                // Extra (in DB but not in config): types and roles relation that are in DB but not in config
                $extraRoles = array_values((array_diff($legalEntityTypeRoles, $roles)));

                if (!empty($missingRoles)) {
                    foreach ($missingRoles as $missingRole) {
                        foreach ($availableRoles[$missingRole] as $roleId) {
                            $rolesToAdd[] = [
                                'legal_entity_type_id' => $legalEntityType->id,
                                'role_id' => $roleId,
                                'created_at' => $now,
                                'updated_at' => $now
                            ];
                        }
                    }
                }

                if (!empty($extraRoles)) {
                    foreach ($extraRoles as $extraRole) {
                        foreach ($availableRoles[$extraRole] as $roleId) {
                            $rolesToRemove[$legalEntityType->id][] = $roleId;
                        }
                    }
                }

            } else {
                echo "WARNING: No roles defined for legal entity type '{$legalEntityTypeName}' in the configuration.";
            }
        }

        if (!empty($rolesToAdd)) {
            $this->warn("\nFound roles missing in the database\n");

            DB::table('legal_entity_type_roles')->insert($rolesToAdd);
        } else {
            $this->info("\nAll roles for legal entity types from config are present in the database.");
        }

        if (!empty($rolesToRemove)) {
            $this->warn("\nFound roles for legal entity types are not defined in the config\n");

            foreach ($rolesToRemove as $legalEntityTypeId => $roleIds) {
                DB::table('legal_entity_type_roles')
                    ->where('legal_entity_type_id', $legalEntityTypeId)
                    ->whereIn('role_id', $roleIds)
                    ->delete();
            }
        } else {
            $this->info("\nNo extra roles for legal entity types found in the database that are not defined in the config.\n");
        }
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

        // Ensure that no cached permission state during seeding
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Collect all types permissions from config source
        $typePermissions = collect((array) config('ehealth.legal_entity_types'))
            ->flatMap(fn ($arr) => (array) $arr);

        // Collect all roles permissions from config source
        $rolePermissions = collect((array) config('ehealth.roles'))
            ->flatMap(fn ($arr) => (array) $arr);

        // Combine and deduplicate permission names
        $configPermissions = $typePermissions
            ->merge($rolePermissions)
            ->filter(fn ($v) => is_string($v) && trim($v) !== '')
            ->map(fn ($v) => trim($v))
            ->unique()
            ->values()
            ->toArray();

        // Get available guards
        $guards = collect(array_keys((array) config('auth.guards')))
            ->values();

        $currentPermissions = Permission::get()
            ->pluck('name')
            ->unique()
            ->values()
            ->toArray();

        // Missing (need to add): permissions that are in config but not in DB
        $missingPermissions = array_diff($configPermissions, $currentPermissions);
        // Extra (in DB but not in config): permissions that are in DB but not in config
        $extraPermissions = array_values(array_diff($currentPermissions, $configPermissions));

        $permissionsToInsert = [];

        if (!empty($missingPermissions)) {
            $this->warn("\nFound permissions missing in the database: " . json_encode(array_values($missingPermissions)) . "\n");

            foreach ($missingPermissions as $permission) {
                foreach ($guards as $guard) {
                    $permissionsToInsert[] = [
                        'name' => $permission,
                        'guard_name' => $guard,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            DB::table('permissions')->insert($permissionsToInsert);
        } else {
            $this->info("\nAll permissions from config are present in the database.");
        }

        if (!empty($extraPermissions)) {
            $this->warn("\nFound permissions are not defined in the config: " . json_encode(array_values($extraPermissions)) . "\n");

            DB::table('permissions')
                ->whereIn('name', $extraPermissions)
                ->whereIn('guard_name', $guards)
                ->delete();
        } else {
            $this->info("\nNo extra permissions found in the database that are not defined in the config.\n");
        }

        // Clear cached permissions once more again
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Synchronize up default permissions for legal entity types
     *
     * @return void
     */
    protected function syncLegalEntityTypePermissions(): void
    {
        $this->info("\n-- Synchronize Legal Entity Type and Permissions --\n");

        $now = Carbon::now()->format('Y-m-d H:i:s');

        $availablePermissions = Permission::get(['id', 'name'])
                ->groupBy('name')
                ->map(fn ($group) => $group->pluck('id')->values()->all())
                ->toArray();

        $legalEntityTypes = LegalEntityType::all();

        $permissionsToAdd = [];
        $permissionsToRemove = [];

        foreach ($legalEntityTypes as $legalEntityType) {
            $legalEntityTypeName = $legalEntityType->name;
            $legalEntityTypePermissions = $legalEntityType->permissions()->get(['name'])->pluck('name')->unique()->toArray();

            if (isset(config('ehealth.legal_entity_types')[$legalEntityTypeName])) {
                $permissions = config('ehealth.legal_entity_types')[$legalEntityTypeName];

                // Missing (need to add): types and permissions relation that are in config but not in DB
                $missingPermissions = array_values((array_diff($permissions, $legalEntityTypePermissions)));
                // Extra (in DB but not in config): types and permissions relation that are in DB but not in config
                $extraPermissions = array_values((array_diff($legalEntityTypePermissions, $permissions)));

                if (!empty($missingPermissions)) {
                    foreach ($missingPermissions as $missingPermission) {
                        foreach ($availablePermissions[$missingPermission] as $permissionId) {
                            $permissionsToAdd[] = [
                                'legal_entity_type_id' => $legalEntityType->id,
                                'permission_id' => $permissionId,
                                'created_at' => $now,
                                'updated_at' => $now
                            ];
                        }
                    }
                }

                if (!empty($extraPermissions)) {
                    foreach ($extraPermissions as $extraPermission) {
                        foreach ($availablePermissions[$extraPermission] as $permissionId) {
                            $permissionsToRemove[$legalEntityType->id][] = $permissionId;
                        }
                    }
                }

            } else {
                $this->warn("\nWARNING: No permissions defined for legal entity type '{$legalEntityTypeName}' in the configuration.");
            }
        }

        if (!empty($permissionsToAdd)) {
            $this->warn("\nFound permissions for legal entity types missing in the database\n");

            DB::table('legal_entity_type_permissions')->insert($permissionsToAdd);
        } else {
            $this->info("\nAll permissions for legal entity types from config are present in the database.");
        }

        if (!empty($permissionsToRemove)) {
            $this->warn("\nFound permissions for legal entity types are not defined in the config\n");

            foreach ($permissionsToRemove as $legalEntityTypeId => $permissionIds) {
                DB::table('legal_entity_type_permissions')
                    ->where('legal_entity_type_id', $legalEntityTypeId)
                    ->whereIn('permission_id', $permissionIds)
                    ->delete();
            }
        } else {
            $this->info("\nNo extra permissions for legal entity types found in the database that are not defined in the config.\n");
        }
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

        $availablePermissions = Permission::get(['id', 'name', 'guard_name'])
                ->groupBy('name')
                ->map(fn ($group) => $group->pluck('id', 'guard_name')->all())
                ->toArray();

        $roles = Role::get(['id', 'name', 'guard_name'])
                ->groupBy('name')
                ->map(fn ($group) => $group->pluck('id', 'guard_name')->all())
                ->toArray();

        $currentRolePermissions = Role::with('permissions')->get()->mapWithKeys(function ($role) {
            return [$role->name => $role->permissions->pluck('name')->unique()->toArray()];
        })->toArray();

        $permissionsToAdd = [];
        $permissionsToRemove = [];

        foreach ($roles as $roleName => $roleIds) {
            foreach ($roleIds as $guardName =>$roleId) {
                $rolePermissions = $currentRolePermissions[$roleName] ?? [];

                $rolesFromConfig = config('ehealth.roles', []);

                if (isset($rolesFromConfig[$roleName])) {
                    $permissions = $rolesFromConfig[$roleName];

                    // Missing (need to add): types and permissions relation that are in config but not in DB
                    $missingPermissions = array_values((array_diff($permissions, $rolePermissions)));
                    // Extra (in DB but not in config): types and permissions relation that are in DB but not in config
                    $extraPermissions = array_values((array_diff($rolePermissions, $permissions)));

                    if (!empty($missingPermissions)) {
                        foreach ($missingPermissions as $missingPermission) {
                            $permissionsToAdd[] = [
                                'role_id' => $roleId,
                                'permission_id' => $availablePermissions[$missingPermission][$guardName]
                            ];
                        }
                    }

                    if (!empty($extraPermissions)) {

                        foreach ($extraPermissions as $extraPermission) {
                            $permissionsToRemove[$roleId][] = $availablePermissions[$extraPermission][$guardName];
                        }
                    }

                } else {
                    $this->warn("\nWARNING: No permissions defined for role '{$roleName}' in the configuration.");
                }
            }
        }

        if (!empty($permissionsToAdd)) {
            $this->warn("\nFound permissions for roles missing in the database...");

            DB::table('role_has_permissions')->insert($permissionsToAdd);

            $this->warn("Synced succesfully\n");
        } else {
            $this->info("\nAll permissions for roles from config are present in the database.");
        }

        if (!empty($permissionsToRemove)) {
            $this->warn("\nFound permissions for roles are not defined in the config...");

            foreach ($permissionsToRemove as $roleId => $permissionIds) {
                DB::table('role_has_permissions')
                    ->where('role_id', $roleId)
                    ->whereIn('permission_id', $permissionIds)
                    ->delete();
            }

            $this->warn("Synced succesfully\n");
        } else {
            $this->info("\nNo extra permissions for roles found in the database that are not defined in the config.\n");
        }

        // Clear permission cache
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
