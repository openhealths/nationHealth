<?php

declare(strict_types=1);

namespace App\Repositories;

use Log;
use Throwable;
use App\Core\Arr;
use App\Enums\Status;
use App\Models\LegalEntity;
use App\Models\Relations\Party;
use App\Models\User;
use App\Models\Employee\Employee;
use Illuminate\Support\Facades\DB;
use App\Enums\Employee\RequestStatus;
use App\Models\Employee\EmployeeRequest;
use Illuminate\Database\Eloquent\Builder;

readonly class EmployeeRepository
{
    /**
     * Creates a new EmployeeRequest draft from prepared data.
     * This is a universal method that only handles database persistence.
     *
     * @param  array  $employeeRequestData  The prepared data for the request itself.
     * @param  LegalEntity  $legalEntity  The associated LegalEntity model.
     * @param  Employee|null  $employee  (Optional) The existing employee being edited.
     * @return EmployeeRequest
     */
    public function createEmployeeRequestDraft(array $employeeRequestData, LegalEntity $legalEntity, ?Employee $employee = null): EmployeeRequest
    {
        $employeeRequest = new EmployeeRequest();
        $employeeRequest->fill($employeeRequestData);
        $employeeRequest->status = RequestStatus::NEW;
        $employeeRequest->legalEntity()->associate($legalEntity);

        if ($employee) {
            $employeeRequest->employee()->associate($employee);
        }

        $employeeRequest->save();

        return $employeeRequest;
    }

    /**
     * @param  Employee|EmployeeRequest  $employee  the model or identifier (ID or UUID) of the employee to update
     * @param  array  $party
     * @param  array  $documents
     * @param  array  $phones
     * @param  array|null  $educations
     * @param  array|null  $specialities
     * @param  array|null  $qualifications
     * @param  array|null  $scienceDegree
     * @return Employee|EmployeeRequest Updated employee
     * @throws Throwable
     */
    public function updateDetails(
        Employee|EmployeeRequest $employee,
        array $party,
        array $documents,
        array $phones,
        ?array $educations = null,
        ?array $specialities = null,
        ?array $qualifications = null,
        ?array $scienceDegree = null,
    ): Employee|EmployeeRequest {
        $model = $employee;

        DB::transaction(function () use ($model, $party, $documents, $phones, $educations, $specialities, $qualifications, $scienceDegree) {
            $partyAttributes = array_diff_key($party, array_flip(['documents', 'phones']));

            $this->updatePartyByUuid($model, $partyAttributes);

            $model->party->syncMany('documents', $documents);
            $model->party->syncMany('phones', $phones);
            $model->syncMany('educations', $educations);
            $model->syncMany('specialities', $specialities);
            $model->syncMany('qualifications', $qualifications);

            if (!empty($scienceDegree)) {
                $model->scienceDegree()->updateOrCreate([], $scienceDegree);
            } else {
                $model->scienceDegree()->delete();
            }
        });

        return $model;
    }

    /**
     * Returns a Query Builder for Parties, sorted by the latest activity date.
     *
     * Mechanism:
     * 1. Aggregates the latest 'updated_at' timestamp from the 'employees' table grouped by party.
     * 2. Aggregates the latest 'updated_at' timestamp from the 'employee_requests' table grouped by party.
     * 3. Joins these aggregated subqueries to the main 'parties' query.
     * 4. Sorts results by the greatest (most recent) timestamp found in either relation.
     *
     * @param  int  $legalEntityId
     * @return Builder
     */
    public function getPartiesWithLatestActivityQuery(int $legalEntityId): Builder
    {
        // 1. Subquery: Get the latest update time for Employees grouped by Party
        $employeesQuery = Employee::selectRaw('party_id, MAX(updated_at) as last_employee_at')
            ->where('legal_entity_id', $legalEntityId)
            ->groupBy('party_id');

        // 2. Subquery: Get the latest update time for Employee Requests grouped by Party
        $requestsQuery = EmployeeRequest::selectRaw('party_id, MAX(updated_at) as last_request_at')
            ->where('legal_entity_id', $legalEntityId)
            ->whereNotNull('party_id')
            ->groupBy('party_id');

        return Party::query()
            ->select('parties.*')
            // Add virtual columns for debugging
            ->addSelect([
                'emp_stat.last_employee_at',
                'req_stat.last_request_at'
            ])
            // 3. Join the subqueries
            ->leftJoinSub($employeesQuery, 'emp_stat', 'parties.id', '=', 'emp_stat.party_id')
            ->leftJoinSub($requestsQuery, 'req_stat', 'parties.id', '=', 'req_stat.party_id')

            // 4. Eager load relations
            ->with([
                'phones',
                'users',
                'employees' => fn ($q) => $q
                    ->where('legal_entity_id', $legalEntityId)
                    ->orderByDesc('updated_at')
                    ->with(['division', 'users']),
                'employeeRequests' => fn ($q) => $q
                    ->where('legal_entity_id', $legalEntityId)
                    ->whereIn('status', [Status::NEW->value, Status::SIGNED->value, Status::APPROVED->value])
                    ->orderByDesc('updated_at')
                    ->with(['revision', 'division'])
            ])

            // 5. Sorting: Compare dates and pick the most recent
            ->orderByRaw("GREATEST(COALESCE(emp_stat.last_employee_at, '1970-01-01'), COALESCE(req_stat.last_request_at, '1970-01-01')) DESC");
    }

    /**
     * Bind employees that have no owner yet to the users they belong to.
     *
     * Employees synced from eHealth arrive without `user_id`, so they never grant roles.
     * The owning account is resolved by the email of the employee request the position was
     * created from, because that email is what eHealth itself knows the position by.
     *
     * A position is deliberately not handed to an account merely for sharing a party. eHealth
     * grants scopes by its own role policies, so a role invented here makes it reject the whole
     * authorize request instead of just the surplus scopes, locking the user out of login.
     *
     * Sets `employees.user_id`, fills the `employee_users` pivot and links the user to the
     * party when the user has none yet.
     *
     * @param  LegalEntity  $legalEntity
     * @return array<int, int> IDs of parties whose employees were bound.
     */
    public function bindOwnerlessEmployeesToUsers(LegalEntity $legalEntity): array
    {
        $statuses = $legalEntity->status === Status::REORGANIZED->value
            ? [Status::APPROVED->value, Status::REORGANIZED->value]
            : [Status::APPROVED->value];

        $employees = Employee::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->whereNull('user_id')
            ->whereIn('status', $statuses)
            ->whereNotNull('party_id')
            ->get();

        if ($employees->isEmpty()) {
            return [];
        }

        $affectedPartyIds = [];

        foreach ($employees as $employee) {
            $owner = $this->resolveEmployeeOwner($employee);

            if (!$owner) {
                continue;
            }

            DB::transaction(function () use ($employee, $owner) {
                if ($owner->partyId === null) {
                    $owner->partyId = $employee->partyId;
                    $owner->save();
                }

                $employee->update(['user_id' => $owner->id]);
                $employee->users()->syncWithoutDetaching([$owner->id]);
            });

            $affectedPartyIds[] = (int) $employee->partyId;

            Log::info('Ownerless employee bound to user.', [
                'employee_id' => $employee->id,
                'user_id' => $owner->id,
                'legal_entity_id' => $legalEntity->id,
            ]);
        }

        return array_values(array_unique($affectedPartyIds));
    }

    /**
     * Account the position belongs to, identified by the email of its employee request.
     *
     * @param  Employee  $employee
     * @return User|null
     */
    protected function resolveEmployeeOwner(Employee $employee): ?User
    {
        $email = $this->resolveEmployeeOwnerEmail($employee);

        if (!$email) {
            return null;
        }

        $owner = User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->first();

        // Never steal an account that already identifies another person
        if ($owner && $owner->partyId !== null && (int) $owner->partyId !== (int) $employee->partyId) {
            return null;
        }

        return $owner;
    }

    /**
     * Email of the account that owns the position, taken from its employee request.
     *
     * @param  Employee  $employee
     * @return string|null
     */
    protected function resolveEmployeeOwnerEmail(Employee $employee): ?string
    {
        $byEmployeeId = EmployeeRequest::query()
            ->where('employee_id', $employee->id)
            ->whereNotNull('email')
            ->latest('applied_at')
            ->value('email');

        if ($byEmployeeId) {
            return $byEmployeeId;
        }

        return EmployeeRequest::query()
            ->where('legal_entity_id', $employee->legalEntityId)
            ->where('employee_type', $employee->employeeType)
            ->where('position', $employee->position)
            ->when(
                $employee->getRawOriginal('start_date') === null,
                fn (Builder $query) => $query->whereNull('start_date'),
                fn (Builder $query) => $query->where('start_date', $employee->getRawOriginal('start_date'))
            )
            ->whereNotNull('email')
            ->latest('applied_at')
            ->value('email');
    }

    /**
     * The logic behind the party update or create is as follows:
     * 1. Check party by UUID. Possible scenario: the party already exists in the system
     * 2. If user already has a party, update it.
     * 3. If user does not have a party, but there is a party with the same UUID, update it and establish the relation.
     * 4. If neither of the above, create a new party and establish the relation.
     */
    protected function updatePartyByUuid(Employee|EmployeeRequest $model, array $party): void
    {
        unset($party['email']);
        $partyUuid = Arr::get($party, 'uuid');
        $partyByUuid = Party::where('uuid', $partyUuid)->first();

        // If the model doesn't have a party and party doesn't exist, create new one. It's a brand-new person
        if (!$partyByUuid && !$model->party) {
            $newParty = new Party($party);
            $newParty->save();
            $model->party()->associate($newParty)->save();

            // If the model doesn't have a related party but the party already exists, update it and relate - the scenario of a new employee with already created person/party
        } elseif ($partyByUuid && !$model->party) {
            $partyByUuid->update($party);
            $model->party()->associate($partyByUuid)->save();

            // The model already has a related party, update it and change the UUID - the case when eHealth creates another party, probably merge scenario
        } elseif (!$partyByUuid && $model->party) {
            $model->party()->update($party);

            // Both the model and the party exist, check if they are the same
        } elseif ($partyByUuid && $model->party) {

            // uuid is the same, just update
            if ($partyByUuid->uuid === $model->party->uuid) {
                $model->party()->update($party);
            } else {
                // Different uuid, need to merge the results, prioritizing the eHealth data
                $model->party()->update($party);

                Log::warning('Potential party merge scenario detected', [
                    'model_party_uuid' => $model->party->uuid,
                    'ehealth_party_uuid' => $partyByUuid->uuid,
                    'updated_with_ehealth_data' => true
                ]);
            }
        }
    }
}
