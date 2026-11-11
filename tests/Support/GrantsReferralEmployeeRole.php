<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Employee\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

trait GrantsReferralEmployeeRole
{
    protected function grantReferralEmployeeRole(User $user, Employee $employee, string $guard = 'web'): void
    {
        setPermissionsTeamId($employee->legalEntityId);
        $permission = Permission::findOrCreate('service_request:read', $guard);
        $permission->legalEntityTypes()->syncWithoutDetaching([$employee->legalEntity->legalEntityTypeId]);
        $role = Role::findOrCreate($employee->employeeType, $guard);
        $role->syncPermissions($permission);
        $user->givePermissionToParent($permission);
        $user->assignRoleParent($role);
        $user->unsetRelation('roles');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
