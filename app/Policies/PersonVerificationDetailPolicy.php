<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Person\VerificationSource;
use App\Enums\Person\VerificationStatus;
use App\Enums\User\Role;
use App\Models\Person\Person;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class PersonVerificationDetailPolicy
{
    /**
     * Determine whether the user can view the person verification list.
     */
    public function viewAny(User $user): Response
    {
        if ($user->cannot('person_verification:read')) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can view the person verification details.
     */
    public function view(User $user): Response
    {
        if ($user->cannot('person_verification:details')) {
            return Response::denyWithStatus(404);
        }

        $allowedRoles = [
            Role::OWNER,
            Role::ADMIN,
            Role::SPECIALIST,
            Role::DOCTOR,
            Role::RECEPTIONIST,
            Role::ASSISTANT,
            Role::MED_COORDINATOR
        ];

        if (!$user->hasAllowedRole($allowedRoles)) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can confirm or refute the fact of the person death.
     */
    public function update(User $user, Person $person): Response
    {
        if ($user->cannot('person_verification:write')) {
            return Response::denyWithStatus(404);
        }

        $employee = $user->employees()
            ->whereLegalEntityId(legalEntity()->id)
            ->whereEmployeeType(Role::DOCTOR)
            ->first();

        if ($employee === null) {
            return Response::denyWithStatus(404);
        }

        $hasActiveDeclaration = $person->declarations()
            ->active()
            ->whereEmployeeId($employee->id)
            ->exists();

        if (!$hasActiveDeclaration) {
            return Response::denyWithStatus(404);
        }

        $deathVerification = $person->verificationDetails()
            ->whereSource(VerificationSource::DRACS_DEATH->value)
            ->first();

        if ($deathVerification?->verificationStatus !== VerificationStatus::NOT_VERIFIED) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }
}
