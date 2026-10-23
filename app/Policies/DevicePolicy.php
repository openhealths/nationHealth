<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\User\Role;
use App\Models\Declaration;
use App\Models\MedicalEvents\Sql\Approval;
use App\Models\MedicalEvents\Sql\Device;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class DevicePolicy
{
    /**
     * Determine whether the user can view the list of devices.
     */
    public function viewAny(User $user): Response
    {
        if ($user->cannot('device:read')) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can view the device of the patient: one of the devices the user may read.
     */
    public function view(User $user, Device $device, Person|Preperson $patient): Response
    {
        if ($user->cannot('device:read')) {
            return Response::denyWithStatus(404);
        }

        $hasPatientAccess = Declaration::accessGrantedTo($user, $patient, legalEntity())->exists()
            || Approval::accessGrantedTo($user, $patient, legalEntity())->exists()
            || Approval::resourceTypeAccessGrantedTo($user, $patient, 'device', legalEntity())->exists();

        return Device::readableFor($patient, $hasPatientAccess)->whereKey($device->id)->exists()
            ? Response::allow()
            : Response::denyWithStatus(404);
    }

    /**
     * Determine whether the user can create device.
     */
    public function create(User $user): Response
    {
        if ($user->cannot('device:write')) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can mark devices, their associations with the patient and their detected issues as
     * entered in error. They are cancelled together, so one rule covers all three.
     */
    public function cancel(User $user): Response
    {
        if ($user->cannot('encounter:cancel')) {
            return Response::denyWithStatus(404);
        }

        // The scope alone is held by more roles than may cancel devices
        if (!$user->hasAllowedRole([Role::DOCTOR, Role::SPECIALIST, Role::MED_ADMIN])) {
            return Response::denyWithStatus(404);
        }

        return Response::allow();
    }
}
