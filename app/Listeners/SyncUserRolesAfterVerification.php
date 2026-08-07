<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\LegalEntity;
use App\Repositories\Repository;
use App\Events\EhealthUserVerified;

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

        $legalEntity = LegalEntity::find($event->legalEntityId);

        if (!$legalEntity || !$user->party) {
            return;
        }

        setPermissionsTeamId($legalEntity->id);

        // Positions synced from eHealth have no owner yet; bind them before assigning roles
        Repository::employee()->bindOwnerlessEmployeesToUsers($legalEntity);

        Repository::party()->syncUserEmployeesAndRoles($user->party->fresh(), $legalEntity);
    }
}
