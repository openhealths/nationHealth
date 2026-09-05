<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\EmployeeRole\Status;
use App\Enums\User\Role;
use App\Models\EmployeeRole;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class EmployeeRolePolicy
{
    /**
     * Roles that may view and manage employee roles.
     *
     * @var array
     */
    private const array MANAGING_ROLES = [Role::OWNER, Role::HR, Role::ADMIN];

    /**
     * User allowed to view the list of employee roles
     */
    public function viewAny(User $user): Response
    {
        if ($user->cannot('employee_role:read')) {
            return Response::denyWithStatus(404);
        }

        // The scope alone is held by more roles than may view the employee roles
        if (!$user->hasAllowedRole(self::MANAGING_ROLES)) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * User allowed to view an employee role
     */
    public function view(User $user, EmployeeRole $employeeRole): Response
    {
        if ($user->cannot('employee_role:read')) {
            return Response::denyWithStatus(404);
        }

        // The scope alone is held by more roles than may view an employee role
        if (!$user->hasAllowedRole(self::MANAGING_ROLES)) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * User allow to add an employee role
     */
    public function create(User $user): Response
    {
        if ($user->cannot('employee_role:write')) {
            return Response::denyWithStatus(404);
        }

        // The scope alone is held by more roles than may create an employee role
        if (!$user->hasAllowedRole(self::MANAGING_ROLES)) {
            return Response::denyWithStatus(404);
        }

        // Can be created for legal entities with the following statuses.
        if (!in_array(legalEntity()->status, ['ACTIVE', 'SUSPENDED'], true)) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can deactivate employee role.
     */
    public function deactivate(User $user, EmployeeRole $employeeRole): Response
    {
        if ($user->cannot('employee_role:write')) {
            return Response::denyWithStatus(404);
        }

        // The scope alone is held by more roles than may deactivate an employee role
        if (!$user->hasAllowedRole(self::MANAGING_ROLES)) {
            return Response::denyWithStatus(404);
        }

        // Legal entity can deactivate only its own employee roles
        if ($employeeRole->healthcareService->legalEntity->id !== legalEntity()->id) {
            return Response::denyWithStatus(404);
        }

        // Can be created for legal entities with the following statuses.
        if (!in_array(legalEntity()->status, ['ACTIVE', 'SUSPENDED'], true)) {
            return Response::denyWithStatus(404);
        }

        // Check that employee role with such ID exists in the system (is_active = true)
        if (!$employeeRole->isActive) {
            return Response::denyWithStatus(404);
        }

        // Only ACTIVE employee role can be deactivated
        if ($employeeRole->status !== Status::ACTIVE) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }
}
