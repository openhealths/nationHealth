<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Status as LegalEntityStatus;
use App\Enums\User\Role;
use App\Enums\Declaration\Status;
use App\Models\Declaration;
use App\Models\LegalEntity;
use App\Models\Employee\Employee;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class DeclarationPolicy
{
    /**
     * Deny every ability when the current legal entity is not eligible to operate with declarations.
     * The legal entity must be active or reorganized and have a type allowed for declaration requests.
     *
     * @param  User  $user
     * @param  string  $ability
     * @return Response|null
     */
    public function before(User $user, string $ability): ?Response
    {
        $legalEntity = legalEntity();

        if (
            ($legalEntity->status !== LegalEntityStatus::ACTIVE->value && $legalEntity->status !== LegalEntityStatus::REORGANIZED->value)
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
     *
     * Owners, administrators and doctors see all declarations of their legal entity, regardless of status.
     */
    public function view(User $user, Declaration $declaration): Response
    {
        if ($user->cannot('declaration:read')) {
            return Response::denyWithStatus(404);
        }

        $legalEntityWideRoles = [Role::OWNER, Role::REORGANIZATION_OWNER, Role::ADMIN, Role::DOCTOR];

        if ($user->hasAllowedRole($legalEntityWideRoles) && $declaration->legalEntityId === legalEntity()->id) {
            return Response::allow();
        }

        // Outside the current legal entity, a doctor sees only their own ACTIVE declarations in a connected legator.
        return $user->party->employees->contains('id', $declaration->employeeId)
            && (
                $declaration->legalEntityId === legalEntity()->id
                || (
                    $user->hasAllowedRole(Role::DOCTOR)
                    && $declaration->status === Status::ACTIVE
                    && $declaration->reorganizedEmployeeDeclaration()
                        ->hasConnectionTo(legalEntity())
                        ->where('party_id', $user->partyId)
                        ->exists()
                )
            )
            ? Response::allow()
            : Response::denyWithStatus(404);
    }

    /**
     * Determine whether the user can resign declaration request.
     */
    public function resign(User $user, ?Declaration $declaration = null): Response
    {
        if (
            legalEntity()->status !== LegalEntityStatus::ACTIVE->value
            || $user->cannot('declaration_request:write')
            || $user->cannot('declaration_request:approve')
            || $user->cannot('declaration_request:sign')
        ) {
            return Response::denyWithStatus(404);
        }

        if (!$user->hasAllowedRole(Role::DOCTOR, true)) {
            return Response::denyWithStatus(404);
        }

        if (!Employee::forUserInLegalEntity($user, legalEntity())->doctor()->whereHas('division')->exists()) {
            return Response::denyWithStatus(404);
        }

        if ($declaration && (
            $declaration->status !== Status::ACTIVE
            || !$declaration->reorganizedEmployeeDeclaration()
                ->hasConnectionTo(legalEntity())
                ->where('party_id', $user->partyId)
                ->exists()
        )) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can synchronize all the declarations.
     */
    public function sync(User $user): Response
    {
        if ($user->can('declaration:read') && $user->can('declaration_request:read') && $user->can('person:read')) {
            return Response::allow();
        }

        return Response::denyWithStatus(404);
    }
}
