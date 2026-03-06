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
        return $user->can('employee:read')
            ? Response::allow()
            : Response::deny(__('employees.policy.view_any_denied'));
    }

    public function view(User $user, Employee $employee): Response
    {
        if ((int) $employee->legalEntityId !== (int) legalEntity()->id) {
            return Response::denyWithStatus(404);
        }

        return $user->can('employee:details')
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
        if (is_null($employee->party?->users()->first()?->id)) {
            return Response::deny(__('employees.policy.no_user_linked'));
        }

        // 4.Status check (dismissed cannot be edited)
        if ($employee->status === Status::DISMISSED) {
            return Response::deny(__('employees.policy.emp.dismissed_no_edit'));
        }

        // 5. Checking the access rights of the current user (ACL)
        return $user->can('employee:write')
            ? Response::allow()
            : Response::deny(__('employees.policy.update_denied'));
    }

    public function deactivate(User $user, Employee $employee): Response
    {
        if ((int) $employee->legalEntityId !== (int) legalEntity()->id) {
            return Response::denyWithStatus(404);
        }

        return $user->can('employee:deactivate')
            ? Response::allow()
            : Response::deny(__('employees.policy.deactivate_denied'));
    }

    /**
     * Determine whether the user can sync the employee with eHealth.
     */
    public function sync(User $user, Employee $employee): Response
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
        return $user->can('employee:write')
            ? Response::allow()
            : Response::deny(__('employees.policy.emp.update_denied'));
    }
}
