<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Episode\Status;
use App\Models\Employee\Employee;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class EpisodePolicy
{
    /**
     * Determine whether the user can view the episode.
     */
    public function view(User $user): Response
    {
        if ($user->cannot('episode:read')) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can create an episode.
     */
    public function create(User $user): Response
    {
        if ($user->cannot('episode:write')) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can edit the episode. Closed and cancelled episodes are read-only.
     * An episode without a managing organization came from the short sync and is treated as our own.
     */
    public function update(User $user, Episode $episode): Response
    {
        $managingOrganization = $episode->managingOrganization?->value;

        if ($user->cannot('episode:write')
            || ($managingOrganization !== null && $managingOrganization !== legalEntity()->uuid)
            || !Employee::managedByUser($user, $episode->careManager?->value)) {
            return Response::denyWithStatus(404);
        }

        return in_array($episode->status, [Status::DRAFT, Status::ACTIVE], true)
            ? Response::allow()
            : Response::denyWithStatus(404);
    }

    /**
     * Determine whether the user can close the episode. A closed or cancelled episode cannot be closed again,
     * and a draft never reached eHealth, so an active episode is the only one left to close.
     * An episode without a managing organization came from the short sync and is treated as our own.
     */
    public function close(User $user, Episode $episode): Response
    {
        $managingOrganization = $episode->managingOrganization?->value;

        if ($user->cannot('episode:write')
            || ($managingOrganization !== null && $managingOrganization !== legalEntity()->uuid)
            || !Employee::managedByUser($user, $episode->careManager?->value)) {
            return Response::denyWithStatus(404);
        }

        return $episode->status === Status::ACTIVE
            ? Response::allow()
            : Response::denyWithStatus(404);
    }

    /**
     * Determine whether the user can mark the episode as entered in error.
     * An episode already marked as such cannot be cancelled again, and a draft is deleted instead.
     * An episode without a managing organization came from the short sync and is treated as our own.
     */
    public function cancel(User $user, Episode $episode): Response
    {
        $managingOrganization = $episode->managingOrganization?->value;

        if ($user->cannot('episode:write')
            || ($managingOrganization !== null && $managingOrganization !== legalEntity()->uuid)
            || !Employee::managedByUser($user, $episode->careManager?->value)) {
            return Response::denyWithStatus(404);
        }

        return in_array($episode->status, [Status::ACTIVE, Status::CLOSED], true)
            ? Response::allow()
            : Response::denyWithStatus(404);
    }

    /**
     * Determine whether the user can delete the episode. Only a draft that never reached eHealth can be deleted.
     * An episode without a managing organization came from the short sync and is treated as our own.
     */
    public function delete(User $user, Episode $episode): Response
    {
        $managingOrganization = $episode->managingOrganization?->value;

        if ($user->cannot('episode:write')
            || ($managingOrganization !== null && $managingOrganization !== legalEntity()->uuid)) {
            return Response::denyWithStatus(404);
        }

        return $episode->status === Status::DRAFT
            ? Response::allow()
            : Response::denyWithStatus(404);
    }
}
