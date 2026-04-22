<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Enums\Status;
use App\Enums\User\Role;
use App\Models\LegalEntity;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Auth;

class LegalEntityPolicy
{
    /**
     * Determine if the user has access to the legal entity
     */
    public function access(User $user, LegalEntity $currentEntity): Response
    {
        $legalEntitiesIds = $user->party?->employees()->pluck('legal_entity_id')->toArray();

        $shouldAllow = in_array($currentEntity->id, $legalEntitiesIds, true);

        if (!$shouldAllow) {
            return Response::denyWithStatus(404);
        }

        app()->bind(LegalEntity::class, fn () => $currentEntity);
        app()->alias(LegalEntity::class, 'legalEntity');

        setPermissionsTeamId($currentEntity->id);

        return Response::allow();
    }

    /**
     * User allowed to view the details of legal entities
     */
    public function viewAny(User $user): Response
    {
        if ($user->cannot('legal_entity:read')) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Available for all unconnected users (to the LegalEntity)
     */
    public function limitedAction(User $user): Response
    {
        if ($user->accessibleLegalEntities()->isEmpty()) {
            return Response::allow();
        }

        return Response::denyWithStatus(404);
    }

    /**
     * Determine if the user can create a legal entities
     *
     * @param  User  $user
     * @return Response
     */
    public function create(User $user): Response
    {
        if ($user->hasAllowedRole([Role::OWNER, Role::ADMIN, Role::HR])) {
            return Response::allow();
        }

        return Response::denyWithStatus(404);
    }

    public function edit(User $user, LegalEntity $legalEntity): Response
    {
        // Should belong to the same legal entity
        if (legalEntity()->id !== $legalEntity->id) {
            return Response::denyWithStatus(404);
        }

        if ($user->hasAllowedRole([Role::OWNER]) && Auth::guard('ehealth')->check() && $legalEntity->status !== Status::REORGANIZED->value) {
            return Response::allow();
        }

        return Response::denyWithStatus(404);
    }

    /**
     * Determine if the user can sync data of a legal entities
     *
     * @param  User  $user
     * @return true|Response
     */
    public function sync(User $user, LegalEntity $legalEntity): true|Response
    {
        // Should belong to the same legal entity
        if (legalEntity()->id !== $legalEntity->id) {
            return Response::denyWithStatus(404);
        }

        if ($user->hasAllowedRole([Role::REORGANIZATION_OWNER, Role::OWNER, Role::ADMIN, Role::HR]) && Auth::guard('ehealth')->check()) {
            return Response::allow();
        }

        return Response::denyWithStatus(404);
    }
}
