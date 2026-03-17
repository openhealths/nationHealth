<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use Illuminate\Support\Carbon;
use App\Events\EhealthUserVerified;
use App\Repositories\Repository;
use App\Services\UserRoleSyncService;

class SyncUserRolesAfterVerification
{
    /**
     * Synchronizes a user's roles based on their employee positions after
     * their identity has been successfully verified and linked to a Party.
     *
     * This listener is triggered by the UserVerifiedAndLinked event, ensuring that
     * the user receives the complete and correct set of roles corresponding to all
     * their official positions within a specific legal entity.
     */
    public function handle(EhealthUserVerified $event): void
    {
        $user = $event->user;
        $legalEntityId = $event->legalEntityId;

        Repository::party()->syncUserEmployeesEndRoles($user->party, $event->legalEntityId);
    }
}
