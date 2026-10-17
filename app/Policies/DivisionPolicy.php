<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Enums\Status;
use App\Models\Division;
use App\Enums\User\Role;
use App\Models\LegalEntity;
use App\Models\HealthcareService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;

class DivisionPolicy
{
    /**
     * User allowed to view the list of divisions
     */
    public function viewAny(User $user): Response
    {
        if ($user->cannot('division:read')) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * User allowed to view the Division's details
     * @param  Division  $division
     *
     * @return Response The response indicating whether the action is authorized or denied
     */
    public function view(User $user, Division $division): Response
    {
        // Should belong to the same legal entity
        if ($division->legalEntityId !== (int) legalEntity()->id) {
            return Response::denyWithStatus(404);
        }

        if ($user->cannot('division:details')) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * User allowed to synchronize the division(s)
     */
    public function sync(User $user, ?Division $division = null): Response
    {
        if ($division !== null && $division->legalEntityId !== (int) legalEntity()->id) {
            return Response::denyWithStatus(404);
        }

        // This for the case when newly created Draft division cannot be synchronized
        if ($division !== null && empty($division->uuid)) {
            return Response::denyWithStatus(404);
        }

        $scope = $division === null ? 'division:read' : 'division:details';

        if ($user->cannot($scope)) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }


    /**
     * User allow to create the Division
     * TV 3.29.1.1.1: only OWNER with division:write may create a division.
     */
    public function create(User $user): Response
    {
        if ($user->cannot('division:write')) {
            return Response::denyWithStatus(404);
        }

        $loginRole = session('single_role_login');
        $hasOwnerRole = $loginRole
            ? $loginRole === Role::OWNER->value && $user->hasRole(Role::OWNER, Auth::getDefaultDriver())
            : $user->hasAllowedRole([Role::OWNER]);

        if (!$hasOwnerRole) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * User allow to delete the Division's draft record fromm the DB
     */
    public function delete(User $user, Division $division): Response
    {
        if ($user->cannot('division:write')) {
            return Response::denyWithStatus(404);
        }

        // Only Divisions with DRAFT status can be deleted
        if ($division->status !== Status::DRAFT) {
            return Response::deny();
        }

        // Should belong to the same legal entity
        if ($division->legalEntityId !== (int) legalEntity()->id) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can update the model.
     * TV 3.29.1.2.1: any role with division:write may update a registered division.
     */
    public function update(User $user, Division $division, ?LegalEntity $legalEntity = null): Response|bool
    {
        if (\is_null($legalEntity)) {
            $legalEntity = legalEntity();
        }

        // If got null instaed Division model
        if (empty($division)) {
            return Response::denyWithStatus(404);
        }

        // Should belong to the same legal entity
        if ($division->legalEntityId !== (int) $legalEntity->id) {
            return Response::denyWithStatus(404);
        }

        if ($user->cannot('division:write')) {
            return Response::denyWithStatus(404);
        }

        // Inactive divisions cannot be updated
        if ($division->status === Status::INACTIVE) {
            return Response::deny();
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can activate the division.
     * TV 3.29.1.2.3: any role with division:activate may activate a division.
     *
     * @param  User  $user
     * @param  Division  $division
     *
     * @return Response The response indicating whether the action is authorized or denied
     */
    public function activate(User $user, Division $division): Response
    {
        if ($user->cannot('division:activate')) {
            return Response::denyWithStatus(404);
        }

        // Only inactive divisions can be activated
        if ($division->status !== Status::INACTIVE) {
            return Response::deny();
        }

        // Should belong to the same legal entity
        if ($division->legalEntityId !== (int) legalEntity()->id) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can deactivate the division.
     * TV 3.29.1.2.2: any role with division:deactivate may deactivate a division.
     *
     * @param  User  $user  The user attempting the action
     * @param  Division  $division  The division to be deactivated
     *
     * @return Response The response indicating whether the action is authorized or denied
     */
    public function deactivate(User $user, Division $division): Response
    {
        if ($user->cannot('division:deactivate')) {
            return Response::denyWithStatus(404);
        }

        // Should belong to the same legal entity
        if ($division->legalEntityId !== (int) legalEntity()->id) {
            return Response::denyWithStatus(404);
        }

        if (!in_array($division->status, [Status::ACTIVE, Status::UNSYNCED], true)) {
            return Response::deny();
        }

        return Response::allow();
    }

    /**
     * Get services associated with a division.
     *
     * @param  Division  $division  The division to get services for
     *
     * @return Builder Query builder for division services
     */
    protected function getDivisionServices(Division $division): Builder
    {
        return HealthcareService::query()->where('division_id', $division->id);
    }

    /**
     * Check if the division has any associated service.
     *
     * @param  Division  $division  The division to check for services
     * @return bool True if the division has at least one service, false otherwise
     */
    protected function hasAnyService(Division $division): bool
    {
        return (bool)$this->getDivisionServices($division)->count();
    }

    /**
     * Checks if the division has any active service.
     *
     * @param  Division  $division  The division to check
     *
     * @return bool Returns true if the division has at least one active service, false otherwise
     */
    protected function hasAnyActiveService(Division $division): bool
    {
        return $this->getDivisionServices($division)
            ->where('status', Status::ACTIVE)->exists();

    }
}
