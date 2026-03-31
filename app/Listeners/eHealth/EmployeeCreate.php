<?php

declare(strict_types=1);

namespace App\Listeners\eHealth;

use Log;
use Throwable;
use App\Core\Arr;
use Carbon\Carbon;
use App\Enums\JobStatus;
use App\Models\Relations\Party;
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
                        ->whereNotNull(['start_date', 'employee_id'])
                        ->latest('applied_at')
                )
            )
            ->orderByDesc('created_at')
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
            Log::info('[EmployeeCreate] No party_id found on any EmployeeRequest. User may be sent to KEP verification.', ['user_id' => $user->id]);
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
            ->whereNot('employee_type', 'OWNER') // We should not filter out OWNER type employees because they can be associated with the user later than other employee types due to their specific matching logic, so we can miss some employees if there is an OWNER type employee in the same legal entity, but we can not be sure that there is an OWNER type employee in the same legal entity,
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

                // If emloyee has already an associated user, skip attaching because it means it's already created by the stored user (user_id)
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

                $newEmployee->insertedAt ??= ($employeeRequest->appliedAt ?? Carbon::now());
                $newEmployee->status ??= JobStatus::PARTIAL;
                $newEmployee->divisionUuid ??= ($employeeRequest->divisionUuid ?? null);

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
                    $dataFromRevision['science_degree'] ?? null
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
            }
        });

        // This means (if NULL) that procceded the first OWNER's login, so we can skip role sync
        // because roles are assigned based on employee types and employee types are assigned based on employee records that are just created,
        // so if it's first login and OWNER, it means that there is no employee record with employee type OWNER before,
        // so roles will be assigned correctly through EmployeeDetailsUpsert job.
        if (!$event->legalEntity->employeeRequestSyncStatus) {
            return;
        }

        // All the going on below is need due to the fact that we need to assign roles based on employee types,
        // and employee types are assigned based on the employee records that are just created.
        if ($user?->party) {
            Repository::party()->syncUserEmployeesAndRoles($user->party, $event->legalEntity->id);
        }
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

                if (is_null($eHealthDateString)) {
                    return false;
                }

                // If start date is not provided in the request (mostly for OWNER), one cannot be sure that it's the same employee,
                // so we will try to find any employee with the same position and employee type and with the same party data,
                // and if there is only one such employee, we will assume that it's the same employee and use its start date
                // for comparison, otherwise we will return false because of ambiguity.
                if (is_null($employeeRequest->startDate)) {
                    switch ($employeeRequest->employeeType) {
                    case 'OWNER':
                        $partyUuid = $employee['party']['uuid'] ?? null;
                        $party = Party::where('uuid', $partyUuid)->first();

                        if (!$party) {
                            return false;
                        }

                        $employeeRequest->startDate = Employee::where('legal_entity_uuid', $employeeRequest->legalEntityUuid)
                            ->where('employee_type', $employeeRequest->employeeType)
                            ->where('position', $employee['position'])
                            ->where('party_id', $party->id)
                            ->first()?->startDate;
                        break;
                    default:
                        return false;
                    }
                }

                $datesMatch = Carbon::parse($employeeRequest->startDate)
                    ->isSameDay(Carbon::parse($eHealthDateString));

                return $namesMatch && $datesMatch;
            });
    }
}
