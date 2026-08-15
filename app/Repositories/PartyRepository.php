<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\User;
use App\Enums\Status;
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
        $isLegalEntityCanBeReorganized = $legalEntity->type->name === LegalEntity::TYPE_PRIMARY_CARE || $legalEntity->type->name === LegalEntity::TYPE_OUTPATIENT;

        $partyEmployees = Employee::getEmployeesForParty(legalEntityId: $legalEntity->id, partyId: $party->id)->get();

        if ($legalEntity->status === Status::REORGANIZED->value) {
            $reorganizedEmployees = Employee::getEmployeesForParty(legalEntityId: $legalEntity->id, partyId: $party->id, status: Status::REORGANIZED)->get();

            $partyEmployees = $partyEmployees->merge($reorganizedEmployees)->unique('id')->values();
        }

        // Get all employee-user relations from pivot table for the legal entity to compare with the new candidates we want to sync later
        $pivotEmployeeUsers = $this->getPivotEmployeeUsers($legalEntity->id);

        $employeesWithUser = $partyEmployees->filter(fn (Employee $employee) => $employee->userId !== null);

        $partyUsers = User::allRelated($party->id, $legalEntity->id)->get();

        $sessionUser = Auth::user();
        if (
            $sessionUser instanceof User
            && (int) $sessionUser->partyId === (int) $party->id
            && !$partyUsers->contains(fn (User $user): bool => (int) $user->id === (int) $sessionUser->id)
        ) {
            $partyUsers->push($sessionUser);
        }

        if ($partyUsers->isEmpty()) {
            return;
        }

        $employeesToSync = [];
        $employeesToDelete = [];
        $employeesCandidatesToSync = [];
        $usersToSync = $partyUsers->pluck('id')->all();

        $guards = array_keys((array) config('auth.guards'));
        $savedGuard = Auth::getDefaultDriver();
        $loginedRole = Session::get('first_login_role');

        setPermissionsTeamId($legalEntity->id);

        foreach ($partyUsers as $user) {
            if ($user->insertedAt === null) {
                continue;
            }

            $userEmployees = $employeesWithUser
                ->filter(fn (Employee $employee) => (int) $employee->userId === (int) $user->id)
                ->filter(fn (Employee $employee) => $employee->isCreatedAtOrAfter($user->insertedAt));

            $employeesCandidatesToSync = array_merge(
                $employeesCandidatesToSync,
                $userEmployees->map(fn (Employee $employee) => [
                    'employee_id' => $employee->id,
                    'user_id' => $user->id,
                ])->all()
            );

            $oldRoles = $user->loadMissing('roles')->roles->pluck('name')->all();

            $availRoles = $userEmployees
                ->map(fn (Employee $employee) => $employee->employeeType)
                ->unique()
                ->values()
                ->all();

            if (\in_array(Role::OWNER->value, $availRoles, true) && $isLegalEntityCanBeReorganized) {
                $availRoles[] = Role::REORGANIZATION_OWNER->value;
            }

            $newRoles = collect($availRoles)->diff($oldRoles)->values()->toArray();
            $staleRoles = collect($oldRoles)->diff($availRoles)->values()->toArray();

            if ($loginedRole && $user->id === Auth::id()) {
                $loginedEmployee = $partyEmployees->where('employee_type', $loginedRole)->first();

                if ($loginedEmployee) {
                    $employeesCandidatesToSync[] = ['employee_id' => $loginedEmployee->id, 'user_id' => $user->id];
                    $newRoles = array_unique(array_merge($newRoles, [$loginedRole]));
                    $staleRoles = array_values(array_diff($staleRoles, [$loginedRole]));
                }
            }

            if ($newRoles === [] && $staleRoles === []) {
                continue;
            }

            $user->unsetRelation('roles')->unsetRelation('permissions');

            if ($loginedRole === Role::OWNER->value && $user->id === Auth::id()) {
                $newRoles = array_unique(array_merge($newRoles, [Role::REORGANIZATION_OWNER->value]));
            }

            foreach ($guards as $guard) {
                Auth::shouldUse($guard);

                if ($newRoles !== []) {
                    $user->assignRole($newRoles);
                }

                foreach ($staleRoles as $staleRole) {
                    if ($user->hasRole($staleRole, $guard)) {
                        $user->removeRole($staleRole);
                    }
                }
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
     * @param  int  $legalEntityId
     * @return array<int, array{employee_id: int, user_id: int}>
     */
    protected function getPivotEmployeeUsers(int $legalEntityId): array
    {
        // First iteration: get all employees with user_id and their users from pivot table
        $pivotEmployeeUsers = Employee::getEmployeesViaPivot($legalEntityId)->get()->map(fn (Employee $employee) => [
           'id' => $employee->id,
           'users' => $employee->users()->allRelatedIds()->all(),
        ])->toArray();

        // Second iteration: flatten the pivot data to have a list of employee_id and user_id pairs for easier syncing later
        return collect($pivotEmployeeUsers)->flatMap(
            fn ($item) =>
                collect($item['users'])->map(fn ($userId) => [
                    'employee_id' => $item['id'],
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
     * @param  array<int, array{employee_id: int, user_id: int}>  $employeesCandidatesToSync  Desired employee-user pairs.
     * @param  array<int, array{employee_id: int, user_id: int}>  $pivotEmployeeUsers  Existing employee-user pairs from the pivot table.
     * @param  array<int, int>  $usersToSync  IDs of users to process.
     * @return array{employeesToDelete: array<int, array{employee_id: int, user_id: int}>, employeesToSync: array<int, array{employee_id: int, user_id: int}>}
     */
    protected function filterEmployeesSyncData(array $employeesCandidatesToSync, array $pivotEmployeeUsers, array $usersToSync): array
    {
        $employeesToDelete = [];
        $employeesToSync = [];

        // Deduplicate before insert
        $employeesCandidatesToSync = collect($employeesCandidatesToSync)
            ->unique(fn ($item) => $item['employee_id'] . '_' . $item['user_id'])
            ->values()
            ->all();

        foreach ($usersToSync as $userId) {
            $pivotEmployee = collect($pivotEmployeeUsers)->filter(fn ($item) => $item['user_id'] === $userId)->pluck('employee_id')->all();
            $candidate = collect($employeesCandidatesToSync)->filter(fn ($item) => $item['user_id'] === $userId)->pluck('employee_id')->all();

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
