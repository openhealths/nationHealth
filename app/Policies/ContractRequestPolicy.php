<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Contracts\ContractRequest;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ContractRequestPolicy
{
    /**
     * Determine whether the user can view any contract requests.
     */
    public function viewAny(User $user): Response
    {
        if ($user->can('contract_request:read') || $user->can('contract_request:create')) {
            return Response::allow();
        }

        return Response::deny();
    }

    /**
     * Determine whether the user can view the specific contract request.
     */
    public function view(User $user, ContractRequest $contractRequest): Response
    {
        // Strict Ownership Check via UUID
        if ($contractRequest->contractor_legal_entity_id !== legalEntity()->uuid) {
            return Response::denyWithStatus(404);
        }

        if ($user->can('contract_request:read') || $user->can('contract_request:create')) {
            return Response::allow();
        }

        return Response::deny(__('contracts.policy.view_denied'));
    }

    /**
     * Determine whether the user can initialize a contract request.
     */
    public function initialize(User $user): Response
    {
        return $user->can('contract_request:create')
            ? Response::allow()
            : Response::denyWithStatus(404);
    }

    /**
     * Determine whether the user can create a contract request.
     */
    public function create(User $user): Response
    {
        return $user->can('contract_request:create')
            ? Response::allow()
            : Response::denyWithStatus(404);
    }

    /**
     * Determine whether the user can approve the contract request from MSP side.
     */
    public function approve(User $user, ContractRequest $contractRequest): Response
    {
        if ($contractRequest->contractor_legal_entity_id !== legalEntity()->uuid) {
            return Response::denyWithStatus(404);
        }

        return $user->can('contract_request:approve')
            ? Response::allow()
            : Response::deny(__('contracts.policy.approve_denied'));
    }

    /**
     * Determine whether the user can sign the contract request from MSP side.
     */
    public function sign(User $user, ContractRequest $contractRequest): Response
    {
        if ($contractRequest->contractor_legal_entity_id !== legalEntity()->uuid) {
            return Response::denyWithStatus(404);
        }

        return $user->can('contract_request:sign')
            ? Response::allow()
            : Response::deny(__('contracts.policy.sign_denied'));
    }

    /**
     * Determine whether the user can synchronize contract requests.
     */
    public function sync(User $user): Response
    {
        if ($user->can('contract_request:read') || $user->can('contract_request:create')) {
            return Response::allow();
        }

        return Response::denyWithStatus(404);
    }
}
