<?php

declare(strict_types=1);

namespace App\Policies;

use App\Auth\EHealth\Services\TokenStorage;
use App\Enums\Declaration\Status;
use App\Enums\Status as LegalEntityStatus;
use App\Enums\User\Role;
use App\Models\Declaration;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class DeclarationPolicy
{
    // The full chain also reads declaration requests and patient authentication methods.
    protected const array SYNC_SCOPES = [
        'declaration:read',
        'declaration_request:read',
        'person:read'
    ];

    /**
     * Deny every ability when the current legal entity is not eligible to operate with declarations.
     * The legal entity must be active or reorganized and have a type allowed for declaration requests.
     *
     * Laravel passes the model or class as $subject before additional context arguments.
     * This hook does not inspect $subject, but accepts it so $legalEntity receives the correct positional argument.
     * The class name is removed only before the ability method is called, not before this hook.
     *
     * @param  User  $user
     * @param  string  $ability
     * @return Response|null
     */
    public function before(
        User $user,
        string $ability,
        Declaration|string|null $subject = null,
        ?LegalEntity $legalEntity = null
    ): ?Response {
        $legalEntity ??= legalEntity();

        if (
            !$legalEntity
            || ($legalEntity->status !== LegalEntityStatus::ACTIVE->value && $legalEntity->status !== LegalEntityStatus::REORGANIZED->value)
            || !\in_array($legalEntity->type->name, config('ehealth.declaration_request.legal_entity_types'), true)
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
     *
     * Laravel forwards the legal entity context to both before() and this method.
     * Queued calls supply granted scopes explicitly. Web calls require both the current
     * session's token scopes and the user's permissions.
     */
    public function sync(User $user, ?LegalEntity $legalEntity = null, ?array $tokenScopes = null): Response
    {
        $grantedScopes = $tokenScopes ?? app(TokenStorage::class)->getTokenScopes();

        foreach (self::SYNC_SCOPES as $scope) {
            if (!\in_array($scope, $grantedScopes, true)
                || ($tokenScopes === null && $user->cannot($scope))) {
                return Response::denyWithStatus(404);
            }
        }

        return Response::allow();
    }
}
