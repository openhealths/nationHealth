<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

class ApprovalPolicy
{
    /**
     * Determine whether the user can create an approval request.
     */
    public function create(User $user): Response
    {
        if ($user->cannot('approval:create')) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }
}
