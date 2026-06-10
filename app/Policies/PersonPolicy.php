<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

class PersonPolicy
{
    /**
     * Determine whether the user can view the person request.
     */
    public function viewAny(User $user): Response
    {
        if ($user->cannot('person:read')) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can view the patient data.
     */
    public function view(User $user): Response
    {
        if ($user->cannot('patient_summary:read')) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can sync the person data.
     */
    public function syncPersonData(User $user): Response
    {
        if ($user->can('personal_data:read') && $user->can('approval:create')) {
            return Response::allow();
        }

        return Response::denyWithStatus(404);
    }
}
