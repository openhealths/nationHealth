<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Person\EncounterStatus;
use App\Enums\Status;
use App\Enums\User\Role;
use App\Models\Employee\Employee;
use App\Models\MedicalEvents\Sql\Approval;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;

class EncounterPolicy
{
    /**
     * Determine whether the user can view the encounter.
     */
    public function view(User $user): Response
    {
        if ($user->cannot('encounter:read')) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can create encounter.
     */
    public function create(User $user): Response
    {
        if ($user->cannot('encounter:write')) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can mark the encounter and its package as entered in error.
     * A draft never reached eHealth and an encounter already marked as such cannot be cancelled again,
     * so a finished one is the only one left to cancel. The episode it belongs to has to be ours;
     * an episode without a managing organization came from the short sync and is treated as our own.
     */
    public function cancel(User $user, Encounter $encounter): Response
    {
        if ($user->cannot('encounter:cancel')
            || !$encounter->belongsToCurrentLegalEntity()
            || !$this->signsAsEmployeeAllowedToCancel($user, $encounter)) {
            return Response::denyWithStatus(404);
        }

        return $encounter->status === EncounterStatus::FINISHED
            ? Response::allow()
            : Response::denyWithStatus(404);
    }

    /**
     * Determine whether the user signs the cancellation as an employee eHealth accepts for it: the performer of
     * the encounter, the holder of an approval that still grants write access to it, or a medical administrator.
     * The signature carries the tax ID of the user's party, so the employees of that party are the ones the
     * signature resolves to, and only the ones still employed count.
     */
    private function signsAsEmployeeAllowedToCancel(User $user, Encounter $encounter): bool
    {
        $employees = Employee::wherePartyId($user->partyId)
            ->whereLegalEntityId(legalEntity()->id)
            ->whereStatus(Status::APPROVED)
            ->whereIsActive(true)
            ->get(['uuid', 'employee_type']);

        if ($employees->contains('employeeType', Role::MED_ADMIN->value)) {
            return true;
        }

        $employeeIds = $employees->pluck('uuid');

        if ($employeeIds->contains($encounter->performer?->value)) {
            return true;
        }

        return Approval::grantingWriteAccessTo($encounter->uuid)
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->isVerified()
            ->whereHas(
                'grantedTo',
                static fn (Builder $identifier): Builder => $identifier->whereIn('value', $employeeIds)
            )
            ->exists();
    }
}
