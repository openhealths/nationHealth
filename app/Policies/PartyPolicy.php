<?php

declare(strict_types=1);

namespace App\Policies;

use App\Auth\EHealth\Services\TokenStorage;
use App\Enums\Status;
use App\Enums\User\Role;
use App\Models\Relations\Party;
use App\Models\User;
use App\Services\Party\PartyVerificationBulkAccess;
use Illuminate\Auth\Access\Response;

class PartyPolicy
{
    public function viewAnyVerification(User $user): Response
    {
        if (!$this->userHasTv323Role($user)) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    public function viewVerification(User $user, Party $party): Response
    {
        if (!$this->partyBelongsToCurrentLegalEntity($party)) {
            return Response::denyWithStatus(404);
        }

        if (!$this->userHasTv323Role($user)) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    public function syncVerification(User $user): Response
    {
        // Bulk/details sync is gated by OAuth token scopes only — not by employee role.
        // ADMIN without party_verification:read never hits getMany (uses details or is denied).
        $scopes = app(TokenStorage::class)->getTokenScopes();

        if (!PartyVerificationBulkAccess::canManualSync($scopes)) {
            return Response::deny(__('party_verification.messages.sync_requires_details_or_read'));
        }

        return Response::allow();
    }

    public function updateVerification(User $user, Party $party): Response
    {
        if (!$this->partyBelongsToCurrentLegalEntity($party)) {
            return Response::denyWithStatus(404);
        }

        if (!$this->userHasTv323Role($user)) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * TZ 3.23 roles: OWNER, HR, ADMIN, PHARMACY_OWNER.
     * Prefer employee_type / Spatie hasRole — avoids hasAllowedRole() which
     * queries Role permissions and breaks when the Permission model is Spatie's.
     */
    private function userHasTv323Role(User $user): bool
    {
        $roles = [
            Role::ADMIN->value,
            Role::HR->value,
            Role::OWNER->value,
            Role::PHARMACY_OWNER->value,
        ];

        if ($user->hasRole($roles)) {
            return true;
        }

        $legalEntity = legalEntity();

        if ($legalEntity === null) {
            return false;
        }

        return $user->employees()
            ->where('legal_entity_id', $legalEntity->id)
            ->whereIn('employee_type', $roles)
            ->where('status', Status::APPROVED->value)
            ->exists();
    }

    private function partyBelongsToCurrentLegalEntity(Party $party): bool
    {
        $legalEntity = legalEntity();

        if ($legalEntity === null) {
            return false;
        }

        return $party->employees()
            ->where('legal_entity_id', $legalEntity->id)
            ->exists();
    }
}
