<?php

declare(strict_types=1);

namespace App\Services\Employee;

use App\Auth\EHealth\Services\TokenStorage;
use App\Models\Employee\Employee;
use App\Models\Employee\EmployeeRequest;
use App\Models\LegalEntity;
use App\Models\Role as ModelsRole;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\PermissionRegistrar;

/**
 * After one employee in a party is deactivated:
 * find the user by that position's email, drop only this employee's Spatie role
 * (if they have no other APPROVED of that type), and refresh the current
 * session's scopes without logging the user out.
 */
class RevokeDeactivatedEmployeeAccess
{
    public function __construct(
        private TokenStorage $tokenStorage,
        private PermissionRegistrar $permissionRegistrar,
    ) {
    }

    public function handle(Employee $employee, LegalEntity $legalEntity): void
    {
        $relatedUser = $this->userByEmployeeEmail($employee);

        $employee->users()->detach();

        if ($relatedUser instanceof User) {
            $this->revokeThisEmployeeRoleIfOrphaned($employee, $relatedUser, $legalEntity);
        }

        $this->refreshSessionScopesWithoutLogout($employee);
    }

    private function userByEmployeeEmail(Employee $employee): ?User
    {
        $email = $this->employeeEmail($employee);

        if ($email === null) {
            return null;
        }

        return User::query()->where('email', $email)->first();
    }

    private function employeeEmail(Employee $employee): ?string
    {
        if ($employee->userId) {
            $email = User::query()->whereKey($employee->userId)->value('email');

            if (is_string($email) && $email !== '') {
                return $email;
            }
        }

        $pivotEmail = $employee->users()->value('users.email');

        if (is_string($pivotEmail) && $pivotEmail !== '') {
            return $pivotEmail;
        }

        $requestEmail = EmployeeRequest::query()
            ->where('employee_id', $employee->id)
            ->whereNotNull('email')
            ->value('email');

        return is_string($requestEmail) && $requestEmail !== '' ? $requestEmail : null;
    }

    private function revokeThisEmployeeRoleIfOrphaned(
        Employee $employee,
        User $user,
        LegalEntity $legalEntity
    ): void {
        $employeeType = $employee->employeeType;

        if (!is_string($employeeType) || $employeeType === '') {
            return;
        }

        if ($employee->userHasOtherApprovedOfType((int) $user->id, (int) $legalEntity->id)) {
            return;
        }

        $guards = array_keys((array) config('auth.guards'));
        $savedGuard = Auth::getDefaultDriver();

        setPermissionsTeamId($legalEntity->id);

        foreach ($guards as $guard) {
            Auth::shouldUse($guard);

            if ($user->hasRole($employeeType, $guard)) {
                $user->removeRole(ModelsRole::findByName($employeeType, $guard));
            }
        }

        Auth::shouldUse($savedGuard);
        $user->unsetRelation('roles')->unsetRelation('permissions');
    }

    private function refreshSessionScopesWithoutLogout(Employee $employee): void
    {
        $sessionUser = Auth::user();

        if (!$sessionUser instanceof User) {
            return;
        }

        if ((int) $sessionUser->partyId !== (int) $employee->partyId) {
            return;
        }

        $this->permissionRegistrar->forgetCachedPermissions();
        $sessionUser->unsetRelation('roles')->unsetRelation('permissions');
        Auth::setUser($sessionUser->fresh());

        $this->tokenStorage->refreshBearerToken();
    }
}
