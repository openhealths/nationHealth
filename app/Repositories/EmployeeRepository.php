<?php

declare(strict_types=1);

namespace App\Repositories;

use Throwable;
use App\Models\LegalEntity;
use App\Models\Relations\Party;
use App\Models\Employee\Employee;
use Illuminate\Support\Facades\DB;
use App\Enums\Employee\RequestStatus;
use App\Models\Employee\EmployeeRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;

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
     * Builds a query for parties linked to employees of a legal entity, ordered by latest employee activity.
     *
     * @param  int  $legalEntityId
     * @return Builder
     */
    public function getPartiesWithLatestActivityQuery(int $legalEntityId): Builder
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
     * Resolve the incoming identity without changing another Party's UUID.
     * Missing UUIDs identify only the Party already linked to this model.
     */
    protected function updatePartyByUuid(Employee|EmployeeRequest $model, array $party): void
    {
        unset($party['email']);

        // Serialize updates to this employee/request and ignore stale loaded relations.
        // Do not refresh the whole caller: it can contain unsaved employee attributes.
        $storedModel = $model->newQuery()->whereKey($model->getKey())->lockForUpdate()->firstOrFail();
        $currentParty = $storedModel->party;
        $partyUuid = $party['uuid'] ?? null;

        if (is_string($partyUuid) && trim($partyUuid) !== '') {
            $resolvedParty = Party::where('uuid', $partyUuid)->first();

            // Preserve links to a local draft when this is its first remote identity.
            if (!$resolvedParty && $currentParty && !$currentParty->uuid) {
                $currentParty = Party::whereKey($currentParty->id)->lockForUpdate()->firstOrFail();
                if (!$currentParty->uuid) {
                    try {
                        DB::transaction(fn () => $currentParty->fill($party)->save());
                        $resolvedParty = $currentParty;
                    } catch (UniqueConstraintViolationException) {
                        // Another employee sync claimed the UUID. The savepoint keeps
                        // PostgreSQL usable so we can associate its canonical Party.
                        $resolvedParty = Party::where('uuid', $partyUuid)->firstOrFail();
                    }
                }
            }

            // Laravel also recovers competing inserts inside our transaction.
            $resolvedParty ??= Party::firstOrCreate(['uuid' => $partyUuid], $party);
            $resolvedParty = Party::whereKey($resolvedParty->id)->lockForUpdate()->firstOrFail();
            $resolvedParty->fill($party)->save();
        } else {
            // Never query WHERE uuid IS NULL or erase an already known identity.
            unset($party['uuid']);
            $resolvedParty = $currentParty ?? new Party();
            $resolvedParty->fill($party)->save();
        }

        // associate() also replaces the cached relation used by documents/phones sync.
        $model->party()->associate($resolvedParty);
        $model->save();
    }
}
