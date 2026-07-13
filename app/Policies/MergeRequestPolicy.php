<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

class MergeRequestPolicy
{
    /**
     * Determine whether the user can create and process a merge request. Creating, approving, resending the OTP,
     * rejecting and signing a merge request are all guarded by the same eHealth scope.
     */
    public function create(User $user): Response
    {
        if ($user->cannot('merge_request:write')) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can sign a merge request. eHealth guards signing with a dedicated scope.
     */
    public function sign(User $user): Response
    {
        if ($user->cannot('merge_request:sign')) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }
}
