<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LegalEntity;
use App\Models\License;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class LicensePolicy
{
    /**
     * User allowed to synchronize licenses.
     */
    public function sync(User $user): Response
    {
        if ($user->cannot('license:read') && $user->cannot('license:write')) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * User can view the list of licenses
     */
    public function viewAny(User $user): Response
    {
        if ($user->cannot('license:read')) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * User can read the license
     */
    public function view(User $user, License $currentLicense, ?LegalEntity $currentLegalEntity = null): Response
    {
        if (is_null($currentLegalEntity)) {
            $currentLegalEntity = legalEntity();
        }

        // Should belong to the same legal entity
        if ($currentLicense->legalEntityId !== $currentLegalEntity->id) {
            return Response::denyWithStatus(404);
        }

        if ($user->cannot('license:details')) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * User can create the license
     */
    public function create(User $user): Response
    {
        if ($user->cannot('license:write')) {
            return Response::denyWithStatus(404);
        }

        // The license can be created only for legal entity types that have additional license types configured,
        // based on LEGAL_ENTITY_<LEGAL_ENTITY_TYPE>_ADDITIONAL_LICENSE_TYPES
        // see: https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/17092870145/Legal+Entities+configurable+parameters
        if (empty(legalEntity()->additionalLicenseTypeCodes())) {
            return Response::denyWithStatus(404);
        }

        // The license can be created for legal entities with the following statuses.
        if (!in_array(legalEntity()->status, ['ACTIVE', 'SUSPENDED'], true)) {
            return Response::denyWithStatus(404);
        }

        // The additional license can be created for legal entities with an active primary license.
        if (!legalEntity()->hasActivePrimaryLicense()) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * User can edit the license
     */
    public function update(User $user, License $currentLicense, ?LegalEntity $currentLegalEntity = null): Response
    {
        if (is_null($currentLegalEntity)) {
            $currentLegalEntity = legalEntity();
        }

        // Should belong to the same legal entity
        if ($currentLicense->legalEntityId !== $currentLegalEntity->id) {
            return Response::denyWithStatus(404);
        }

        if ($user->cannot('license:write')) {
            return Response::denyWithStatus(404);
        }

        // Check that legal entity is in ‘ACTIVE’ or ‘SUSPENDED’ status
        if (!in_array(legalEntity()->status, ['ACTIVE', 'SUSPENDED'], true)) {
            return Response::denyWithStatus(404);
        }

        // Can't write to the main license
        if ($currentLicense->isPrimary) {
            return Response::denyWithStatus(403, __('licenses.policy.primary_not_editable'));
        }

        return Response::allow();
    }
}
