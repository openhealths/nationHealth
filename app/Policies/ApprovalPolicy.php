<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MedicalEvents\Sql\Approval;
use App\Models\User;
use App\Models\LegalEntity;
use App\Models\Employee\Employee;
use App\Models\CarePlan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Auth;
use App\Models\Person\Person;
use App\Enums\User\Role;

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

    public function view(User $user, Approval $approval): Response
    {
        if ($user->cannot('approval:read')) {
            return Response::denyWithStatus(404);
        }

        $approval->loadMissing([
            'grantedTo',
            'createdBy',
            'approvable' => static fn (MorphTo $morphTo) => $morphTo->morphWith([
                CarePlan::class => ['person'],
            ]),
        ]);

        $employee = $user->activeDoctorEmployee();
        $isRecipientOrAuthor = $employee && ($approval->grantedTo?->value === $employee->uuid || $approval->createdBy?->value === $employee->uuid);

        return $isRecipientOrAuthor || $this->hasActiveDeclaration($user, $this->patient($approval), legalEntity()) ? Response::allow() : Response::denyWithStatus(404);
    }

    public function viewAllForPatient(User $user, Person $patient): Response
    {
        return $user->can('approval:read') && $this->hasActiveDeclaration($user, $patient, legalEntity()) ? Response::allow() : Response::denyWithStatus(404);
    }

    public function cancel(User $user, Approval $approval, LegalEntity $legalEntity): Response
    {
        if ($user->cannot('approval:cancel') || ($approval->expiresAt !== null && !$approval->expiresAt->isFuture())) {
            return Response::denyWithStatus(404);
        }

        $approval->loadMissing('grantedTo');
        if (Employee::forUserInLegalEntity($user, $legalEntity)
            ->whereIn('employee_type', [Role::DOCTOR, Role::SPECIALIST, Role::MED_COORDINATOR])
            ->where('uuid', $approval->grantedTo?->value)
            ->exists()) {
            return Response::allow();
        }

        return $this->hasActiveDeclaration($user, $this->patient($approval), $legalEntity) ? Response::allow() : Response::denyWithStatus(404);
    }

    protected function patient(Approval $approval): Person
    {
        return $approval->approvable instanceof Person ? $approval->approvable : $approval->approvable->person;
    }

    protected function hasActiveDeclaration(User $user, Person $patient, LegalEntity $legalEntity): bool
    {
        return $patient->declarations()
            ->active()
            ->whereIn('employee_id', Employee::forUserInLegalEntity($user, $legalEntity)->doctor()->select('id'))
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
