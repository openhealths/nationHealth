<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\User\Role;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ConfidantPersonPolicy
{
    /**
     * Determine whether the user can create confidant person relationship request.
     */
    public function create(User $user): Response
    {
        if ($user->cannot('confidant_person_relationship_request:write')) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can view the confidant person relationships of the patient.
     */
    public function view(User $user): Response
    {
        // The scope alone is held by more roles than may view the confidant persons of a patient
        if ($user->cannot('confidant_person_relationship:read')
            || !$user->hasAllowedRole([Role::DOCTOR, Role::SPECIALIST, Role::ASSISTANT])) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can view the confidant person relationship requests of the patient.
     */
    public function viewRequests(User $user): Response
    {
        if ($user->cannot('confidant_person_relationship_request:read')) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }
}
