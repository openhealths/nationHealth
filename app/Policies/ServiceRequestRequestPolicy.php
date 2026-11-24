<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\Person\Person;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ServiceRequestRequestPolicy
{
    public function viewAny(User $user): Response
    {
        return $this->search($user)->allowed() && $user->can('view', Person::class)
            ? Response::allow()
            : Response::denyWithStatus(404);
    }

    public function view(User $user, ServiceRequestRequest $serviceRequest, Person $person, LegalEntity $legalEntity): Response
    {
        if (!$legalEntity->is(legalEntity()) || $this->viewAny($user)->denied()) {
            return Response::denyWithStatus(404);
        }

        return ServiceRequestRequest::readableFor($person, $user, $legalEntity)->whereKey($serviceRequest->id)->exists()
            ? Response::allow()
            : Response::denyWithStatus(404);
    }

    public function updateAny(User $user, LegalEntity $legalEntity): Response
    {
        return $legalEntity->is(legalEntity()) && $user->partyId !== null
            && $this->viewAny($user)->allowed() && $user->can('service_request:write')
            ? Response::allow()
            : Response::denyWithStatus(404);
    }

    public function update(User $user, ServiceRequestRequest $serviceRequest, Person $person, LegalEntity $legalEntity): Response
    {
        if ($this->updateAny($user, $legalEntity)->denied() || $this->view($user, $serviceRequest, $person, $legalEntity)->denied()) {
            return Response::denyWithStatus(404);
        }

        return Employee::forUserInLegalEntity($user, $legalEntity)->whereKey($serviceRequest->employeeId)->exists()
            && $serviceRequest->isEditableRequest()
            ? Response::allow()
            : Response::denyWithStatus(404);
    }

    /**
     * Determine whether the user can search electronic referrals.
     */
    public function search(User $user): Response
    {
        if ($user->cannot('service_request:read')) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can take an electronic referral into work.
     */
    public function use(User $user): Response
    {
        if ($user->cannot('service_request:use')) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    public function complete(User $user): Response
    {
        return $user->can('service_request:complete') ? Response::allow() : Response::denyWithStatus(404);
    }
}
