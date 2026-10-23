<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\User;
use App\Repositories\EmployeeRepository;
use Illuminate\Auth\Access\Response;

class DeviceRequestRequestPolicy
{
    public function viewAny(User $user, ?LegalEntity $legalEntity = null): Response
    {
        $legalEntity ??= legalEntity();
        if ($legalEntity === null) {
            return Response::denyAsNotFound();
        }

        return $this->perform($user, $legalEntity, 'device_request:read');
    }

    public function create(User $user, ?LegalEntity $legalEntity = null): Response
    {
        $legalEntity ??= legalEntity();
        if ($legalEntity === null) {
            return Response::denyAsNotFound();
        }

        return $this->perform($user, $legalEntity, 'device_request:write');
    }

    public function perform(User $user, LegalEntity $legalEntity, string $scope): Response
    {
        $allowed = in_array($scope, ['device_request:read', 'device_request:write', 'device_request:revoke',
            'device_request:complete', 'device_request:mark_in_error', 'device_request:resend', 'device_dispense:read'], true)
            && (int) getPermissionsTeamId() === (int) $legalEntity->id
            && $user->can($scope)
            && $this->employee($user, $legalEntity, $scope) !== null;

        return $allowed ? Response::allow() : Response::denyAsNotFound();
    }

    public function employee(User $user, LegalEntity $legalEntity, string $scope): ?Employee
    {
        $types = $scope === 'device_request:write'
            ? ['DOCTOR', 'SPECIALIST']
            : ['DOCTOR', 'SPECIALIST', 'ASSISTANT', 'MED_COORDINATOR', 'MED_ADMIN'];

        return app(EmployeeRepository::class)->activeForLegalEntity($user, $legalEntity, $types);
    }
}
