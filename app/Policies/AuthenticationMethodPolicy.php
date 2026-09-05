<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

class AuthenticationMethodPolicy
{
    /**
     * Determine whether the user can view the list of patient authentication methods.
     */
    public function view(User $user): Response
    {
        if ($user->cannot('authentication_method_request:write')) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can create, update or deactivate patient authentication methods.
     */
    public function update(User $user): Response
    {
        if ($user->cannot('authentication_method_request:write')) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }
}
