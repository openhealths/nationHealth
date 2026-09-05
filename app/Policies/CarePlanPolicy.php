<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CarePlanStatus;
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

        if ((int) $carePlan->legalEntityId !== (int) legalEntity()?->id) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Sign, cancel, complete, or issue requests on an already stored plan.
     * Distinct from update(): eHealth keeps a signed plan in `new` until the first activity.
     */
    public function manage(User $user, CarePlan $carePlan): Response
    {
        if ($user->cannot('care_plan:write')) {
            return Response::denyWithStatus(404);
        }

        if ((int) $carePlan->legalEntityId !== (int) legalEntity()?->id) {
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

        if ((int) $carePlan->legalEntityId !== (int) legalEntity()?->id) {
            return Response::denyWithStatus(404);
        }

        $status = CarePlanStatus::fromStored($carePlan->status);
        if (!in_array($status, [CarePlanStatus::DRAFT, CarePlanStatus::PENDING], true)) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }
}
