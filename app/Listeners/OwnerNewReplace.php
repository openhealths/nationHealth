<?php

namespace App\Listeners;

use App\Core\Arr;
use App\Events\EHealthUserLogin;
use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\Api\Employee;

class OwnerNewReplace
{
    public function handle(EHealthUserLogin $event): void
    {
        \Log::info('OwnerNewReplace start');
        return;
        $user = $event->user;
        $role = session('ehealth_user_role');
        if ($user->role === 'OWNER') {
            return;
        }
        if ($role != 'OWNER') {
            return;
        }

        if (!$user->ownerScopes) {
            return;
        }

        $data = EHealth::employee()->getMany([
            'legal_entity_id' => $event->legalEntity->uuid,
            'status' => 'APPROVED',
            'employee_type' => 'OWNER'
        ])->validate();

        $newOwner = Arr::first($data);

        $oldOwner = Employee::activeOwners($event->legalEntity->id)->first();
        if ($oldOwner->uuid === $newOwner['uuid']) {
            return;
        }

        $newOwnerDetails = EHealth::employee()->getDetails($newOwner['uuid'])->validate();


    }
}
