<?php

declare(strict_types=1);

namespace App\Services\Employee;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\Employee\RequestStatus as LocalStatus;
use App\Enums\Employee\RevisionStatus;
use App\Enums\JobStatus;
use App\Enums\Status;
use App\Models\Division;
use App\Models\Employee\Employee;
use App\Models\Employee\EmployeeRequest;
use App\Models\LegalEntity;
use App\Repositories\Repository;
use App\Traits\BatchLegalEntityQueries;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EmployeeRequestProcessor
{
    use BatchLegalEntityQueries;

    public const string OUTCOME_APPROVED = 'approved';
    public const string OUTCOME_REJECTED = 'rejected';
    public const string OUTCOME_EXPIRED = 'expired';
    public const string OUTCOME_PENDING = 'pending';
    public const string OUTCOME_FAILED = 'failed';

    public function __construct(private EmployeeRequestMatcher $matcher)
    {
    }

    /**
     * Sync one pending local employee request against eHealth (same outcome as login EmployeeCreate).
     *
     * @return array{outcome: string, message: string}
     */
    public function syncSinglePendingRequest(EmployeeRequest $request, LegalEntity $legalEntity): array
    {
        if (!$request->isPendingEhealth() || !$request->uuid) {
            return [
                'outcome' => self::OUTCOME_FAILED,
                'message' => __('employees.sync.employee_request_not_pending'),
            ];
        }

        $request->loadMissing(['revision', 'employee', 'party', 'division']);

        if (!session()->get(config('ehealth.api.oauth.bearer_token'))) {
            return [
                'outcome' => self::OUTCOME_FAILED,
                'message' => __('employees.sync.session_token_missing'),
            ];
        }

        $remoteData = EHealth::employeeRequest()
            ->getDetails($request->uuid)
            ->validate();

        $remoteStatus = $remoteData['status'] instanceof \BackedEnum
            ? $remoteData['status']->value
            : $remoteData['status'];

        // One outcome / one message only. Remote NEW with uuid is keep-NEW in ESOZ
        // (submitted, often awaiting email) — not a local unsigned draft (those have no uuid
        // and never reach getDetails here).
        switch ($remoteStatus) {
            case 'REJECTED':
                $request->update([
                    'status' => LocalStatus::REJECTED,
                    'applied_at' => $this->remoteAppliedAt($remoteData),
                ]);
                $request->revision?->update(['status' => RevisionStatus::OUTDATED]);

                return [
                    'outcome' => self::OUTCOME_REJECTED,
                    'message' => __('employees.sync.employee_request_status_updated', ['status' => $remoteStatus]),
                ];

            case 'EXPIRED':
                $request->update([
                    'status' => LocalStatus::EXPIRED,
                    'applied_at' => $this->remoteAppliedAt($remoteData),
                ]);
                $request->revision?->update(['status' => RevisionStatus::OUTDATED]);

                return [
                    'outcome' => self::OUTCOME_EXPIRED,
                    'message' => __('employees.sync.employee_request_status_updated', ['status' => $remoteStatus]),
                ];

            case 'NEW':
            case 'SIGNED':
                // Do not apply revision: APPROVED Employee may already exist (edit flow).
                return [
                    'outcome' => self::OUTCOME_PENDING,
                    'message' => __('employees.sync.employee_request_still_pending'),
                ];

            case LocalStatus::APPROVED->value:
                break;

            default:
                return [
                    'outcome' => self::OUTCOME_FAILED,
                    'message' => __('employees.sync.employee_request_status_updated', ['status' => (string) $remoteStatus]),
                ];
        }

        // Get EmployeeRequest Data has no employee_id — resolve via local edit link or Employee list search.
        $taxId = data_get($request->revision?->data, 'party.tax_id');
        $remoteEmployee = null;
        $employeeUuid = null;

        if (filled($request->employeeId)) {
            $employeeUuid = Employee::whereKey($request->employeeId)->value('uuid');
        }

        if ($employeeUuid === null && is_string($taxId) && $taxId !== '') {
            $remoteEmployee = $this->matcher->findApprovedForRequest(
                $request,
                $taxId,
                $legalEntity->uuid
            );
            $employeeUuid = is_string($remoteEmployee['uuid'] ?? null) ? $remoteEmployee['uuid'] : null;
        }

        if ($employeeUuid === null) {
            return [
                'outcome' => self::OUTCOME_FAILED,
                'message' => __('employees.sync.no_employees_found'),
            ];
        }

        $applyPayload = array_merge($remoteData, $remoteEmployee ?? []);
        $applyPayload['employee_id'] = $employeeUuid;
        $applyPayload['status'] = $remoteEmployee['status'] ?? Status::APPROVED->value;
        $applyPayload['legal_entity_id'] = $applyPayload['legal_entity_id'] ?? $legalEntity->uuid;

        $this->applyApprovedRequest($request, $applyPayload);

        return [
            'outcome' => self::OUTCOME_APPROVED,
            'message' => __('employees.sync.employee_request_success'),
        ];
    }

    /**
     * Applies data from an APPROVED eHealth request to the local Employee entity.
     * Since the User Token response does not contain the created 'employee_id',
     * this method resolves the UUID by searching eHealth via Tax ID.
     *
     * @param  EmployeeRequest  $request  The local request entity.
     * @param  array  $eHealthData  Data array from the API response (primarily for status confirmation).
     * @throws \Throwable
     */
    public function applyApprovedRequest(EmployeeRequest $request, array $eHealthData): void
    {
        Log::info('[EmployeeRequestProcessor] Start Apply.', [
            'request_uuid' => $request->uuid,
            'eHealth_status' => $eHealthData['status'] ?? 'N/A',
        ]);

        DB::transaction(function () use ($request, $eHealthData) {
            // 1. Prepare Local Data (Source of Truth for content)
            $revisionData = $request->revision->data;
            $mappedLocalData = EHealth::employeeRequest()->mapCreate($revisionData);

            $taxId = $mappedLocalData['party']['tax_id'] ?? null;

            if (!$taxId) {
                Log::error(
                    '[EmployeeRequestProcessor] Critical: Tax ID is missing in revision data for approved request.',
                    ['request_id' => $request->id]
                );
                // [EN: Throw an exception to halt the transaction if Tax ID is missing]
                throw new \RuntimeException('Cannot apply approved request: Tax ID is missing.');
            }

            // 2. Resolve Employee UUID (from eHealth request details or Tax ID search)
            $employeeUuid = $eHealthData['employee_id']
                ?? $eHealthData['employee_uuid']
                ?? $this->resolveEmployeeUuid($request, $taxId);

            if (!$employeeUuid) {
                throw new \RuntimeException(
                    "Critical: Could not resolve Employee UUID from eHealth by searching Tax ID for Request {$request->id}"
                );
            }

            // 3. Find existing Employee by UUID or instantiate a new one
            $employee = Employee::where('uuid', $employeeUuid)->first();

            // Fallback for update scenarios (if we have a local link)
            if (!$employee && $request->employeeId) {
                $employee = Employee::find($request->employeeId);
            }

            $isNew = false;
            if (!$employee) {
                $isNew = true;
                Log::info("[EmployeeRequestProcessor] Creating NEW Employee locally with UUID {$employeeUuid}");

                $employee = new Employee();
                $employee->uuid = $employeeUuid;
            }

            // 4. Prepare System Overrides
            // We prioritize eHealth status and dates, but use local ID for Division
            $systemOverrides = Arr::only(
                $eHealthData,
                ['status', 'start_date', 'end_date', 'position', 'employee_type']
            );

            // Handle Division: API returns UUID, we need local ID
            if (isset($eHealthData['division_id'])) {
                $divisionUuid = $eHealthData['division_id'];
                if (is_string($divisionUuid) && strlen($divisionUuid) === 36) {
                    $division = Division::where('uuid', $divisionUuid)->first();
                    if ($division) {
                        $systemOverrides['division_id'] = $division->id;
                    }
                    // If not found locally, we rely on the Revision data (mappedLocalData) which has the correct int ID
                } else {
                    $systemOverrides['division_id'] = $divisionUuid;
                }
            }

            // 5. Merge Data: Revision (Base) + System Overrides
            $finalEmployeeData = array_merge(
                $mappedLocalData['employee'],
                $systemOverrides
            );

            // 6. Fill Model
            $employee->fill($finalEmployeeData);

            if ($isNew) {
                $employee->uuid = $employeeUuid; // Ensure UUID is set
                $employee->legalEntityId = $request->legalEntityId;
                $employee->userId = $request->userId;
                $employee->status = $systemOverrides['status'] ?? Status::APPROVED->value;

                if ($request->partyId) {
                    $employee->partyId = $request->partyId;
                }
            }

            // 7. Save to DB
            $employee->save();

            Log::info("[EmployeeRequestProcessor] Employee Saved. ID: {$employee->id}");

            // 8. Link Request to Employee
            if ($request->employeeId !== $employee->id) {
                $request->update([
                                     'employee_id' => $employee->id,
                                     'party_id' => $employee->partyId ?? $request->partyId,
                                 ]);
            }

            // 9. Update Details (Party, Documents, Phones...)
            Repository::employee()->updateDetails(
                $employee,
                $mappedLocalData['party'],
                $mappedLocalData['documents'],
                $mappedLocalData['phones'],
                $mappedLocalData['educations'] ?? null,
                $mappedLocalData['specialities'] ?? null,
                $mappedLocalData['qualifications'] ?? null,
                $mappedLocalData['scienceDegree'] ?? null
            );

            // 10. Assign Roles to User
            $this->assignUserRoles($employee, $request->legalEntityId, $request->userId);

            // 11. Finalize Request Status (applied_at/inserted_at = remote updated_at for role windows)
            $appliedAt = $this->remoteAppliedAt($eHealthData);
            if (blank($employee->insertedAt)) {
                $employee->insertedAt = $appliedAt;
                $employee->save();
            }

            $request->update([
                'status' => LocalStatus::APPROVED,
                'applied_at' => $appliedAt,
                'inserted_at' => $appliedAt,
            ]);

            if ($request->revision) {
                $request->revision->update(['status' => RevisionStatus::APPLIED]);
            }

            $this->markOlderPendingEditsSuperseded($request);
        });
    }

    /**
     * After a newer edit is applied, close older still-pending edits for the same employee
     * in this LE so login/sync cannot re-apply yesterday's phones/documents.
     */
    public function markOlderPendingEditsSuperseded(EmployeeRequest $applied): void
    {
        if (blank($applied->employeeId) || blank($applied->legalEntityId)) {
            return;
        }

        $olderPending = EmployeeRequest::query()
            ->with('revision')
            ->filterByLegalEntityId((int) $applied->legalEntityId)
            ->where('employee_id', $applied->employeeId)
            ->where('id', '!=', $applied->id)
            ->pendingEhealth()
            ->whereNotNull('uuid')
            ->where(function ($query) use ($applied): void {
                $query->where('created_at', '<', $applied->created_at)
                    ->orWhere(function ($inner) use ($applied): void {
                        $inner->where('created_at', $applied->created_at)
                            ->where('id', '<', $applied->id);
                    });
            })
            ->get();

        foreach ($olderPending as $oldRequest) {
            $oldRequest->update([
                'status' => LocalStatus::EXPIRED,
                'applied_at' => now(),
            ]);
            $oldRequest->revision?->update(['status' => RevisionStatus::OUTDATED]);
        }
    }

    /**
     * eHealth request updated_at is the authoritative applied_at for terminal statuses.
     */
    private function remoteAppliedAt(array $remoteData): Carbon
    {
        return filled($remoteData['updated_at'] ?? null)
            ? Carbon::parse($remoteData['updated_at'])
            : now();
    }

    /**
     * Resolves the Employee UUID by searching eHealth using Tax ID.
     * Since User Token endpoints do not return the created ID, we must find it
     * by matching Tax ID + Position + Start Date within the current Legal Entity.
     */
    private function resolveEmployeeUuid(EmployeeRequest $request, string $taxId): ?string
    {
        $remote = $this->matcher->findApprovedForRequest($request, $taxId, legalEntity()->uuid);

        return $remote['uuid'] ?? null;
    }

    /**
     * Among approved local requests, keep only the newest per employee_id.
     * Creates (no employee_id) are kept individually. Older superseded edits are returned separately.
     *
     * @param  Collection<int, EmployeeRequest>  $approvedRequests
     * @return array{apply: Collection<int, EmployeeRequest>, superseded: Collection<int, EmployeeRequest>}
     */
    public function partitionLatestApprovedPerEmployee(Collection $approvedRequests): array
    {
        $sorted = $approvedRequests
            ->sortBy(fn (EmployeeRequest $request): array => [
                $request->created_at?->timestamp ?? 0,
                $request->id,
            ])
            ->values();

        $apply = collect();
        $superseded = collect();

        foreach ($sorted->groupBy(
            fn (EmployeeRequest $request): string => filled($request->employeeId)
                ? 'employee-'.$request->employeeId
                : 'create-'.$request->id
        ) as $group) {
            /** @var Collection<int, EmployeeRequest> $group */
            $latest = $group->last();
            $apply->push($latest);
            $superseded = $superseded->merge($group->filter(
                fn (EmployeeRequest $request): bool => $request->id !== $latest->id
            ));
        }

        return [
            'apply' => $apply->values(),
            'superseded' => $superseded->values(),
        ];
    }

    /**
     * Processes a batch of remote Employee Request data from eHealth.
     */
    public function processBatch(array $eHealthData, LegalEntity $legalEntity): void
    {
        // Fix for single object response vs array response
        // If eHealth returns a single associative array (has 'uuid' or 'id'), wrap it in a list.
        if (!empty($eHealthData) && (isset($eHealthData['uuid']) || isset($eHealthData['id']))) {
            $eHealthData = [$eHealthData];
        }

        $eHealthRequests = collect($eHealthData)->keyBy('uuid');

        if ($eHealthRequests->isEmpty()) {
            return;
        }

        $localPendingRequests = EmployeeRequest::query()
            ->filterByLegalEntityId($legalEntity->id)
            ->pendingEhealth()
            ->whereIn('uuid', $eHealthRequests->keys())
            ->with(['revision', 'employee', 'party', 'division'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $approvedLocals = collect();

        foreach ($localPendingRequests as $localRequest) {
            $remoteRequestData = $eHealthRequests->get($localRequest->uuid);

            if (!$remoteRequestData) {
                continue;
            }

            $remoteStatus = $remoteRequestData['status'] ?? null;

            if (!$remoteStatus) {
                Log::warning(
                    "[EmployeeRequestProcessor] Remote status missing for Request UUID: {$localRequest->uuid}"
                );
                continue;
            }

            try {
                if ($remoteStatus === 'APPROVED') {
                    // Keep payload only in the batch map ($eHealthRequests). Do not setAttribute a
                    // pseudo-column — Eloquent update() would try to persist it and abort PG.
                    $approvedLocals->push($localRequest);
                } elseif (in_array($remoteStatus, ['REJECTED', 'EXPIRED'], true)) {
                    $newStatus = match ($remoteStatus) {
                        'REJECTED' => LocalStatus::REJECTED,
                        'EXPIRED' => LocalStatus::EXPIRED,
                        default => null,
                    };

                    if ($newStatus) {
                        $localRequest->update([
                            'status' => $newStatus,
                            'applied_at' => now(),
                        ]);
                        $localRequest->revision?->update(['status' => RevisionStatus::OUTDATED]);

                        Log::info(
                            "[EmployeeRequestProcessor] Request status updated to {$newStatus->value}. Request ID: {$localRequest->id}"
                        );
                    }
                }
            } catch (\Throwable $e) {
                Log::error(
                    "[EmployeeRequestProcessor] Failed to process request ID {$localRequest->id}: ".$e->getMessage(),
                    ['exception' => $e]
                );
            }
        }

        $partition = $this->partitionLatestApprovedPerEmployee($approvedLocals);

        foreach ($partition['superseded'] as $supersededRequest) {
            try {
                // Remote APPROVED but superseded by a newer edit for the same employee — close without applying content.
                $supersededRequest->update([
                    'status' => LocalStatus::APPROVED,
                    'applied_at' => now(),
                ]);
                $supersededRequest->revision?->update(['status' => RevisionStatus::OUTDATED]);
            } catch (\Throwable) {
                // Soft-fail: newer apply still proceeds; older row can be cleaned on next sync.
            }
        }

        foreach ($partition['apply'] as $localRequest) {
            try {
                $remoteRequestData = $eHealthRequests->get($localRequest->uuid);
                $this->applyApprovedRequest($localRequest, $remoteRequestData);
            } catch (\Throwable $e) {
                Log::error(
                    "[EmployeeRequestProcessor] Failed to process request ID {$localRequest->id}: ".$e->getMessage(),
                    ['exception' => $e]
                );
            }
        }

        // Logic to insert missing requests from eHealth that don't exist locally
        $localEmployeeRequestUuids = EmployeeRequest::query()
            ->filterByLegalEntityId($legalEntity->id)
            ->pluck('uuid')
            ->toArray();

        $employeeRequestsUpsertData = [];

        foreach ($eHealthData as $ehealthEmployeeRequest) {
            if (in_array($ehealthEmployeeRequest['uuid'], $localEmployeeRequestUuids, true)) {
                continue;
            }

            // Check if 'inserted_at' exists, otherwise use current time
            $insertedAt = isset($ehealthEmployeeRequest['inserted_at'])
                ? Carbon::parse($ehealthEmployeeRequest['inserted_at'])->format('Y-m-d H:i:s')
                : now();

            $employeeRequestsUpsertData[] = [
                'uuid' => $ehealthEmployeeRequest['uuid'],
                'inserted_at' => $insertedAt,
                'status' => $ehealthEmployeeRequest['status'],
                'legal_entity_id' => $legalEntity->id,
                'sync_status' => JobStatus::PARTIAL->value
            ];
        }

        if (!empty($employeeRequestsUpsertData)) {
            EmployeeRequest::insert($employeeRequestsUpsertData);
        }
    }

    /**
     * Assigns roles to the user associated with the employee.
     */
    private function assignUserRoles(Employee $employee, int $legalEntityId, ?int $requestUserId = null): void
    {
        // Link User to Party if missing (critical for the User->Employee relation)
        if ($requestUserId && $employee->partyId) {
            $user = \App\Models\User::find($requestUserId);
            if ($user && !$user->partyId) {
                $user->partyId = $employee->partyId;
                $user->save();
            }
        }

        $users = $employee->party->users()->get();

        if ($users->isEmpty() && $requestUserId) {
            $requestUser = \App\Models\User::find($requestUserId);

            if ($requestUser) {
                $users = collect([$requestUser]);
            }
        }

        if ($users->isEmpty()) {
            return;
        }

        $roleName = $employee->employeeType;

        foreach ($users as $user) {
            $employee->users()->syncWithoutDetaching([$user->id]);

            if (!$user->partyId && $employee->partyId) {
                $user->partyId = $employee->partyId;
                $user->save();
            }

            // Assign Role based on Employee Type
            if ($roleName && !$user->hasRole($roleName)) {
                setPermissionsTeamId($legalEntityId);
                $user->assignRole($roleName);
            }
        }
    }
}
