<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

class ServiceRequestRequestPolicy
{
    /**
     * Determine whether the user can search electronic referrals.
     */
    public function searchElectronicReferrals(User $user): Response
    {
        if ($user->cannot('service_request:read')) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }
}