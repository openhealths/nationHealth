<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Enums\Status;
use App\Models\HealthcareService;
use Illuminate\Auth\Access\Response;

class HealthcareServicePolicy
{
    /**
     * User allowed to view the list of healthcare services
     */
    public function viewAny(User $user): Response
    {
        if ($user->cannot('healthcare_service:read')) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * User allowed to synchronize healthcare services.
     */
    public function sync(User $user): Response
    {
        if ($user->cannot('healthcare_service:write') && $user->cannot('healthcare_service:read')) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * User allowed to view exactly that healthcare service.
     */
    public function view(User $user, HealthcareService $healthcareService): Response
    {
        // Should belong to the same legal entity
        if (legalEntity()->id !== $healthcareService->legalEntityId) {
            return Response::denyWithStatus(404);
        }

        if ($user->cannot('healthcare_service:read')) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * User allow to create the healthcare services
     */
    public function create(User $user): Response
    {
        if ($user->cannot('healthcare_service:write')) {
            return Response::denyWithStatus(404);
        }

        // Check that legal entity type exists in HEALTHCARE_SERVICE_LEGAL_ENTITIES_ALLOWED_TYPES chart parameter.
        $types = dictionary()->basics()->byName('LEGAL_ENTITY_TYPE_V2')->asCodeDescription()->keys()->toArray();
        if (!in_array(legalEntity()->type->name, $types, true)) {
            return Response::denyWithStatus(404);
        }

        // The healthcare service can be created for legal entities with the following statuses.
        if (!in_array(legalEntity()->status, ['ACTIVE', 'SUSPENDED'], true)) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * User allow to delete the healthcare services draft record fromm the DB
     */
    public function delete(User $user, HealthcareService $healthcareService): Response
    {
        // Should belong to the same legal entity
        if (legalEntity()->id !== $healthcareService->legalEntityId) {
            return Response::denyWithStatus(404);
        }

        // Only HealthcareServices with DRAFT status can be deleted
        if ($healthcareService->status !== Status::DRAFT) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can edit the model.
     */
    public function edit(User $user, HealthcareService $healthcareService): Response
    {
        // Should belong to the same legal entity
        if ($healthcareService->legalEntityId !== legalEntity()->id) {
            return Response::denyWithStatus(404);
        }

        if ($user->cannot('healthcare_service:write')) {
            return Response::denyWithStatus(404);
        }

        // Only draft can be edited
        if ($healthcareService->status !== Status::DRAFT) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, HealthcareService $healthcareService): Response
    {
        // Should belong to the same legal entity
        if ($healthcareService->legalEntityId !== legalEntity()->id) {
            return Response::denyWithStatus(404);
        }

        if ($user->cannot('healthcare_service:write')) {
            return Response::denyWithStatus(404);
        }

        //  Check that legal entity is in 'ACTIVE' or 'SUSPENDED' status
        if (!in_array(legalEntity()->status, ['ACTIVE', 'SUSPENDED'], true)) {
            return Response::denyWithStatus(404);
        }

        // Only active healthcare services can be updated
        if ($healthcareService->status !== Status::ACTIVE) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can activate the healthcare service.
     */
    public function activate(User $user, HealthcareService $healthcareService): Response
    {
        if ($user->cannot('healthcare_service:write')) {
            return Response::denyWithStatus(404);
        }

        // Some healthcare services cannot be activated
        if ($healthcareService->status === Status::ACTIVE || $healthcareService->status === Status::DRAFT) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can deactivate the healthcare service.
     */
    public function deactivate(User $user, HealthcareService $healthcareService): Response
    {
        if ($user->cannot('healthcare_service:write')) {
            return Response::denyWithStatus(404);
        }

        // Some healthcare services cannot be deactivated
        if ($healthcareService->status === Status::INACTIVE || $healthcareService->status === Status::DRAFT) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }
}
