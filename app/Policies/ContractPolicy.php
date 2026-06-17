<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Contracts\Contract;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ContractPolicy
{
    /**
     * Determine whether the user can view any contracts.
     */
    public function viewAny(User $user): Response
    {
        if ($user->can('contract_request:read') || $user->can('contract:read')) {
            return Response::allow();
        }

        return Response::denyWithStatus(404);
    }

    /**
     * Determine whether the user can view a specific contract.
     */
    public function view(User $user, Contract $contract): Response
    {
        // Strict check: Contract must belong to the current Legal Entity
        if ((int) $contract->legal_entity_id !== (int) legalEntity()->id) {
            return Response::denyWithStatus(404);
        }

        if ($user->can('contract_request:read') || $user->can('contract:read')) {
            return Response::allow();
        }

        return Response::deny(__('contracts.policy.view_denied'));
    }

    /**
     * Determine whether the user can create contracts.
     */
    public function create(User $user): Response
    {
        return $user->can('contract_request:create')
            ? Response::allow()
            : Response::denyWithStatus(404);
    }

    /**
     * Determine whether the user can synchronize contracts with eHealth.
     */
    public function sync(User $user): Response
    {
        if ($user->can('contract:read') || $user->can('contract_request:read')) {
            return Response::allow();
        }

        return Response::deny(__('contracts.policy.sync_denied'));
    }
}
