<?php

declare(strict_types=1);

namespace App\Listeners\eHealth;

use Log;
use Throwable;
use App\Core\Arr;
use Carbon\Carbon;
use App\Models\User;
use App\Enums\Status;
use RuntimeException;
use App\Enums\JobStatus;
use App\Enums\User\Role;
use App\Models\Relations\Party;
use App\Classes\eHealth\EHealth;
use App\Events\EHealthUserLogin;
use App\Jobs\EmployeeRequestPendingApply;
use App\Repositories\Repository;
use App\Models\Employee\Employee;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use App\Enums\Employee\RequestStatus;
use App\Enums\Employee\RevisionStatus;
use App\Models\Employee\EmployeeRequest;
use App\Models\LegalEntity;
use App\Services\Employee\EmployeeRequestMatcher;
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
            ->where(
                fn (EloquentBuilder $q) => $q
                    // Pending eHealth decision: NEW + uuid (current keep-NEW) or legacy SIGNED
                    ->where(fn (EloquentBuilder $query) => $query->pendingEhealth())
                    // Sync for requests approved through our system and synced before user's first login
                    ->orWhere(
                        fn (EloquentBuilder $query) =>
                        $query->where('status', RequestStatus::APPROVED)
                            ->whereNotNull(['start_date', 'employee_id', 'user_id'])
                            ->where('user_id', $user->id)
                            ->whereHas(
                                'employee',
                                fn (EloquentBuilder $query) =>
                                $query->whereNull('user_id')
                            )
                    )
                    // Sync for requests that weren't approved through our system, were imported from EHealth
                    ->orWhere(
                        fn (EloquentBuilder $query) =>
                        $query->where('status', RequestStatus::APPROVED)
                            ->whereNull('user_id')
                            ->whereNotNull(['start_date', 'employee_id'])
                            ->latest('applied_at')
                    )
            )
            ->orderByDesc('created_at')
            ->get();

        if ($employeeRequests->isEmpty()) {
            Log::info('[EmployeeCreate] No pending/approved employee requests for user email.', [
                'user_id' => $user->id,
                'email' => $user->email,
            ]);

            return;
        }

        Log::info('[EmployeeCreate] Found employee requests to process.', [
            'user_id' => $user->id,
            'request_ids' => $employeeRequests->pluck('id')->all(),
            'statuses' => $employeeRequests->map(fn (EmployeeRequest $r) => $r->status?->value)->all(),
        ]);

        $requestWithParty = $employeeRequests->whereNotNull('party_id')->first();

        if ($requestWithParty) {
            $user->party()->associate($requestWithParty->partyId);
            $user->save();
            $user->refresh();
            Log::info('[EmployeeCreate] Associated new User with existing Party.', ['user_id' => $user->id, 'party_id' => $requestWithParty->partyId]);
        } else {
            Log::info('[EmployeeCreate] No party_id found on any EmployeeRequest. Will try eHealth employee sync.', ['user_id' => $user->id]);
        }

        $taxIds = $this->collectTaxIds($employeeRequests);

        if ($taxIds->isEmpty()) {
            Log::warning('[EmployeeCreate] No tax_id found in any pending request revision.', [
                'user_id' => $user->id,
                'request_ids' => $employeeRequests->pluck('id')->all(),
            ]);

            return;
        }

        $employees = $this->fetchApprovedEmployeesByTaxIds($event->legalEntity->uuid, $taxIds);

        if (empty($employees)) {
            Log::warning('[EmployeeCreate] eHealth returned no APPROVED/REORGANIZED employees for tax_ids.', [
                'user_id' => $user->id,
                'tax_ids' => $taxIds->all(),
                'legal_entity_uuid' => $event->legalEntity->uuid,
            ]);

            return;
        }

        // This filters out only uuids associated with the current user
        $existingUuids = Employee::whereIn('uuid', array_column($employees, 'uuid'))
            ->whereIn('status', [Status::APPROVED, Status::REORGANIZED])
            ->where('legal_entity_id', $event->legalEntity->id)
            ->whereNotIn('id', $employeeRequests->pluck('employee_id')->filter()->all())
            ->whereHas('users', fn (EloquentBuilder $query) => $query->where('id', $user->id))
            ->pluck('uuid')
            ->all();

        $employees = array_filter($employees, fn (array $employee) => !in_array($employee['uuid'], $existingUuids, true));

        if (empty($employees)) {
            Log::info('[EmployeeCreate] All remote employees already linked to this user.', [
                'user_id' => $user->id,
            ]);

            return;
        }

        // One list call: uuid → remote status. Missing uuids are "unknown" and fall back to jobs.
        $remoteRequestStatuses = $this->fetchRemoteRequestStatusMap($event->legalEntity);

        $matched = 0;
        $pendingEditsToQueue = collect();

        DB::transaction(function () use (
            $user,
            $employees,
            $employeeRequests,
            $event,
            $remoteRequestStatuses,
            &$matched,
            &$pendingEditsToQueue
        ) {
            foreach ($employees as $eHealthEmployee) {
                $employeeRequest = $this->findMatchingLocalRequest($employeeRequests, $eHealthEmployee);

                if (!$employeeRequest) {
                    Log::info('[EmployeeCreate] No local request matched remote employee.', [
                        'user_id' => $user->id,
                        'employee_uuid' => $eHealthEmployee['uuid'] ?? null,
                        'position' => $eHealthEmployee['position'] ?? null,
                        'employee_type' => $eHealthEmployee['employee_type'] ?? null,
                    ]);

                    continue;
                }

                // Pending edit: never apply from "employee is APPROVED" alone — use list status first.
                if (
                    $employeeRequest->status !== RequestStatus::APPROVED
                    && $employeeRequest->isPendingEhealth()
                    && filled($employeeRequest->employeeId)
                ) {
                    $action = $this->resolvePendingEditAction(
                        $remoteRequestStatuses->get($employeeRequest->uuid)
                    );

                    if ($action === 'queue') {
                        $pendingEditsToQueue->push($employeeRequest);

                        Log::info('[EmployeeCreate] Pending edit not on list page; queue details job.', [
                            'user_id' => $user->id,
                            'request_id' => $employeeRequest->id,
                            'request_uuid' => $employeeRequest->uuid,
                        ]);

                        continue;
                    }

                    if ($action === 'skip') {
                        Log::info('[EmployeeCreate] Pending edit still NEW/SIGNED on list; skip apply.', [
                            'user_id' => $user->id,
                            'request_id' => $employeeRequest->id,
                            'request_uuid' => $employeeRequest->uuid,
                        ]);

                        continue;
                    }

                    if ($action === 'reject' || $action === 'expire') {
                        $newStatus = $action === 'reject' ? RequestStatus::REJECTED : RequestStatus::EXPIRED;
                        $employeeRequest->update([
                            'status' => $newStatus,
                            'applied_at' => now(),
                        ]);
                        $employeeRequest->revision?->update(['status' => RevisionStatus::OUTDATED]);

                        Log::info('[EmployeeCreate] Pending edit marked terminal from list status.', [
                            'user_id' => $user->id,
                            'request_id' => $employeeRequest->id,
                            'status' => $newStatus->value,
                        ]);

                        continue;
                    }

                    // 'apply' — remote list says APPROVED; fall through to sync apply below.
                    Log::info('[EmployeeCreate] Pending edit APPROVED on list; applying now.', [
                        'user_id' => $user->id,
                        'request_id' => $employeeRequest->id,
                        'request_uuid' => $employeeRequest->uuid,
                    ]);
                }

                // If the employee type is OWNER, we need to check if the current owner is different from the one in EHealth.
                if ($eHealthEmployee['employee_type'] === Role::OWNER->value) {
                    $currOwner = $event->legalEntity->getOwner();

                    // $currOwner = null when the Legal Entity just created, so we don't need to change anything in this case.
                    if ($currOwner?->uuid && $currOwner->uuid !== $eHealthEmployee['uuid']) {
                        $currentOwnerUser = User::find($currOwner->userId);

                        // Just overcautiousness
                        if ($currentOwnerUser) {
                            Repository::legalEntity()->disableOldOwner($currentOwnerUser, $event->legalEntity);
                        } else {
                            Log::error('[EmployeeCreate] User not found for current owner.', [
                               'user_id' => $currOwner->userId,
                               'legal_entity_uuid' => $event->legalEntity->uuid,
                               'employee_uuid' => $eHealthEmployee['uuid'] ?? null,
                            ]);

                            throw new RuntimeException(__('auth.login.error.owner_replacement.current_owner_user_not_found'));
                        }
                    }
                }

                // If employee has already an associated user, skip attaching because it means it's already created by the stored user (user_id)
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
                $matched++;
            }
        });

        if ($matched === 0) {
            Log::warning('[EmployeeCreate] Remote employees found but none matched local requests.', [
                'user_id' => $user->id,
                'remote_count' => count($employees),
                'request_ids' => $employeeRequests->pluck('id')->all(),
            ]);
        }

        $this->dispatchPendingEditApplyJobs($pendingEditsToQueue->unique('id')->values(), $event);

        // This means (if NULL) that proceeded the first OWNER's login, so we can skip role sync
        // because roles are assigned based on employee types and employee types are assigned based on employee records that are just created,
        // so if it's first login and OWNER, it means that there is no employee record with employee type OWNER before,
        // so roles will be assigned correctly through EmployeeDetailsUpsert job.
        if (!$event->legalEntity->employeeRequestSyncStatus) {
            return;
        }

        // All the going on below is need due to the fact that we need to assign roles based on employee types,
        // and employee types are assigned based on the employee records that are just created.
        $user->refresh();

        if ($user?->party) {
            Repository::party()->syncUserEmployeesAndRoles($user->party, $event->legalEntity);
        }
    }

    /**
     * @param  Collection<int, EmployeeRequest>  $employeeRequests
     * @return Collection<int, string>
     */
    private function collectTaxIds(Collection $employeeRequests): Collection
    {
        return $employeeRequests
            ->map(fn (EmployeeRequest $request) => data_get($request->revision?->data, 'party.tax_id'))
            ->filter(fn ($taxId) => is_string($taxId) && $taxId !== '')
            ->unique()
            ->values();
    }

    /**
     * One EmployeeRequest list page (max page_size). Fresh approvals tend to appear first.
     * Absence from the map means "unknown" — caller should fall back to details jobs.
     *
     * @return Collection<string, string> uuid => remote status
     */
    private function fetchRemoteRequestStatusMap(LegalEntity $legalEntity): Collection
    {
        try {
            $filters = [
                'page_size' => (int) config('ehealth.api.page_size', 300),
            ];

            if (filled($legalEntity->edrpou)) {
                $filters['edrpou'] = $legalEntity->edrpou;
            }

            $page = EHealth::employeeRequest()
                ->getMany($filters, 1)
                ->validate();

            /** @var Collection<string, string> $statuses */
            $statuses = collect($page)
                ->filter(static fn ($row): bool => is_array($row) && is_string($row['uuid'] ?? null))
                ->mapWithKeys(static function (array $row): array {
                    $status = $row['status'] ?? null;
                    $statusValue = $status instanceof \BackedEnum ? $status->value : $status;

                    return is_string($statusValue) && $statusValue !== ''
                        ? [$row['uuid'] => $statusValue]
                        : [];
                });

            Log::info('[EmployeeCreate] Loaded remote EmployeeRequest status map.', [
                'legal_entity_id' => $legalEntity->id,
                'count' => $statuses->count(),
            ]);

            return $statuses;
        } catch (Throwable $e) {
            Log::warning('[EmployeeCreate] EmployeeRequest list failed; pending edits will use details jobs.', [
                'legal_entity_id' => $legalEntity->id,
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    /**
     * Decide how to handle a pending edit given an optional status from the list page.
     *
     * @return 'apply'|'skip'|'queue'|'reject'|'expire'
     */
    private function resolvePendingEditAction(?string $remoteStatus): string
    {
        if ($remoteStatus === null || $remoteStatus === '') {
            return 'queue';
        }

        if (EmployeeRequestMatcher::isRemoteStillPending($remoteStatus)) {
            return 'skip';
        }

        if ($remoteStatus === 'REJECTED') {
            return 'reject';
        }

        if ($remoteStatus === 'EXPIRED') {
            return 'expire';
        }

        if ($remoteStatus === RequestStatus::APPROVED->value) {
            return 'apply';
        }

        return 'skip';
    }

    /**
     * Queue details jobs only for pending edits whose status was not on the list page.
     *
     * @param  Collection<int, EmployeeRequest>  $pendingEdits
     */
    private function dispatchPendingEditApplyJobs(Collection $pendingEdits, EHealthUserLogin $event): void
    {
        $pendingEdits = $pendingEdits
            ->filter(fn (EmployeeRequest $request) => filled($request->uuid))
            ->values();

        if ($pendingEdits->isEmpty()) {
            return;
        }

        $previousJob = null;

        foreach ($pendingEdits->reverse() as $request) {
            $previousJob = new EmployeeRequestPendingApply(
                employeeRequest: $request,
                legalEntity: $event->legalEntity,
                nextEntity: $previousJob,
                standalone: $previousJob === null,
            );
        }

        Bus::batch([$previousJob])
            ->name(EmployeeRequestPendingApply::BATCH_NAME)
            ->withOption('legal_entity_id', $event->legalEntity->id)
            ->withOption('token', $event->token)
            ->withOption('user', $event->user)
            ->withOption('sync_entity', LegalEntity::ENTITY_EMPLOYEE_REQUEST)
            ->onQueue('sync')
            ->dispatch();

        Log::info('[EmployeeCreate] Dispatched pending edit apply job chain for unknown list statuses.', [
            'user_id' => $event->user->id,
            'request_ids' => $pendingEdits->pluck('id')->all(),
        ]);
    }

    /**
     * @param  Collection<int, string>  $taxIds
     * @return list<array<string, mixed>>
     */
    private function fetchApprovedEmployeesByTaxIds(string $legalEntityUuid, Collection $taxIds): array
    {
        $employees = [];

        foreach ($taxIds as $taxId) {
            try {
                $page = EHealth::employee()->getMany(
                    [
                        'legal_entity_id' => $legalEntityUuid,
                        'tax_id' => $taxId,
                    ]
                )->validate();
            } catch (Throwable $e) {
                Log::error('[EmployeeCreate] Failed to fetch employees from eHealth.', [
                    'tax_id' => $taxId,
                    'legal_entity_uuid' => $legalEntityUuid,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            foreach ($page as $employee) {
                if (!in_array($employee['status'] ?? null, [Status::APPROVED->value, Status::REORGANIZED->value], true)) {
                    continue;
                }

                $uuid = $employee['uuid'] ?? null;
                if ($uuid === null) {
                    continue;
                }

                $employees[$uuid] = $employee;
            }
        }

        return array_values($employees);
    }

    /**
     * This matching logic is fragile as it relies on text fields.
     * A more robust solution would be to use a unique token exchanged during the signing process.
     * This implementation is kept for now but should be considered for a future upgrade.
     *
     * @param  Collection<int, EmployeeRequest>  $employeeRequests
     * @param  array<string, mixed>  $employee
     */
    private function findMatchingLocalRequest(Collection $employeeRequests, array $employee): ?EmployeeRequest
    {
        $remoteTaxId = $employee['party']['tax_id'] ?? null;

        $candidates = $employeeRequests
            ->when(
                is_string($remoteTaxId) && $remoteTaxId !== '',
                fn (Collection $requests) => $requests->filter(
                    fn (EmployeeRequest $request) => data_get($request->revision?->data, 'party.tax_id') === $remoteTaxId
                )
            )
            ->where('position', $employee['position'])
            ->where('employee_type', $employee['employee_type']);

        return $candidates->first(function (EmployeeRequest $employeeRequest) use ($employee) {
            $party = $employeeRequest->revision->data['party'];
            $namesMatch = $party['first_name'] === $employee['party']['first_name']
                && $party['last_name'] === $employee['party']['last_name']
                && $party['second_name'] === $employee['party']['second_name'];

            $eHealthDateString = $employee['start_date'] ?? null;

            if (is_null($eHealthDateString)) {
                return false;
            }

            // If start date is not provided in the request or names do not match, one cannot be sure that it's the same employee,
            // so we will try to find any employee with the same position and employee type and with the same party data,
            // and if there is only one such employee, we will assume that it's the same employee and use its start date
            // for comparison, otherwise we will return false because of ambiguity.
            if (is_null($employeeRequest->startDate) || !$namesMatch) {
                $partyUuid = $employee['party']['uuid'] ?? null;
                $party = Party::where('uuid', $partyUuid)->first();

                if (!$party) {
                    return false;
                }

                $employeeRequest->startDate = Employee::matchingEmployee(
                    legalEntityUuid: $employeeRequest->legalEntityUuid,
                    employeeType: $employeeRequest->employeeType,
                    position: $employeeRequest->position,
                    partyId: $party->id,
                )
                    ->first()
                        ? $employeeRequest->revision->data['employee_request_data']['start_date']
                        : null;

                if (!$employeeRequest->startDate) {
                    return false;
                }
                $namesMatch = true; // If we have found the employee by other parameters and got the start date,
                // we can assume that names match because of the uniqueness of the employee record

            }

            $datesMatch = EmployeeRequestMatcher::datesMatchSameDay(
                $employeeRequest->startDate,
                $eHealthDateString
            );

            return $namesMatch && $datesMatch;
        });
    }
}
