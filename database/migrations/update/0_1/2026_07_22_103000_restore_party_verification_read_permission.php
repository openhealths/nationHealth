<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Restore party_verification:read after eHealth confirmed the scope is valid.
 * Re-attaches the permission to roles / legal entity types that list it in config/scopes.
 */
return new class extends Migration
{
    private const string PERMISSION = 'party_verification:read';

    public function up(): void
    {
        $now = now();
        $guards = array_keys((array) config('auth.guards'));

        foreach ($guards as $guard) {
            $exists = DB::table('permissions')
                ->where('name', self::PERMISSION)
                ->where('guard_name', $guard)
                ->exists();

            if (!$exists) {
                DB::table('permissions')->insert([
                    'name' => self::PERMISSION,
                    'guard_name' => $guard,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $permissionIds = DB::table('permissions')
            ->where('name', self::PERMISSION)
            ->pluck('id', 'guard_name');

        $this->attachToRoles($permissionIds);
        $this->attachToLegalEntityTypes($permissionIds->values()->all(), $now);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function attachToRoles($permissionIdsByGuard): void
    {
        $rolesConfig = (array) config('ehealth.roles');

        foreach ($rolesConfig as $roleName => $scopes) {
            if (!in_array(self::PERMISSION, (array) $scopes, true)) {
                continue;
            }

            $roles = DB::table('roles')->where('name', $roleName)->get(['id', 'guard_name']);

            foreach ($roles as $role) {
                $permissionId = $permissionIdsByGuard[$role->guard_name] ?? null;

                if (!$permissionId) {
                    continue;
                }

                $exists = DB::table('role_has_permissions')
                    ->where('role_id', $role->id)
                    ->where('permission_id', $permissionId)
                    ->exists();

                if (!$exists) {
                    DB::table('role_has_permissions')->insert([
                        'role_id' => $role->id,
                        'permission_id' => $permissionId,
                    ]);
                }
            }
        }
    }

    /**
     * @param  list<int>  $permissionIds
     */
    private function attachToLegalEntityTypes(array $permissionIds, $now): void
    {
        $typesConfig = (array) config('ehealth.legal_entity_types');

        foreach ($typesConfig as $typeName => $scopes) {
            if (!in_array(self::PERMISSION, (array) $scopes, true)) {
                continue;
            }

            $typeId = DB::table('legal_entity_types')->where('name', $typeName)->value('id');

            if (!$typeId) {
                continue;
            }

            foreach ($permissionIds as $permissionId) {
                $exists = DB::table('legal_entity_type_permissions')
                    ->where('legal_entity_type_id', $typeId)
                    ->where('permission_id', $permissionId)
                    ->exists();

                if (!$exists) {
                    DB::table('legal_entity_type_permissions')->insert([
                        'legal_entity_type_id' => $typeId,
                        'permission_id' => $permissionId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->where('name', self::PERMISSION)
            ->pluck('id');

        if ($permissionIds->isEmpty()) {
            return;
        }

        DB::table('role_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('legal_entity_type_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
