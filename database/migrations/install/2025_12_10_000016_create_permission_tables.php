<?php

declare(strict_types=1);

use App\Models\Permission;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    protected const int CHUNK_SIZE = 1000;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $teams = config('permission.teams');
        $tableNames = config('permission.table_names');
        $columnNames = config('permission.column_names');
        $pivotRole = $columnNames['role_pivot_key'] ?? 'role_id';
        $pivotPermission = $columnNames['permission_pivot_key'] ?? 'permission_id';

        if (empty($tableNames)) {
            throw new \Exception('Error: config/permission.php not loaded. Run [php artisan config:clear] and try again.');
        }
        if ($teams && empty($columnNames['team_foreign_key'] ?? null)) {
            throw new \Exception('Error: team_foreign_key on config/permission.php not loaded. Run [php artisan config:clear] and try again.');
        }

        Schema::create($tableNames['permissions'], function (Blueprint $table) {
            $table->bigIncrements('id'); // permission id
            $table->string('name');       // For MySQL 8.0 use string('name', 125);
            $table->string('guard_name'); // For MySQL 8.0 use string('guard_name', 125);
            $table->timestamps();

            $table->unique(['name', 'guard_name']);
        });

        Schema::create($tableNames['roles'], function (Blueprint $table) use ($teams, $columnNames) {
            $table->bigIncrements('id'); // role id
            if ($teams || config('permission.testing')) { // permission.testing is a fix for sqlite testing
                $table->unsignedBigInteger($columnNames['team_foreign_key'])->nullable();
                $table->index($columnNames['team_foreign_key'], 'roles_team_foreign_key_index');

                $table->foreign($columnNames['team_foreign_key'])
                    ->references('id')
                    ->on($columnNames['team_foreign_key_constraint_table'])
                    ->onDelete('cascade');
            }
            $table->string('name');       // For MySQL 8.0 use string('name', 125);
            $table->string('guard_name'); // For MySQL 8.0 use string('guard_name', 125);
            $table->timestamps();
            if ($teams || config('permission.testing')) {
                $table->unique([$columnNames['team_foreign_key'], 'name', 'guard_name']);
            } else {
                $table->unique(['name', 'guard_name']);
            }
        });

        Schema::create($tableNames['model_has_permissions'], function (Blueprint $table) use ($tableNames, $columnNames, $pivotPermission, $teams) {
            $table->unsignedBigInteger($pivotPermission);

            $table->string('model_type');
            $table->unsignedBigInteger($columnNames['model_morph_key']);
            $table->index([$columnNames['model_morph_key'], 'model_type'], 'model_has_permissions_model_id_model_type_index');

            $table->foreign($pivotPermission)
                ->references('id') // permission id
                ->on($tableNames['permissions'])
                ->onDelete('cascade');
            if ($teams) {
                $table->unsignedBigInteger($columnNames['team_foreign_key']);
                $table->index($columnNames['team_foreign_key'], 'model_has_permissions_team_foreign_key_index');

                $table->primary(
                    [$columnNames['team_foreign_key'], $pivotPermission, $columnNames['model_morph_key'], 'model_type'],
                    'model_has_permissions_permission_model_type_primary'
                );
            } else {
                $table->primary(
                    [$pivotPermission, $columnNames['model_morph_key'], 'model_type'],
                    'model_has_permissions_permission_model_type_primary'
                );
            }

        });

        Schema::create($tableNames['model_has_roles'], function (Blueprint $table) use ($tableNames, $columnNames, $pivotRole, $teams) {
            $table->unsignedBigInteger($pivotRole);

            $table->string('model_type');
            $table->unsignedBigInteger($columnNames['model_morph_key']);
            $table->index([$columnNames['model_morph_key'], 'model_type'], 'model_has_roles_model_id_model_type_index');

            $table->foreign($pivotRole)
                ->references('id') // role id
                ->on($tableNames['roles'])
                ->onDelete('cascade');
            if ($teams) {
                $table->unsignedBigInteger($columnNames['team_foreign_key']);

                $table->foreign($columnNames['team_foreign_key'])
                    ->references('id')
                    ->on($columnNames['team_foreign_key_constraint_table'])
                    ->onDelete('cascade');

                $table->index($columnNames['team_foreign_key'], 'model_has_roles_team_foreign_key_index');

                $table->primary(
                    [$columnNames['team_foreign_key'], $pivotRole, $columnNames['model_morph_key'], 'model_type'],
                    'model_has_roles_role_model_type_primary'
                );
            } else {
                $table->primary(
                    [$pivotRole, $columnNames['model_morph_key'], 'model_type'],
                    'model_has_roles_role_model_type_primary'
                );
            }
        });

        Schema::create($tableNames['role_has_permissions'], function (Blueprint $table) use ($tableNames, $pivotRole, $pivotPermission) {
            $table->unsignedBigInteger($pivotPermission);
            $table->unsignedBigInteger($pivotRole);

            $table->foreign($pivotPermission)
                ->references('id') // permission id
                ->on($tableNames['permissions'])
                ->onDelete('cascade');

            $table->foreign($pivotRole)
                ->references('id') // role id
                ->on($tableNames['roles'])
                ->onDelete('cascade');

            $table->primary([$pivotPermission, $pivotRole], 'role_has_permissions_permission_id_role_id_primary');
        });

        app('cache')
            ->store(config('permission.cache.store') !== 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));

        try {
            $this->setLegalEntityRoles();

            $this->setPermissions();

            $this->setRolePermissions();
        } catch (Exception $error) {
            $this->down();

            throw $error;
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tableNames = config('permission.table_names');

        if (empty($tableNames)) {
            throw new \Exception('Error: config/permission.php not found and defaults could not be merged. Please publish the package configuration before proceeding, or drop the tables manually.');
        }

        Schema::drop($tableNames['role_has_permissions']);
        Schema::drop($tableNames['model_has_roles']);
        Schema::drop($tableNames['model_has_permissions']);
        Schema::drop($tableNames['roles']);
        Schema::drop($tableNames['permissions']);
    }

    /**
     * Set up roles specific to legal entities.
     *
     * @return void
     */
    protected function setLegalEntityRoles(): void
    {
        $now = now();

        $rolesToInsert = [];

        // Get all specified guards from section 'guards' from file config/auth.php (except sanctum)
        $guards = collect(array_keys((array) config('auth.guards')))
            ->reject(fn ($guard) => $guard === 'sanctum')
            ->values();

        $roleList = array_keys(config('ehealth.roles'));

        // Prepare Role's and Permission's data to insert into DB
        foreach ($guards as $guard) {
            foreach ($roleList as $roleName) {
                $rolesToInsert[] = [
                    'name' => $roleName,
                    'guard_name' => $guard,
                    'created_at' => $now,
                    'updated_at' => $now
                ];
            }
        }

        DB::table('roles')->insert($rolesToInsert);
    }

    /**
     * Set up the default permissions for the application.
     *
     * @return void
     */
    protected function setPermissions(): void
    {
        // Ensure that no cached permission state during seeding
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Collect all types permissions from config source
        $typePermissions = collect((array) config('ehealth.legal_entity_types'))
            ->flatMap(fn ($arr) => (array) $arr);

        // Collect all roles permissions from config source
        $rolePermissions = collect((array) config('ehealth.roles'))
            ->flatMap(fn ($arr) => (array) $arr);

        // Combine and deduplicate permission names
        $allNames = $typePermissions
            ->merge($rolePermissions)
            ->filter(fn ($v) => is_string($v) && trim($v) !== '')
            ->map(fn ($v) => trim($v))
            ->unique()
            ->values();

        // Get available guards except sanctum
        $guards = collect(array_keys((array) config('auth.guards')))
            ->reject(fn ($g) => $g === 'sanctum')
            ->values();

        $now = now();
        $dataToInsert = [];

        foreach ($guards as $guard) {
            foreach ($allNames as $name) {
                $dataToInsert[] = [
                    'name' => $name,
                    'guard_name' => $guard,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // Insert data into table by chunks or ignore duplicates
        foreach (array_chunk($dataToInsert, self::CHUNK_SIZE) as $chunk) {
            DB::table(config('permission.table_names.permissions'))
                ->insertOrIgnore($chunk);
        }

        // Clear cached permissions again
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Set up default role permissions for the application.
     *
     * @return void
     */
    protected function setRolePermissions(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Get all guards except sanctum
        $guards = collect(array_keys((array) config('auth.guards')))
            ->reject(fn ($g) => $g === 'sanctum')
            ->values();

        // Get roles from config
        $rolesData = (array) config('ehealth.roles');

        // Extract names of roles
        $roleNames = array_keys($rolesData);

        foreach ($guards as $guard) {
            // Prepare permission map for this guard: permission name -> id
            $permMap = Permission::where('guard_name', $guard)
                ->get(['id', 'name'])
                ->keyBy('name')
                ->map(fn ($p) => (int) $p->id);

            // Get roles existing for this guard: role name -> id
            $roleMap = Role::whereIn('name', $roleNames)
                ->where('guard_name', $guard)
                ->get(['id', 'name'])
                ->keyBy('name')
                ->map(fn ($r) => (int) $r->id);

            $dataToInsert = [];

            foreach ($rolesData as $roleName => $roleScopes) {
                $roleId = $roleMap[$roleName] ?? null;

                if (!$roleId) {
                    // If role not present for this guard
                    continue;
                }

                // Get scopes from config
                $roleScopes = collect((array) $roleScopes)
                    ->filter(fn ($v) => is_string($v) && $v !== '');

                // Get permission IDs for this role's scopes
                $permIds = $roleScopes
                    ->unique()
                    ->map(fn ($name) => $permMap[$name] ?? null) // Here should be array of IDs
                    ->filter()
                    ->values();

                foreach ($permIds as $pid) {
                    $dataToInsert[] = [
                        'role_id' => $roleId,
                        'permission_id' => (int) $pid,
                    ];
                }
            }

            // Insert data into table by chunks for this guard
            if (!empty($dataToInsert)) {
                foreach (array_chunk($dataToInsert, self::CHUNK_SIZE) as $chunk) {
                    DB::table(config('permission.table_names.role_has_permissions'))
                        ->insertOrIgnore($chunk);
                }
            }
        }

        // Clear permission cache
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
