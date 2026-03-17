<?php

namespace App\Repositories;

use App\Models\Relations\Party;
use App\Models\Employee\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class PartyRepository
{
    public function syncUserEmployeesEndRoles(Party $party, int $legalEntityId): void
    {
        $partyEmployees = $party->employees->where('legal_entity_id', $legalEntityId);
        $employeesWithUser = $partyEmployees->filter(fn(Employee $employee) => $employee->user_id !== null);

        $partyUsers = $party->users->whereIn('id', $employeesWithUser->pluck('user_id'));


        $employeesToSync = [];
        $usersToSync = [];

        $guards = collect(array_keys((array) config('auth.guards')))->values();
        $savedGuard = Auth::getDefaultDriver();

        setPermissionsTeamId($legalEntityId);

        // Get the right data structure to perform sync
        foreach ($partyUsers as $user) {
            $employeesFiltered = $employeesWithUser->filter(function ($employee) use ($user) {
                return $employee->isCreatedAtOrAfter($user->insertedAt);
            });

            $usersToSync[] = $user->id;
            $employeesToSync = array_merge($employeesToSync, $employeesFiltered->map(fn(Employee $employee) => ['employee_id' => $employee->id, 'user_id' => $user->id])->all());

            // Current Roles for the $user
            $oldRoles = $user->loadMissing('roles')->roles->pluck('name')->all();

            // Get all suitable roles based on the employee types of the user's party employees
            $availRoles = $partyEmployees->filter(fn(Employee $employee) => $employee->isCreatedAtOrAfter($user->insertedAt))
                ->map(fn(Employee $employee) => $employee->employeeType)
                ->unique()
                ->values()
                ->all();

            // Determine which roles are new and need to be assigned
            $newRoles = collect($availRoles)->diff($oldRoles)->values()->toArray();

            if (empty($newRoles)) {
                continue;
            }

            $user->unsetRelation('roles')->unsetRelation('permissions');

            foreach ($guards as $guard) {
                Auth::shouldUse($guard);

                // Set all roles for the all guards that have the same name as the new roles we want to assign (depends on guard)
                $user->assignRole($newRoles);
            }
        }

        // Sync all employee_users relations
        DB::table('employee_users')->whereIn('user_id', $usersToSync)->delete();
        DB::table('employee_users')->insert($employeesToSync);

        Auth::shouldUse($savedGuard);
    }
}
