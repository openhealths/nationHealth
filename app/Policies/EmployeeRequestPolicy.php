<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Employee\EmployeeRequest;
use App\Models\Relations\Party;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class EmployeeRequestPolicy
{
    public function viewAny(User $user): Response
    {
        return $user->can('employee_request:read')
            ? Response::allow()
            : Response::deny(__('employees.policy.req.view_any_denied'));
    }

    public function view(User $user, EmployeeRequest $employeeRequest): Response
    {
        if ((int)$employeeRequest->legal_entity_id !== (int)legalEntity()->id) {
            return Response::denyWithStatus(404);
        }

        return $user->can('employee_request:read')
            ? Response::allow()
            : Response::deny(__('employees.policy.req.view_denied'));
    }

    public function create(User $user, ?Party $party = null): Response
    {
        if ($party) {
            if ($party->employees->isEmpty()) {
                return Response::deny(__('employees.policy.req.add_position_denied_for_draft'));
            }
        }

        return $user->can('employee_request:write')
            ? Response::allow()
            : Response::deny(__('employees.policy.req.create_denied'));
    }

    public function update(User $user, EmployeeRequest $employeeRequest): Response
    {
        if ((int)$employeeRequest->legal_entity_id !== (int)legalEntity()->id) {
            return Response::denyWithStatus(404);
        }

        if (!$employeeRequest->isLocalDraft()) {
            return Response::deny(__('employees.policy.req.processed_no_edit'));
        }

        return $user->can('employee_request:write')
            ? Response::allow()
            : Response::deny(__('employees.policy.req.update_denied'));
    }

    public function delete(User $user, EmployeeRequest $employeeRequest): Response
    {
        if ((int)$employeeRequest->legal_entity_id !== (int)legalEntity()->id) {
            return Response::denyWithStatus(404);
        }

        if (!$employeeRequest->isLocalDraft()) {
            return Response::deny(__('employees.policy.req.processed_no_delete'));
        }

        return $user->can('employee_request:write')
            ? Response::allow()
            : Response::deny(__('employees.policy.req.delete_denied'));
    }
}
