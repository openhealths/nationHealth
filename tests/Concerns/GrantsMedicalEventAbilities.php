<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Permission;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

trait GrantsMedicalEventAbilities
{
    /**
     * @param  list<string>  $extra
     */
    protected function grantMedicalEventAbilities(User $user, array $extra = []): void
    {
        if (config('permission.teams')) {
            $teamId = getPermissionsTeamId()
                ?: $user->employees()->value('legal_entity_id')
                ?: \App\Models\Employee\Employee::query()->where('user_id', $user->id)->value('legal_entity_id')
                ?: legalEntity()?->id;
            if ($teamId) {
                setPermissionsTeamId($teamId);
            }
        }

        $names = array_values(array_unique(array_merge([
            'care_plan:read',
            'care_plan:write',
            'service_request:read',
            'service_request:write',
            'service_request:makeinprogress',
            'service_request:complete',
            'service_request:use',
            'medication_request:read',
            'medication_request:write',
            'device_request:read',
            'device_request:write',
        ], $extra)));

        $permissions = array_map(
            static fn (string $name) => Permission::findOrCreate($name, 'web'),
            $names
        );

        $user->givePermissionToParent(...$permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
