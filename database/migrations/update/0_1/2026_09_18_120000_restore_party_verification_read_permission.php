<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Restore party_verification:read after #562 removed it from Spatie.
 * OWNER/HR/REORGANIZATION_OWNER and LE types still advertise it in config/scopes;
 * OAuth scope= is built from Spatie, so without this row the bearer token never gets :read
 * and PartyVerificationFullSync hits 403 on GET /parties/verifications.
 */
return new class extends Migration
{
    private const string PERMISSION_NAME = 'party_verification:read';

    /**
     * Roles that must request bulk list scope (aligned with config/scopes/roles.php).
     *
     * @var list<string>
     */
    private const array ROLE_NAMES = [
        'HR',
        'OWNER',
        'REORGANIZATION_OWNER',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        $now = Carbon::now()->format('Y-m-d H:i:s');
        $guards = array_keys((array) config('auth.guards', ['web' => [], 'ehealth' => []]));

        if ($guards === []) {
            $guards = ['web', 'ehealth'];
        }

        $permissionIdsByGuard = [];

        foreach ($guards as $guard) {
            $existingId = DB::table('permissions')
                ->where('name', self::PERMISSION_NAME)
                ->where('guard_name', $guard)
                ->value('id');

            if ($existingId) {
                $permissionIdsByGuard[$guard] = (int) $existingId;

                continue;
            }

            $permissionIdsByGuard[$guard] = (int) DB::table('permissions')->insertGetId([
                'name' => self::PERMISSION_NAME,
                'guard_name' => $guard,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->attachToRoles($permissionIdsByGuard);
        $this->attachToLegalEntityTypes(array_values($permissionIdsByGuard), $now);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->where('name', self::PERMISSION_NAME)
            ->pluck('id');

        if ($permissionIds->isEmpty()) {
            return;
        }

        if (Schema::hasTable('role_has_permissions')) {
            DB::table('role_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        }

        if (Schema::hasTable('model_has_permissions')) {
            DB::table('model_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        }

        if (Schema::hasTable('legal_entity_type_permissions')) {
            DB::table('legal_entity_type_permissions')->whereIn('permission_id', $permissionIds)->delete();
        }

        DB::table('permissions')->whereIn('id', $permissionIds)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @param  array<string, int>  $permissionIdsByGuard
     */
    private function attachToRoles(array $permissionIdsByGuard): void
    {
        if (!Schema::hasTable('roles') || !Schema::hasTable('role_has_permissions')) {
            return;
        }

        $roles = DB::table('roles')
            ->whereIn('name', self::ROLE_NAMES)
            ->get(['id', 'guard_name']);

        $rows = [];

        foreach ($roles as $role) {
            $permissionId = $permissionIdsByGuard[$role->guard_name] ?? null;

            if ($permissionId === null) {
                continue;
            }

            $alreadyAttached = DB::table('role_has_permissions')
                ->where('role_id', $role->id)
                ->where('permission_id', $permissionId)
                ->exists();

            if ($alreadyAttached) {
                continue;
            }

            $rows[] = [
                'role_id' => $role->id,
                'permission_id' => $permissionId,
            ];
        }

        if ($rows !== []) {
            DB::table('role_has_permissions')->insert($rows);
        }
    }

    /**
     * @param  list<int>  $permissionIds
     */
    private function attachToLegalEntityTypes(array $permissionIds, string $now): void
    {
        if (!Schema::hasTable('legal_entity_types') || !Schema::hasTable('legal_entity_type_permissions')) {
            return;
        }

        $typeScopes = (array) config('ehealth.legal_entity_types', []);
        $typeNamesWithRead = [];

        foreach ($typeScopes as $typeName => $scopes) {
            if (is_array($scopes) && in_array(self::PERMISSION_NAME, $scopes, true)) {
                $typeNamesWithRead[] = $typeName;
            }
        }

        if ($typeNamesWithRead === []) {
            return;
        }

        $typeIds = DB::table('legal_entity_types')
            ->whereIn('name', $typeNamesWithRead)
            ->pluck('id');

        $rows = [];

        foreach ($typeIds as $typeId) {
            foreach ($permissionIds as $permissionId) {
                $alreadyAttached = DB::table('legal_entity_type_permissions')
                    ->where('legal_entity_type_id', $typeId)
                    ->where('permission_id', $permissionId)
                    ->exists();

                if ($alreadyAttached) {
                    continue;
                }

                $rows[] = [
                    'legal_entity_type_id' => $typeId,
                    'permission_id' => $permissionId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($rows !== []) {
            DB::table('legal_entity_type_permissions')->insert($rows);
        }
    }
};
