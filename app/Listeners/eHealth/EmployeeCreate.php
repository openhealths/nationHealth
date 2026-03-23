<?php

declare(strict_types=1);

namespace App\Listeners\eHealth;

use Log;
use Throwable;
use App\Core\Arr;
use Carbon\Carbon;
use App\Models\User;
use App\Classes\eHealth\EHealth;
use App\Events\EHealthUserLogin;
use App\Repositories\Repository;
use App\Models\Employee\Employee;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Enums\Employee\RequestStatus;
use App\Enums\Employee\RevisionStatus;
use App\Models\Employee\EmployeeRequest;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

class EmployeeCreate
{
    /**
     * @throws Throwable
     */
    public function handle(EHealthUserLogin $event): void
    {
        $user = $event->user;

        $employeeRequests = EmployeeRequest::with('revision')
            ->where('email', $user->email)
            ->where(fn(EloquentBuilder $q) => $q
                ->where(fn(EloquentBuilder $query) =>
                    $query->where('status', RequestStatus::SIGNED)
                )
                // Sync for requests approved through our system and synced before user's first login
                ->orWhere(fn(EloquentBuilder $query) =>
                    $query->where('status', RequestStatus::APPROVED)
                        ->whereNotNull(['start_date', 'employee_id', 'user_id'])
                        ->where('user_id', $user->id)
                        ->whereHas('employee', fn(EloquentBuilder $query) =>
                            $query->whereNull('user_id')
                        )
                )
                // Sync for requests that weren't approved through our system, were imported from EHealth
                ->orWhere(fn(EloquentBuilder $query) =>
                    $query->where('status', RequestStatus::APPROVED)
                        ->whereNotNull('start_date')
                        ->whereNull('employee_id')
                )
            )
            ->orderBy('created_at', 'desc')
            ->get();

        if ($employeeRequests->isEmpty()) {
            return;
        }

        $requestWithParty = $employeeRequests->whereNotNull('party_id')->first();
        $firstRequest = $employeeRequests->first();

        if ($requestWithParty) {
            $user->party()->associate($requestWithParty->partyId);
            $user->save();
            $user->refresh();
            Log::info('[EmployeeCreate] Associated new User with existing Party.', ['user_id' => $user->id, 'party_id' => $requestWithParty->partyId]);
        } else {
            Log::info('[EmployeeCreate] No party_id found on any EmployeeRequest. User will be sent to KEP verification.', ['user_id' => $user->id]);
        }

        $taxId = $firstRequest->revision->data['party']['tax_id'] ?? null;
        if (!$taxId) {
            return;
        }

        $employees = EHealth::employee()->getMany(
            [
                'legal_entity_id' => $event->legalEntity->uuid,
                'tax_id' => $taxId,
                'status' => 'APPROVED',
                'page_size' => config('ehealth.api.page_size_max') // Get maximum records at one time allowed by EHealth's API (if page_size in .env will set to smaller value, some of employee may be missed)
            ]
        )->validate();

        if (empty($employees)) {
            return;
        }

        // This filters out only uuids associated with the current user
        $existingUuids = Employee::whereIn('uuid', array_column($employees, 'uuid'))
            ->where('legal_entity_id', $event->legalEntity->id)
            ->whereHas('users', fn(EloquentBuilder $query) => $query->where('id', $user->id))
            ->pluck('uuid')
            ->all();

        $employees = array_filter($employees, fn (array $employee) => !in_array($employee['uuid'], $existingUuids));

        if (empty($employees)) {
            return;
        }

        DB::transaction(function () use ($user, $employees, $employeeRequests, $event, &$newRoles) {
            foreach ($employees as $eHealthEmployee) {

                $employeeRequest = $this->findMatchingLocalRequest($employeeRequests, $eHealthEmployee);

                if (!$employeeRequest) {
                    continue;
                }

                // If emloyee has already an associated user, we skip attaching because it means it's already created by the stored user (user_id)
                $eHealthEmployee['user_id'] ??= $user->id;

                $dataFromRevision = EHealth::employeeRequest()->mapCreate($employeeRequest->revision->data);
                $dataFromEHealth = Arr::only(
                    $eHealthEmployee,
                    ['uuid', 'status', 'position', 'employee_type', 'start_date', 'end_date', 'is_active']
                );

                $newEmployee = Employee::updateOrCreate(
                    ['uuid' => $dataFromEHealth['uuid']],
                    array_merge($dataFromRevision['employee'], $dataFromEHealth, [
                        'legal_entity_id' => $event->legalEntity->id,
                        'legal_entity_uuid' => $event->legalEntity->uuid,
                        'user_id' => $user->id,
                    ])
                );

                $cleanPartyFromRevision = $dataFromRevision['party'];
                $cleanPartyFromEHealth = Arr::except($eHealthEmployee['party'] ?? [], ['email']);
                $mergedCleanPartyData = array_merge($cleanPartyFromRevision, $cleanPartyFromEHealth);

                $newEmployee->users()->syncWithoutDetaching([$user->id]);

                $newEmployee = Repository::employee()->updateDetails(
                    $newEmployee,
                    $mergedCleanPartyData,
                    $dataFromRevision['documents'],
                    $dataFromRevision['phones'],
                    $dataFromRevision['educations'] ?? null,
                    $dataFromRevision['specialities'] ?? null,
                    $dataFromRevision['qualifications'] ?? null,
                    $dataFromRevision['scienceDegree'] ?? null
                );

                if (!$user->partyId && $newEmployee->partyId) {
                    $user->partyId = $newEmployee->partyId;
                    $user->save();

                    Log::info('[EmployeeCreate] Associated User with Party from new Employee record.', ['user_id' => $user->id, 'party_id' => $newEmployee->partyId]);
                }

                $employeeRequest->update(
                    [
                        'employee_id' => $newEmployee->id,
                        'status' => RequestStatus::APPROVED,
                        'user_id' => $user->id,
                        'party_id' => $newEmployee->partyId,
                    ]
                );

                $employeeRequest->revision->update(['status' => RevisionStatus::APPLIED]);

                $users = $newEmployee->party->users;

                $newEmployee->users()->syncWithoutDetaching([$user->id]);

                $employeeInsertedTime = $newEmployee->insertedAt ?? $employeeRequest->appliedAt ?? $newEmployee->createdAt;

                if ($employeeInsertedTime) {
                    // Get employees in the same party that have users, were created after the current employee,
                    // and are not already associated with the current user.
                    // This ensures that the current user is linked to all relevant employee records in the party
                    // that were created after the user's creation time, while avoiding associations with future records
                    // that may not be relevant to the user's current session.
                    $employeesIds = $newEmployee->party
                        ->employees()
                        ->has('users')
                        ->where('user_id', '!=', $user->id)
                        ->where('inserted_at', '>', $employeeInsertedTime)
                        ->get()
                        ->pluck('id')
                        ->toArray();

                    // Sync current user to older employees in the party
                    $user->employees()->syncWithoutDetaching($employeesIds);

                    // Get users associated with the employee's party that were created before the employee record was inserted,
                    // ensuring proper association of existing users to the new employee record without linking future users
                    // that may not be relevant to the current session.
                    $userIds = $users
                        ->filter(fn($usr) => Carbon::parse($usr->inserted_at)->lessThan($employeeInsertedTime))
                        ->pluck('id')
                        ->all();

                    // Sync older users to the new employee
                    $newEmployee->users()->syncWithoutDetaching($userIds);
                }
            }
        });

        $newRoles = $this->getRolesForParty($user);

        setPermissionsTeamId($event->legalEntity->id);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        $user->assignRole($newRoles);
    }

    /**
     * This matching logic is fragile as it relies on text fields.
     * A more robust solution would be to use a unique token exchanged during the signing process.
     * This implementation is kept for now but should be considered for a future upgrade.
     */
    private function findMatchingLocalRequest(Collection $employeeRequests, array $employee): ?EmployeeRequest
    {
        return $employeeRequests->where('position', $employee['position'])
            ->where('employee_type', $employee['employee_type'])
            ->first(function (EmployeeRequest $employeeRequest) use ($employee) {
                $party = $employeeRequest->revision->data['party'];
                $namesMatch = $party['first_name'] === $employee['party']['first_name']
                    && $party['last_name'] === $employee['party']['last_name']
                    && $party['second_name'] === $employee['party']['second_name'];

                $eHealthDateString = $employee['start_date'] ?? null;

                if (is_null($eHealthDateString) || is_null($employeeRequest->start_date)) {
                    return false;
                }

                $datesMatch = Carbon::parse($employeeRequest->start_date)
                    ->isSameDay(Carbon::parse($eHealthDateString));

                return $namesMatch && $datesMatch;
            });
    }

    /**
     * Get roles based on employee types associated with the user's party.
     *
     * Only includes employee types where the user was created before or at the same time
     * as the employee record was inserted, ensuring proper role assignment chronology.
     *
     * @param  User  $user
     *
     * @return array<string> Array of unique employee type role identifiers.
     */
    protected function getRolesForParty(User $user): array
    {
        $roles = [];
        $userCreatedTime = Carbon::parse($user->inserted_at) ?? null;

        foreach ($user->party?->employees as $employee) {
            if ($employee->employeeType && $employee->insertedAt && $userCreatedTime && $userCreatedTime->lessThanOrEqualTo($employee->insertedAt)) {
                $roles[] = $employee->employeeType;
            }
        }

        return array_unique($roles);
    }
}
