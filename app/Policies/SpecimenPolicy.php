<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Specimen\Status as SpecimenStatus;
use App\Enums\Status;
use App\Enums\User\Role;
use App\Models\Employee\Employee;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;

class SpecimenPolicy
{
    /**
     * Determine whether the user can view the specimen.
     *
     * @param  User  $user
     * @return Response
     */
    public function view(User $user): Response
    {
        if ($user->cannot('specimen:read')) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can create a specimen outside the encounter package.
     *
     * @param  User  $user
     * @return Response
     */
    public function create(User $user): Response
    {
        if ($user->cannot('specimen:write') || legalEntity()->status !== Status::ACTIVE->value) {
            return Response::denyWithStatus(404);
        }

        // The scope alone is held by more roles than may create a specimen outside the encounter package
        if (!$user->hasAllowedRole([Role::ASSISTANT, Role::LABORANT])) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can set the time the specimen was received for processing. The time can be set only once.
     *
     * @param  User  $user
     * @param  array  $specimen
     * @return Response
     */
    public function process(User $user, array $specimen): Response
    {
        if (
            $specimen['status'] !== SpecimenStatus::AVAILABLE->value
            || !empty($specimen['receivedTime'])
            || $user->cannot('specimen:process')
            || legalEntity()->status !== Status::ACTIVE->value
        ) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can mark the specimen as unsatisfactory.
     *
     * @param  User  $user
     * @param  array  $specimen
     * @return Response
     */
    public function reject(User $user, array $specimen): Response
    {
        if (
            $specimen['status'] !== SpecimenStatus::AVAILABLE->value
            || $user->cannot('specimen:reject')
            || legalEntity()->status !== Status::ACTIVE->value
        ) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can mark the specimen as entered in error on its own. Allowed to its author and to a
     * medical administrator of the legal entity that manages it. A specimen recorded in an encounter package is marked
     * as such together with the package instead.
     *
     * @param  User  $user
     * @param  array  $specimen
     * @return Response
     */
    public function cancel(User $user, array $specimen): Response
    {
        $cancellableStatuses = [
            SpecimenStatus::AVAILABLE->value,
            SpecimenStatus::UNSATISFACTORY->value,
            SpecimenStatus::UNAVAILABLE->value
        ];

        if (
            !in_array($specimen['status'], $cancellableStatuses, true)
            || data_get($specimen, 'context.identifier.value') !== null
            || $user->cannot('specimen:cancel')
            || legalEntity()->status !== Status::ACTIVE->value
            || data_get($specimen, 'managingOrganization.identifier.value') !== legalEntity()->uuid
        ) {
            return Response::denyWithStatus(404);
        }

        $isAuthorOrMedicalAdministrator = Employee::wherePartyId($user->partyId)
            ->whereLegalEntityId(legalEntity()->id)
            ->whereStatus(Status::APPROVED)
            ->whereIsActive(true)
            ->where(static fn (Builder $query): Builder => $query
                ->whereUuid(data_get($specimen, 'registeredBy.identifier.value'))
                ->orWhere('employee_type', Role::MED_ADMIN->value))
            ->exists();

        if (!$isAuthorOrMedicalAdministrator) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can mark the specimen as unavailable.
     *
     * @param  User  $user
     * @param  array  $specimen
     * @return Response
     */
    public function invalidate(User $user, array $specimen): Response
    {
        if (
            $specimen['status'] !== SpecimenStatus::AVAILABLE->value
            || $user->cannot('specimen:invalidate')
            || legalEntity()->status !== Status::ACTIVE->value
        ) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }
}
