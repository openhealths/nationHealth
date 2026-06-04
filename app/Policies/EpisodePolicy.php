<?php

declare(strict_types=1);

namespace App\Policies;

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
}
