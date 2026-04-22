<?php

declare(strict_types=1);

use Carbon\Carbon;
use App\Models\User;
use App\Enums\User\Role;
use App\Models\Permission;
use App\Core\ExtendedMigration;
use App\Models\Role as ModelsRole;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 *
 * This migration add new roles
 */
return new class extends ExtendedMigration
{
    // Data to be added
    protected const Role NEW_ROLE = Role::REORGANIZATION_OWNER;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::table('roles')->where('name', self::NEW_ROLE->value)->exists()) {
            logger('Role already exists. Skipping roles update.');

            return;
        }

        $this->doUpdate();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!DB::table('roles')->where('name', self::NEW_ROLE->value)->exists()) {
            logger('Role already removed. Skipping role rollback.');

            return;
        }

        $this->doRollback();
    }

    /**
     * Set the updated data for the roles table.
     *
     * @return void
     */
    protected function doUpdate(): void
    {
        $rolesData = [];

        $guards = collect(array_keys((array) config('auth.guards')))->values();
        $permissionsForRole = config('ehealth.roles')[self::NEW_ROLE->value] ?? [];

        foreach($guards as $guard) {
            $rolesData[] = [
                'name' => self::NEW_ROLE->value,
                'guard_name' => $guard,
                'created_at' => new Carbon(),
                'updated_at' => new Carbon()
            ];
        }

        // Modify tables!
        DB::transaction(function () use ($rolesData, $permissionsForRole) {
            $rolesPermissionData = [];
            $legalEntityTypeRoleData = [];

            // Add new role
            DB::table('roles')->insertOrIgnore($rolesData);

            $roleIds = ModelsRole::where('name', self::NEW_ROLE->value)->pluck('id');

            $this->addNewRoleToUsers($roleIds);

            $legalEntityTypeId = DB::table('legal_entity_types')->where('name', 'MSP_LIMITED')->value('id');

            foreach($roleIds as $roleId) {
                $rolesPermissionData = array_merge($rolesPermissionData, Permission::whereIn('name', $permissionsForRole)
                    ->pluck('id', 'name')
                    ->map(fn($id) => [
                        'permission_id' => $id,
                        'role_id'       => $roleId,
                    ])
                    ->values()
                    ->all());

                $legalEntityTypeRoleData[] = [
                    'legal_entity_type_id' => $legalEntityTypeId,
                    'role_id' => $roleId,
                    'created_at' => new Carbon(),
                    'updated_at' => new Carbon()
                ];
            }

            // Add permissions to the new role
            DB::table('role_has_permissions')->insertOrIgnore($rolesPermissionData);

            // Add legal entity types to the new role
            DB::table('legal_entity_type_roles')->insertOrIgnore($legalEntityTypeRoleData);
        });
    }

    /**
     * Remove the previously added data for the roles table.
     *
     * @return void
     */
    protected function doRollback(): void
    {
        $roleIds = ModelsRole::where('name', self::NEW_ROLE->value)->pluck('id');

        DB::transaction(function () use ($roleIds) {
            // Remove the roles depends on guard
            foreach($roleIds as $roleId) {
                // LegalEntityTypeRole and RoleHasPermission records will be automatically removed due to foreign key constraints
                DB::table('roles')->where('id', $roleId)->delete();
            }
        });
    }

    /**
     * Add new role to users with OWNER employee role
     *
     * @param Collection $roleIds
     *
     * @return void
     */
    protected function addNewRoleToUsers(Collection $roleIds): void
    {
        $morphType = (new User)->getMorphClass();

        DB::table('employee_users')
            ->join('employees', 'employees.id', '=', 'employee_users.employee_id')
            ->where('employees.employee_type', Role::OWNER->value)
            ->select('employee_users.user_id', 'employees.legal_entity_id')
            ->distinct()
            ->get()
            ->each(function ($row) use ($roleIds, $morphType) {
                $roleIds->each(function ($roleId) use ($row, $morphType) {
                    DB::table('model_has_roles')->insertOrIgnore([
                        'model_id' => $row->user_id,
                        'model_type' => $morphType,
                        'role_id' => $roleId,
                        'legal_entity_id' => $row->legal_entity_id,
                    ]);
                });
            });
    }
};
