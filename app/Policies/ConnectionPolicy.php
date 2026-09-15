<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Enums\User\Role;
use App\Models\Connection;
use Illuminate\Auth\Access\Response;
use App\Enums\LegalEntity\ConnectionStatus;

class ConnectionPolicy
{
    /**
     * Determine whether the user can view any connection.
     */
    public function viewAny(User $user): Response
    {
        if ($user->can('connection:read') && $user->can('client:read')) {
            return Response::allow();
        }

        return Response::denyWithStatus(404);
    }

    /**
     * Determine whether the user can view the connection.
     */
    public function view(User $user, Connection $connection): Response
    {
        if (
            $user->can('connection:read') &&
            $user->can('client:read') &&
            $connection->legalEntityId === legalEntity()->id
        ) {
            return Response::allow();
        }

        return Response::denyWithStatus(404);
    }

    /**
     * Determine whether the user can update any connection.
     */
    public function updateConnection(User $user, Connection $connection): Response
    {
        if (
            $user->can('connection:write') &&
            $connection->legalEntityId === legalEntity()->id &&
            $connection->status === ConnectionStatus::ACTIVE->value
        ) {
            return Response::allow();
        }

        return Response::denyWithStatus(404);
    }

    /**
     * Determine whether the user can delete any connection.
     */
    public function deleteConnection(User $user, Connection $connection): Response
    {
        if (
            $user->hasAllowedRole([Role::OWNER]) &&
            $user->can('connection:delete') &&
            $connection->legalEntityId === legalEntity()->id &&
            $connection->status === ConnectionStatus::ACTIVE->value
        ) {
            return Response::allow();
        }

        return Response::denyWithStatus(404);
    }

    /**
     * Determine whether the user can update the connection secret.
     */
    public function updateSecret(User $user, Connection $connection): Response
    {
        if (
            $user->hasAllowedRole([Role::OWNER]) &&
            $user->can('connection:write') &&
            $user->can('connection:refresh_secret') &&
            $connection->legalEntityId === legalEntity()->id &&
            $connection->status === ConnectionStatus::ACTIVE->value
        ) {
            return Response::allow();
        }

        return Response::denyWithStatus(404);
    }

    /**
     * Determine whether the user can synchronize all the connections.
     */
    public function sync(User $user): Response
    {
        if (
            $user->can('connection:read') &&
            $user->can('client:read')
        ) {
            return Response::allow();
        }

        return Response::denyWithStatus(404);
    }

    /**
     * Determine whether the user can synchronize a specific connection
     */
    public function syncConnection(User $user, Connection $connection): Response
    {
        if (
            $user->can('connection:read') &&
            $user->can('client:read') &&
            $connection->status === ConnectionStatus::ACTIVE->value
        ) {
            return Response::allow();
        }

        return Response::denyWithStatus(404);
    }
}
