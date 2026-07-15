<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Status;
use App\Enums\User\Role;
use App\Models\Employee\Employee;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class EmployeePolicy
{
    public function viewAny(User $user): Response
    {
        return ($user->can('employee:read') || $user->hasAllowedRole([Role::ADMIN, Role::HR]))
            ? Response::allow()
            : Response::deny(__('employees.policy.view_any_denied'));
    }

    public function view(User $user, Employee $employee): Response
    {
        if ((int) $employee->legalEntityId !== (int) legalEntity()->id) {
            return Response::denyWithStatus(404);
        }

        return ($user->can('employee:details') || $user->hasAllowedRole([Role::ADMIN, Role::HR]))
            ? Response::allow()
            : Response::deny(__('employees.policy.view_denied'));
    }

    public function update(User $user, Employee $employee): Response
    {
        // 1. Verification of affiliation with the current institution
        if ((int)$employee->legalEntityId !== (int)legalEntity()->id) {
            return Response::denyWithStatus(404);
        }

        // 2. Prohibition of editing the owner of the establishment
        if ($employee->employeeType === Role::OWNER->value) {
            return Response::deny(__('employees.policy.owner_no_edit'));
        }

        // 3. Check if there is a connection with the user (user_id)
        if (is_null($employee->party?->users()->first()?->id) && !$user->hasAllowedRole([Role::ADMIN, Role::HR])) {
            return Response::deny(__('employees.policy.no_user_linked'));
        }

        // 4.Status check (dismissed cannot be edited)
        if ($employee->status === Status::DISMISSED) {
            return Response::deny(__('employees.policy.emp.dismissed_no_edit'));
        }

        // 5. Checking the access rights of the current user (ACL)
        return ($user->can('employee:write') || $user->hasAllowedRole([Role::ADMIN, Role::HR]))
            ? Response::allow()
            : Response::deny(__('employees.policy.update_denied'));
    }

    public function deactivate(User $user, Employee $employee): Response
    {
        if ((int) $employee->legalEntityId !== (int) legalEntity()->id) {
            return Response::denyWithStatus(404);
        }

        return ($user->can('employee:deactivate') || $user->hasAllowedRole([Role::ADMIN, Role::HR]))
            ? Response::allow()
            : Response::deny(__('employees.policy.deactivate_denied'));
    }

    /**
     * Determine whether the user can sync the employee with eHealth.
     * This allows users with 'employee:read' permission only to sync employees
     * (read an employees data from eHealth and save it to MIS database).
     */
    public function sync(User $user): Response
    {
        return $user->can('employee:read')
            ? Response::allow()
            : Response::deny(__('employees.policy.emp.update_denied'));
    }

    /**
     * Determine whether the user can sync the employee with eHealth.
     * This allows users with 'employee:write' permission to sync employees
     * (update an employee data on the eHealth side).
     */
    public function syncEmployee(User $user, Employee $employee): Response
    {
        // 1. Verification of affiliation with the current institution
        if ((int) $employee->legalEntityId !== (int) legalEntity()->id) {
            return Response::denyWithStatus(404);
        }

        // 2. State Check
        if (!$employee->party?->users()->first()?->id || !$employee->partyId || !$employee->uuid) {
            return Response::deny(__('employees.policy.sync_missing_data'));
        }

        // 3. PERMISSIONS
        return $user->can('employee:read')
            ? Response::allow()
            : Response::deny(__('employees.policy.emp.update_denied'));
    }
}
