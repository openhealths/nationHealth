<?php

declare(strict_types=1);

namespace App\Repositories;

use Log;
use Throwable;
use App\Core\Arr;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\Relations\Party;
use App\Models\Employee\Employee;
use Illuminate\Support\Facades\DB;
use App\Enums\Employee\RequestStatus;
use App\Models\Employee\EmployeeRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

readonly class EmployeeRepository
{
    public function requestMatchesEmployee(EmployeeRequest $request, Employee $employee): bool
    {
        if ((int) $request->legalEntityId !== (int) $employee->legalEntityId
            || ($request->employeeId !== null && (int) $request->employeeId !== (int) $employee->id)
            || ($request->partyId !== null && (int) $request->partyId !== (int) $employee->partyId)
            || (filled($request->employeeType) && $request->employeeType !== $employee->employeeType)
            || (filled($request->position) && $request->position !== $employee->position)) {
            return false;
        }

        $employeeUuid = data_get($request->revision?->data, 'employee_uuid');
        $revisionEmployeeType = data_get($request->revision?->data, 'employee_request_data.employee_type');
        $revisionPosition = data_get($request->revision?->data, 'employee_request_data.position');
        if ((filled($employeeUuid) && $employeeUuid !== $employee->uuid)
            || (filled($revisionEmployeeType) && $revisionEmployeeType !== $employee->employeeType)
            || (filled($revisionPosition) && $revisionPosition !== $employee->position)) {
            return false;
        }

        if ($request->employeeId !== null || $request->partyId !== null) {
            return true;
        }

        $party = $employee->party;
        $partyUuid = data_get($request->revision?->data, 'party.uuid');
        $taxId = data_get($request->revision?->data, 'party.tax_id');

        return $party && ((filled($partyUuid) && $partyUuid === $party->uuid)
            || (filled($taxId) && $taxId === $party->taxId));
    }

    public function employeeForRequest(EmployeeRequest $request): ?Employee
    {
        $employee = $request->employee;

        return $employee && $this->requestMatchesEmployee($request, $employee) ? $employee : null;
    }

    public function latestDraftForEmployee(Employee $employee): ?EmployeeRequest
    {
        return EmployeeRequest::whereEmployeeId($employee->id)
            ->whereLegalEntityId($employee->legalEntityId)
            ->whereNull('uuid')
            ->whereNull('applied_at')
            ->with('revision')
            ->latest()
            ->latest('id')
            ->get()
            ->first(fn (EmployeeRequest $request): bool => $this->requestMatchesEmployee($request, $employee));
    }

    public function requestForEmployeeDetails(Employee $employee, ?string $divisionUuid, ?string $startDate): ?EmployeeRequest
    {
        $requests = EmployeeRequest::whereLegalEntityId($employee->legalEntityId)
            ->whereEmployeeType($employee->employeeType)
            ->wherePosition($employee->position)
            ->whereNotNull('uuid')
            ->where(fn (Builder $query) => $query->whereEmployeeId($employee->id)->orWhereNull('employee_id'))
            ->where(fn (Builder $query) => $query->wherePartyId($employee->partyId)->orWhereNull('party_id'))
            ->where('division_uuid', $divisionUuid)
            ->where('start_date', $startDate)
            ->with('revision')
            ->latest('applied_at')
            ->latest('id')
            ->get()
            ->filter(fn (EmployeeRequest $request): bool => $this->requestMatchesEmployee($request, $employee));

        $linked = $requests->first(fn (EmployeeRequest $request): bool => (int) $request->employeeId === (int) $employee->id);

        return $linked ?? ($requests->count() === 1 ? $requests->first() : null);
    }

    /** @return list<array<string, mixed>> */
    public function originalSpecialities(Employee|EmployeeRequest|null $source, ?int $legalEntityId, string $employeeType): array
    {
        $employee = $source instanceof EmployeeRequest ? $this->employeeForRequest($source) : $source;

        if (!$employee
            || !$employee->exists
            || (int) $employee->legalEntityId !== $legalEntityId
            || $employee->employeeType !== $employeeType
            || $employee->status !== \App\Enums\Status::APPROVED) {
            return [];
        }

        $employee->loadMissing('specialities');

        return Arr::toCamelCase($employee->specialities->toArray());
    }

    public function activeForLegalEntity(User $user, LegalEntity $legalEntity, array $employeeTypes): ?Employee
    {
        return $user->employees()->whereLegalEntityId($legalEntity->id)->active()->whereIn('employee_type', $employeeTypes)->first();
    }

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
     * Builds a query for parties linked to employees of a legal entity, ordered by latest employee activity.
     *
     * @param  int  $legalEntityId
     * @return Builder|QueryBuilder
     */
    public function getPartiesWithLatestActivityQuery(int $legalEntityId): Builder|QueryBuilder
    {
        $employeesQuery = Employee::selectRaw('party_id, MAX(updated_at) as last_employee_at')
            ->where('legal_entity_id', $legalEntityId)
            ->groupBy('party_id');

        return Party::query()
            ->select('parties.*')
            ->addSelect([
                'emp_stat.last_employee_at',
            ])
            ->leftJoinSub($employeesQuery, 'emp_stat', 'parties.id', '=', 'emp_stat.party_id')
            ->with([
                'phones',
                'employees' => fn ($q) => $q
                    ->where('legal_entity_id', $legalEntityId)
                    ->orderByDesc('updated_at')
                    ->with(['division']),
            ])
            ->orderByRaw("COALESCE(emp_stat.last_employee_at, '1970-01-01') DESC");
    }

    /**
     * The logic behind the party update or create is as follows:
     * 1. Check party by UUID. Possible scenario: the party already exists in the system
     * 2. If user already has a party, update it.
     * 3. If user does not have a party, but there is a party with the same UUID, update it and establish the relation.
     * 4. If neither of the above, create a new party and establish the relation.
     * 5. If the model is linked to a different Party than the one that already owns this UUID,
     *    relink to that Party and update it. Never copy the UUID onto the currently linked row.
     */
    protected function updatePartyByUuid(Employee|EmployeeRequest $model, array $party): void
    {
        unset($party['email']);
        $partyUuid = Arr::get($party, 'uuid');
        // A missing uuid must not become WHERE uuid IS NULL: that row is a different person
        // created moments earlier, and the next hire would overwrite their name.
        $partyByUuid = is_string($partyUuid) && $partyUuid !== ''
            ? Party::whereUuid($partyUuid)->first()
            : null;

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

            // If both the ID and UUID are the same, just update
            if ($partyByUuid->id === $model->party->id && $partyByUuid->uuid === $model->party->uuid) {
                $model->party()->update($party);
            } else {
                // party()->update() is a mass update constrained to the current party_id
                // at the moment party() is called. Writing this payload there copies the
                // eHealth UUID onto the draft row and hits parties_uuid_unique.
                // Relink first, then update the row that already owns the UUID.
                $previousPartyUuid = $model->party->uuid;

                $model->party()->associate($partyByUuid)->save();
                $partyByUuid->update($party);

                Log::warning('Potential party merge scenario detected', [
                    'model_party_uuid' => $previousPartyUuid,
                    'ehealth_party_uuid' => $partyByUuid->uuid,
                    'updated_with_ehealth_data' => true,
                    'relinked_to_existing_party' => true,
                ]);
            }
        }
    }
}
