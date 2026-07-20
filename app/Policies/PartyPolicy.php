<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Relations\Party;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class PartyPolicy
{
    public function viewAnyVerification(User $user): Response
    {
        if ($user->cannot('party_verification:details')) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    public function viewVerification(User $user, Party $party): Response
    {
        if (!$this->partyBelongsToCurrentLegalEntity($party)) {
            return Response::denyWithStatus(404);
        }

        if ($user->cannot('party_verification:details')) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    public function syncVerification(User $user): Response
    {
        if ($user->cannot('party_verification:details')) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    public function updateVerification(User $user, Party $party): Response
    {
        if (!$this->partyBelongsToCurrentLegalEntity($party)) {
            return Response::denyWithStatus(404);
        }

        if ($user->cannot('party_verification:write')) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
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
