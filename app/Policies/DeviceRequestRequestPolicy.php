<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Employee\Employee;
use App\Models\Declaration;
use App\Models\MedicalEvents\Sql\Approval;
use App\Models\Person\Person;
use App\Models\User;
use App\Repositories\Repository;
use Illuminate\Auth\Access\Response;

class DeviceRequestRequestPolicy
{
    public function viewAny(User $user): Response
    {
        return $this->perform($user, 'device_request:read');
    }

    public function create(User $user): Response
    {
        return $this->perform($user, 'device_request:write');
    }

    public function viewAllForPatient(User $user, Person $person): bool
    {
        return $this->perform($user, 'device_request:read')->allowed()
            && (Declaration::accessGrantedTo($user, $person, legalEntity())->exists()
                || Approval::accessGrantedTo($user, $person, legalEntity())->exists());
    }

    public function perform(User $user, string $scope): Response
    {
        $legalEntity = legalEntity();
        $allowed = $legalEntity !== null
            && in_array($scope, ['device_request:read', 'device_request:write', 'device_request:revoke',
            'device_request:complete', 'device_request:mark_in_error', 'device_request:resend', 'device_dispense:read'], true)
            && (int) getPermissionsTeamId() === (int) $legalEntity->id
            && $user->can($scope)
            && $this->employee($user, $scope) !== null;

        return $allowed ? Response::allow() : Response::denyAsNotFound();
    }

    public function employee(User $user, string $scope, ?string $employeeUuid = null): ?Employee
    {
        $types = $scope === 'device_request:write'
            ? ['DOCTOR', 'SPECIALIST']
            : ['DOCTOR', 'SPECIALIST', 'ASSISTANT', 'MED_COORDINATOR', 'MED_ADMIN'];

        return Repository::employee()->activeForLegalEntity($user, legalEntity(), $types, $employeeUuid);
    }
}
