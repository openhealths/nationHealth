<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Employee\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Auth;

class ApprovalPolicy
{
    /**
     * Determine whether the user can create an approval request.
     */
    public function create(User $user): Response
    {
        if ($user->cannot('approval:create')) {
            return Response::denyWithStatus(404);
        }

        return $this->roleCanCreateApproval($user, $user->allowedRoles->all())
            ? Response::allow()
            : Response::denyWithStatus(404);
    }

    // Check for whether the user can create an approval request for a specific employee (in case of combined scope permissions)
    public function createForEmployee(User $user, Employee $employee): Response
    {
        if ($user->cannot('approval:create') || !$user->hasAllowedRole($employee->employeeType)) {
            return Response::denyWithStatus(404);
        }

        return $this->roleCanCreateApproval($user, [$employee->employeeType])
            ? Response::allow()
            : Response::denyWithStatus(404);
    }

    protected function roleCanCreateApproval(User $user, array $roleNames): bool
    {
        return $user->roles()
            ->whereIn('name', $roleNames)
            ->where('guard_name', Auth::getDefaultDriver())
            ->whereHas('permissions', static fn (Builder $query): Builder => $query->where('name', 'approval:create'))
            ->exists();
    }

    /**
     * Determine whether the user can synchronize approvals.
     */
    public function sync(User $user): Response
    {
        if ($user->cannot('approval:read')) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }
}
