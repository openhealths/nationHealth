<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Status as LegalEntityStatus;
use App\Enums\User\Role;
use App\Models\Declaration;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class DeclarationPolicy
{
    /**
     * Deny every ability when the current legal entity is not eligible to operate with declarations.
     * The legal entity must be active and have a type allowed for declaration requests.
     *
     * @param  User  $user
     * @param  string  $ability
     * @return Response|null
     */
    public function before(User $user, string $ability): ?Response
    {
        $legalEntity = legalEntity();

        if (
            $legalEntity->status !== LegalEntityStatus::ACTIVE->value
            || !in_array($legalEntity->type->name, config('ehealth.declaration_request_legal_entity_types'), true)
        ) {
            return Response::denyWithStatus(404);
        }

        return null;
    }

    /**
     * Determine whether the user can view any declaration.
     */
    public function viewAny(User $user): Response
    {
        if ($user->cannot('declaration:read')) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can view declaration.
     */
    public function view(User $user, Declaration $declaration): Response
    {
        if ($user->cannot('declaration:read')) {
            return Response::denyWithStatus(404);
        }

        if ($user->hasAllowedRole(Role::OWNER) && $declaration->legalEntityId === legalEntity()->id) {
            return Response::allow();
        }

        // Can only view their own
        return $user->party->employees()->whereKey($declaration->employeeId)->exists()
            ? Response::allow()
            : Response::denyWithStatus(404);
    }

    /**
     * Determine whether the user can resign declaration request.
     */
    public function resign(User $user): Response
    {
        if ($user->cannot('declaration_request:sign')) {
            return Response::denyWithStatus(404);
        }

        if (!$user->hasAllowedRole(Role::DOCTOR, true)) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can synchronize all the declarations.
     */
    public function sync(User $user): Response
    {
        if ($user->cannot('declaration:read') || $user->cannot('declaration_request:read') || $user->cannot('person:read')) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }
}
