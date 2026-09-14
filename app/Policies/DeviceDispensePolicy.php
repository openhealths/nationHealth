<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

class DeviceDispensePolicy
{
    /**
     * Determine whether the user can view device dispenses.
     */
    public function view(User $user): Response
    {
        if ($user->cannot('device_dispense:read')) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can record a device dispense.
     *
     * eHealth grants `device_dispense:write` to the roles and legal entity types allowed to hand devices
     * over, so the scope is what decides it here rather than a legal entity type checked by name.
     */
    public function create(User $user): Response
    {
        if ($user->cannot('device_dispense:write')) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }
}
