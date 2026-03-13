<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

class DrugPolicy
{
    /**
     * Determine if the user can view drugs.
     */
    public function view(User $user): Response
    {
        if ($user->cannot('drugs:read')) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }
}
