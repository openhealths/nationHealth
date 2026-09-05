<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

class DevicePolicy
{
    /**
     * Determine whether the user can view the device.
     */
    public function view(User $user): Response
    {
        if ($user->cannot('device:read')) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can create device.
     */
    public function create(User $user): Response
    {
        if ($user->cannot('device:write')) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }
}
