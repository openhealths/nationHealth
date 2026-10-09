<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

class DeviceDispensePolicy
{
    /**
     * Determine whether the user can view device dispenses.
     *
     * @param  User  $user
     * @return Response
     */
    public function viewAny(User $user): Response
    {
        if ($user->cannot('device_dispense:read')) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can view a device dispense.
     *
     * @param  User  $user
     * @return Response
     */
    public function view(User $user): Response
    {
        if ($user->cannot('device_dispense:read')) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }
}