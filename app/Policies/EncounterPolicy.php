<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\JobStatus;
use App\Enums\Person\EncounterStatus;
use App\Enums\Status;
use App\Enums\User\Role;
use App\Models\Declaration;
use App\Models\Employee\Employee;
use App\Models\MedicalEvents\Sql\Approval;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;

class EncounterPolicy
{
    /**
     * Determine whether the user can view the encounter of the patient: one of the encounters the user may read,
     * one based on a care plan with an approval on it or managed by the current legal entity, or a draft performed
     * by an employee of the current legal entity, which exists here only and is not known to eHealth yet.
     */
    public function view(User $user, Encounter $encounter, Person|Preperson $patient): Response
    {
        if ($user->cannot('encounter:read')) {
            return Response::denyWithStatus(404);
        }

        $hasPatientAccess = Declaration::accessGrantedTo($user, $patient, legalEntity())->exists()
            || Approval::accessGrantedTo($user, $patient, legalEntity())->exists();

        $isReadable = Encounter::readableFor($patient, $hasPatientAccess)->whereKey($encounter->id)->exists()
            || Encounter::basedOnApprovedCarePlan($patient, $user, legalEntity())->whereKey($encounter->id)->exists()
            || Encounter::basedOnCarePlanOfLegalEntity($patient)->whereKey($encounter->id)->exists()
            || $this->isOwnDraft($encounter, $patient);

        return $isReadable ? Response::allow() : Response::denyWithStatus(404);
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
     * Determine whether the user can change the encounter package and sign it: a draft that is not waiting for eHealth.
     */
    public function update(User $user, Encounter $encounter): Response
    {
        if ($user->cannot('encounter:write')) {
            return Response::denyWithStatus(404);
        }

        if ($encounter->status !== EncounterStatus::DRAFT
            || $encounter->ehealthLinks()->latest('id')->value('status') === JobStatus::PENDING->value) {
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
     * Determine whether the encounter is a draft of the patient performed by an employee of the current legal entity.
     * Its episode may come from the short sync without a managing organization, which the access rules cannot tell
     * apart, so the author would otherwise lose their own draft.
     */
    private function isOwnDraft(Encounter $encounter, Person|Preperson $patient): bool
    {
        $performerId = $encounter->performer?->value;

        return $encounter->status === EncounterStatus::DRAFT
            && $performerId !== null
            && Encounter::forPatient($patient)->whereKey($encounter->id)->exists()
            && Employee::whereLegalEntityId(legalEntity()->id)->whereUuid($performerId)->exists();
    }

    /**
     * Determine whether the user signs the cancellation as an employee eHealth accepts for it: the performer of
     * the encounter, the holder of an approval that still grants write access to it, or a medical administrator.
     * The signature carries the tax ID of the user's party, so the employees of that party are the ones the
     * signature resolves to, and only the ones still employed count. An assistant cancels what they recorded
     * themselves and nothing else, so neither an approval nor an administrator role opens the way for them.
     */
    private function signsAsEmployeeAllowedToCancel(User $user, Encounter $encounter): bool
    {
        $employees = Employee::wherePartyId($user->partyId)
            ->whereLegalEntityId(legalEntity()->id)
            ->whereStatus(Status::APPROVED)
            ->whereIsActive(true)
            ->get(['uuid', 'employee_type']);

        $isAssistantOnly = $user->isAssistantOnly();

        if (!$isAssistantOnly && $employees->contains('employeeType', Role::MED_ADMIN->value)) {
            return true;
        }

        $employeeIds = $employees->pluck('uuid');

        if ($employeeIds->contains($encounter->performer?->value)) {
            return true;
        }

        if ($isAssistantOnly) {
            return false;
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
