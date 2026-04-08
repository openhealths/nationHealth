<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CarePlan;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CarePlanPolicy
{
    /**
     * Determine whether the user can view the care plan.
     */
    public function view(User $user, CarePlan $carePlan): Response
    {
        if ($user->cannot('care_plan:read')) {
            return Response::denyWithStatus(404);
        }

        if ((int) $carePlan->legal_entity_id !== (int) legalEntity()->id) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can create care plan.
     */
    public function create(User $user): Response
    {
        if ($user->cannot('care_plan:write')) {
            return Response::deny(__('care-plan.no_permission_create'));
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can update the care plan.
     */
    public function update(User $user, CarePlan $carePlan): Response
    {
        if ($user->cannot('care_plan:write')) {
            return Response::denyWithStatus(404);
        }

        if ((int) $carePlan->legal_entity_id !== (int) legalEntity()->id) {
            return Response::denyWithStatus(404);
        }

        // Only author can edit if it's still NEW? Or check status?
        // For now, follow the general pattern.
        return Response::allow();
    }
}
