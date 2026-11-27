<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Preperson\Status as PrepersonStatus;
use App\Enums\Status;
use App\Enums\User\Role;
use App\Models\LegalEntity;
use App\Models\Preperson;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class PrepersonPolicy
{
    /**
     * Roles that create and update prepersons, by legal entity type. Other roles and legal entity types may hold the
     * preperson scopes, but don't get these actions in the application.
     *
     * @var array
     */
    protected const array WRITE_ROLES = [
        LegalEntity::TYPE_OUTPATIENT => [Role::SPECIALIST, Role::ASSISTANT, Role::RECEPTIONIST],
    ];

    /**
     * Roles that view prepersons, by legal entity type.
     *
     * @var array
     */
    protected const array READ_ROLES = [
        LegalEntity::TYPE_OUTPATIENT => [Role::SPECIALIST, Role::ASSISTANT, Role::RECEPTIONIST],
        LegalEntity::TYPE_PRIMARY_CARE => [Role::DOCTOR],
    ];

    /**
     * Deny every ability when the current legal entity cannot operate with prepersons.
     * Prepersons are available only for the legal entity types listed in PREPERSON_LEGAL_ENTITIES_ALLOWED_TYPES.
     */
    public function before(User $user, string $ability): ?Response
    {
        if (!in_array(legalEntity()->type->name, config('ehealth.preperson.legal_entities_allowed_types', []), true)) {
            return Response::denyWithStatus(404);
        }

        return null;
    }

    /**
     * Determine whether the user can view list of prepersons.
     */
    public function viewAny(User $user): Response
    {
        if ($user->cannot('preperson:read') || !$this->hasReadRole($user)) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can view details about preperson.
     */
    public function view(User $user, Preperson $preperson): Response
    {
        if ($user->cannot('preperson:read') || !$this->hasReadRole($user)) {
            return Response::denyWithStatus(404);
        }

        // Drafts are not registered in eHealth (no uuid, no medical events), so their record pages are unavailable.
        if ($preperson->status === PrepersonStatus::DRAFT) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can view the summary of the preperson's medical records.
     */
    public function viewSummary(User $user, Preperson $preperson): Response
    {
        if ($user->cannot('patient_summary:read') || !$this->hasReadRole($user)) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can create preperson.
     */
    public function create(User $user): Response
    {
        if ($user->cannot('preperson:write') || !$this->hasWriteRole($user)) {
            return Response::denyWithStatus(404);
        }

        // Legal entity must be ACTIVE
        if (legalEntity()->status !== Status::ACTIVE->value) {
            return Response::denyWithStatus(404);
        }

        $healthcareServicesQuery = legalEntity()
            ->healthcareServices()
            ->whereStatus(Status::ACTIVE)
            ->whereProvidingCondition('INPATIENT');

        $allowedSpecialityTypes = config('ehealth.preperson.healthcare_services_speciality_types', []);
        if (!empty($allowedSpecialityTypes)) {
            $healthcareServicesQuery->whereIn('speciality_type', $allowedSpecialityTypes);
        }

        // Legal entity must have at least one active inpatient healthcare service.
        if (!$healthcareServicesQuery->exists()) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can continue the registration of a draft preperson.
     */
    public function edit(User $user, Preperson $preperson): Response
    {
        if ($user->cannot('preperson:write') || !$this->hasWriteRole($user)) {
            return Response::denyWithStatus(404);
        }

        // Only drafts can have their registration continued.
        if ($preperson->status !== PrepersonStatus::DRAFT) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can delete a draft preperson.
     */
    public function delete(User $user, Preperson $preperson): Response
    {
        if ($user->cannot('preperson:write') || !$this->hasWriteRole($user)) {
            return Response::denyWithStatus(404);
        }

        // Only local drafts can be deleted; registered prepersons are managed through eHealth.
        if ($preperson->status !== PrepersonStatus::DRAFT) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can update preperson.
     */
    public function update(User $user, Preperson $preperson): Response
    {
        if ($user->cannot('preperson:write') || !$this->hasWriteRole($user)) {
            return Response::denyWithStatus(404);
        }

        // Only active can be updated.
        if ($preperson->status !== PrepersonStatus::ACTIVE) {
            return Response::denyWithStatus(404);
        }

        // Legal entity must be ACTIVE
        if (legalEntity()->status !== Status::ACTIVE->value) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can create medical events for prepersons. Only the roles that create and update
     * prepersons create them, others only view the medical events.
     */
    public function createMedicalEvents(User $user): Response
    {
        if (!$this->hasWriteRole($user)) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Whether the user holds a role that creates and updates prepersons in the current legal entity.
     *
     * @param  User  $user
     * @return bool
     */
    protected function hasWriteRole(User $user): bool
    {
        return $user->hasAllowedRole(self::WRITE_ROLES[legalEntity()->type->name] ?? []);
    }

    /**
     * Whether the user holds a role that views prepersons in the current legal entity.
     *
     * @param  User  $user
     * @return bool
     */
    protected function hasReadRole(User $user): bool
    {
        return $user->hasAllowedRole(self::READ_ROLES[legalEntity()->type->name] ?? []);
    }
}
