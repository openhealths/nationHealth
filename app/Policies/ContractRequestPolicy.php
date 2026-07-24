<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Contracts\ContractRequest;
use App\Models\LegalEntity;
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
     * Capitation contract creation is currently disabled for all legal entity types
     * (including PRIMARY_CARE and OUTPATIENT).
     */
    public function createCapitation(User $user): Response
    {
        return Response::denyWithStatus(404);
    }

    /**
     * Reimbursement contract creation is allowed only for pharmacy legal entities.
     */
    public function createReimbursement(User $user): Response
    {
        if (!$user->can('contract_request:create')) {
            return Response::denyWithStatus(404);
        }

        $legalEntity = legalEntity();

        // PRIMARY_CARE (ПМД) and other non-pharmacy LEs must not see/submit reimbursement contracts.
        if ($legalEntity === null || $legalEntity->type?->name !== LegalEntity::TYPE_PHARMACY) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can approve the contract request from MSP side.
     */
    public function approve(User $user, ContractRequest $contractRequest): Response
    {
        if ($contractRequest->contractor_legal_entity_id !== legalEntity()->uuid) {
            return Response::denyWithStatus(404);
        }

        $status = $contractRequest->status instanceof \App\Enums\Contract\Status
            ? $contractRequest->status
            : \App\Enums\Contract\Status::tryFrom((string) $contractRequest->status);

        if ($status !== \App\Enums\Contract\Status::APPROVED) {
            return Response::deny(__('contracts.policy.approve_denied'));
        }

        if (($contractRequest->type === 'REIMBURSEMENT' || $contractRequest->type === \App\Enums\Contract\Type::REIMBURSEMENT->value)
            && $user->hasAllowedRole([\App\Enums\User\Role::OWNER])
            && legalEntity()->type->name === \App\Models\LegalEntity::TYPE_PRIMARY_CARE
        ) {
            return Response::deny(__('contracts.policy.approve_denied'));
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

        $status = $contractRequest->status instanceof \App\Enums\Contract\Status
            ? $contractRequest->status
            : \App\Enums\Contract\Status::tryFrom((string) $contractRequest->status);

        if ($status !== \App\Enums\Contract\Status::NHS_SIGNED) {
            return Response::deny(__('contracts.policy.sign_denied'));
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
