<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Person\Status;
use App\Enums\Status as LegalEntityStatus;
use App\Models\Person\PersonRequest;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class PersonRequestPolicy
{
    /**
     * User allowed to view the list of person requests
     */
    public function viewAny(User $user): Response
    {
        if ($user->cannot('person_request:read')) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can view the person request.
     */
    public function view(User $user): Response
    {
        if ($user->cannot('person_request:read')) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can create person request.
     */
    public function create(User $user): Response
    {
        if ($user->cannot('person_request:write')) {
            return Response::denyWithStatus(404);
        }

        $legalEntity = legalEntity();

        // client_id must belong to an active legal entity
        if ($legalEntity->status !== LegalEntityStatus::ACTIVE->value || !$legalEntity->isActive) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can reject person request.
     */
    public function reject(User $user, PersonRequest $personRequest): Response
    {
        if ($user->cannot('person_request:write')) {
            return Response::denyWithStatus(404);
        }

        // New and Approved person request can be rejected
        if (!in_array($personRequest->status, [Status::NEW, Status::APPROVED], true)) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }
}
