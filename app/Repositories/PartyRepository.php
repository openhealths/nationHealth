<?php

namespace App\Repositories;

use App\Models\User;
use App\Enums\User\Role;
use App\Models\LegalEntity;
use App\Models\Relations\Party;
use App\Models\Employee\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;


class PartyRepository
{
    public function syncUserEmployeesAndRoles(Party $party, LegalEntity $legalEntity): void
    {
        $isLegalEntityCanBeReorganized = $legalEntity->type->name  === LegalEntity::TYPE_PRIMARY_CARE || $legalEntity->type->name === LegalEntity::TYPE_OUTPATIENT;

        $partyEmployees = Employee::getEmployeesForParty(legalEntityId: $legalEntity->id, partyId: $party->id)->get();

        // Get all employee-user relations from pivot table for the legal entity to compare with the new candidates we want to sync later
        $pivotEmployeeUsers = $this->getPivotEmployeeUsers($legalEntity->id);

        if ($partyEmployees->isEmpty()) {
            return;
        }

        $employeesWithUser = $partyEmployees->filter(fn(Employee $employee) => $employee->user_id !== null);

        // Get all users that are linked to the party through employees with user_id or through employee_users pivot
        $partyUsers = User::allRelated($party->id, $legalEntity->id)->get();

        if ($partyUsers->isEmpty()) {
            return;
        }

        $employeesToSync = [];
        $employeesToDelete = [];
        $employeesCandidatesToSync = [];
        $usersToSync = $partyUsers->pluck('id')->all();

        $guards = collect(array_keys((array) config('auth.guards')))->values();
        $savedGuard = Auth::getDefaultDriver();
        $loginedRole = Session::get('first_login_role');

        setPermissionsTeamId($legalEntity->id);

        // Get the right data structure to perform sync
        foreach ($partyUsers as $user) {
            $employeesFiltered = $employeesWithUser->filter(fn(Employee $employee) => $employee->isCreatedAtOrAfter($user->insertedAt));

            $employeesCandidatesToSync = array_merge($employeesCandidatesToSync, $employeesFiltered->map(fn(Employee $employee) => ['employee_id' => $employee->id, 'user_id' => $user->id])->all());

            // Current Roles for the $user
            $oldRoles = $user->loadMissing('roles')->roles->pluck('name')->all();

            // Get all suitable roles based on the employee types of the user's party employees
            $availRoles = $partyEmployees->filter(fn(Employee $employee) => $employee->isCreatedAtOrAfter($user->insertedAt))
                ->map(fn(Employee $employee) => $employee->employeeType)
                ->unique()
                ->values()
                ->all();

            if (\in_array(Role::OWNER->value, $availRoles) && $isLegalEntityCanBeReorganized) {
                $availRoles[] = Role::REORGANIZATION_OWNER->value;
            }

            // Determine which roles are new and need to be assigned
            $newRoles = collect($availRoles)->diff($oldRoles)->values()->toArray();

            // Check if the user has an more than one employee with $loginedRole role in the same party.
            // If so, include the $loginedRole's role from the party to ensure proper access for users with multiple $loginedRole employees in the same party
            // NOTE: this case is possible when data of existent employee has been modified with changed an email.
            // Changing email causes to create a new user with the same email and assign the employee to this new user,
            // but the old user still exists with the old employee and role.
            if ($loginedRole && $user->id === Auth::id()) {

                $loginedEmployee = $partyEmployees->where('employee_type', $loginedRole)->first();

                // This need to save the pivot table data when the employee has been modified with changed email, (employee record has new 'user_id' value)
                // so the new user is created and assigned to the employee, but the old user still exists with the old employee and role.
                $employeesCandidatesToSync = array_merge(
                    $employeesCandidatesToSync,
                    [['employee_id' => $loginedEmployee->id, 'user_id' => $user->id]],
                    array_map(
                        fn($userId) => ['employee_id' => $loginedEmployee->id, 'user_id' => $userId],
                        array_column(array_filter($pivotEmployeeUsers, fn($puser) => $puser['employee_id'] === $loginedEmployee->id), 'user_id')
                    )
                );

                $newRoles = array_unique(array_merge($newRoles, [$loginedRole]));
            }

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

        // Get the right data structure to perform sync
        [
            'employeesToDelete' => $employeesToDelete,
            'employeesToSync' => $employeesToSync,
        ] = $this->filterEmployeesSyncData($employeesCandidatesToSync, $pivotEmployeeUsers, $usersToSync);

        // Perform the actual sync: delete removed relations
        if (!empty($employeesToDelete)) {
            DB::table('employee_users')->where('employee_id', array_column($employeesToDelete, 'employee_id'))->where('user_id', array_column($employeesToDelete, 'user_id'))->delete();
        }

        // Perform the actual sync: add new relations
        if (!empty($employeesToSync)) {
            DB::table('employee_users')->upsert($employeesToSync, ['employee_id', 'user_id']);
        }

        Auth::shouldUse($savedGuard);
    }

    /**
     * Get all existing employee-user relations from the pivot table for a given legal entity.
     *
     * Fetches employees that already have at least one associated user, then flattens
     * the result into a list of `employee_id` / `user_id` pairs for later comparison
     * during sync operations.
     *
     * @param  int   $legalEntityId
     *
     * @return array<int, array{employee_id: int, user_id: int}>
     */
    protected function getPivotEmployeeUsers(int $legalEntityId): array
    {
        // First iteration: get all employees with user_id and their users from pivot table
         $pivotEmployeeUsers = Employee::getEmployeesViaPivot($legalEntityId)->get()->map(fn(Employee $employee) => [
            'id' => $employee->id,
            'users' => $employee->users()->allRelatedIds()->all(),
        ])->toArray();

        // Second iteration: flatten the pivot data to have a list of employee_id and user_id pairs for easier syncing later
        return collect($pivotEmployeeUsers)->flatMap(fn($item) =>
                collect($item['users'])->map(fn($userId) => [
                    'employee_id'   => $item['id'],
                    'user_id' => $userId,
                ])
            )->values()->all();
    }

    /**
     * Compare sync candidates against existing pivot relations and determine which records to add or remove.
     *
     * For each user in $usersToSync, computes the symmetric difference between the currently
     * stored pivot pairs ($pivotEmployeeUsers) and the desired pairs ($employeesCandidatesToSync),
     * returning two lists: relations that should be inserted and relations that should be deleted.
     *
     * @param  array<int, array{employee_id: int, user_id: int}> $employeesCandidatesToSync  Desired employee-user pairs.
     * @param  array<int, array{employee_id: int, user_id: int}> $pivotEmployeeUsers         Existing employee-user pairs from the pivot table.
     * @param  array<int, int>                                   $usersToSync                IDs of users to process.
     *
     * @return array{employeesToDelete: array<int, array{employee_id: int, user_id: int}>, employeesToSync: array<int, array{employee_id: int, user_id: int}>}
     */
    protected function filterEmployeesSyncData(array $employeesCandidatesToSync, array $pivotEmployeeUsers, array $usersToSync): array
    {
        $employeesToDelete = [];
        $employeesToSync = [];

        // Deduplicate before insert
        $employeesCandidatesToSync = collect($employeesCandidatesToSync)
            ->unique(fn($item) => $item['employee_id'] . '_' . $item['user_id'])
            ->values()
            ->all();

        foreach ($usersToSync as $userId) {
            $pivotEmployee = collect($pivotEmployeeUsers)->filter(fn($item) => $item['user_id'] === $userId)->pluck('employee_id')->all();
            $candidate = collect($employeesCandidatesToSync)->filter(fn($item) => $item['user_id'] === $userId)->pluck('employee_id')->all();

            // Values in pivotEmployee but not in candidate
            $employeesToRemove = array_diff($pivotEmployee, $candidate);

            // Values in candidate but not in pivotEmployee
            $employeesToAdd = array_diff($candidate, $pivotEmployee);

            // Skip if nothing to sync for the user
            if (empty($employeesToRemove) && empty($employeesToAdd)) {
                continue;
            }

            foreach ($employeesToRemove as $employeeId) {
                $employeesToDelete[] = ['employee_id' => $employeeId, 'user_id' => $userId];
            }

            foreach ($employeesToAdd as $employeeId) {
                $employeesToSync[] = ['employee_id' => $employeeId, 'user_id' => $userId];
            }
        }

        return [
            'employeesToDelete' => $employeesToDelete,
            'employeesToSync' => $employeesToSync,
        ];
    }
}
