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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EmployeeRequestProcessor
{
    use BatchLegalEntityQueries;
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

            // 2. Resolve Employee UUID (The critical step)
            $employeeUuid = $this->resolveEmployeeUuid($request, $taxId); // <-- Tax ID is now guaranteed to be a string

            if (!$employeeUuid) {
                throw new \RuntimeException(
                    "Critical: Could not resolve Employee UUID from eHealth by searching Tax ID for Request {$request->id}"
                );
            }

            // 3. Find existing Employee by UUID or instantiate a new one
            $employee = Employee::where('uuid', $employeeUuid)->first();

            // Fallback for update scenarios (if we have a local link)
            if (!$employee && $request->employee_id) {
                $employee = Employee::find($request->employee_id);
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
                $employee->legal_entity_id = $request->legal_entity_id;
                $employee->user_id = $request->user_id;
                $employee->status = $systemOverrides['status'] ?? Status::ACTIVE;

                if ($request->party_id) {
                    $employee->party_id = $request->party_id;
                }
            }

            // 7. Save to DB
            $employee->save();

            Log::info("[EmployeeRequestProcessor] Employee Saved. ID: {$employee->id}");

            // 8. Link Request to Employee
            if ($request->employee_id !== $employee->id) {
                $request->update([
                                     'employee_id' => $employee->id,
                                     'party_id' => $employee->party_id ?? $request->party_id,
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
            $this->assignUserRoles($employee, $request->legal_entity_id);

            // 11. Finalize Request Status
            $request->update([
                                 'status' => LocalStatus::APPROVED,
                                 'applied_at' => now(),
                             ]);

            if ($request->revision) {
                $request->revision->update(['status' => RevisionStatus::APPLIED]);
            }
        });
    }

    /**
     * Resolves the Employee UUID by searching eHealth using Tax ID.
     * Since User Token endpoints do not return the created ID, we must find it
     * by matching Tax ID + Position + Start Date within the current Legal Entity.
     */
    private function resolveEmployeeUuid(EmployeeRequest $request, string $taxId): ?string
    {
        Log::info("[EmployeeRequestProcessor] Searching eHealth for Employee by TaxID: {$taxId}");

        try {
            $divisionUuid = optional($request->division)->uuid;

            $employeeType = $request->employee_type;

            $params = [
                'tax_id' => $taxId,
                'status' => 'APPROVED',
                'legal_entity_id' => legalEntity()->uuid,
                'page_size' => 50,
            ];

            if ($divisionUuid) {
                $params['division_id'] = $divisionUuid;
            }

            if ($employeeType) {
                $params['employee_type'] = $employeeType;
            }

            $response = EHealth::employee()->getMany($params);

            $employeesList = $response->validate();

            if (empty($employeesList)) {
                Log::warning(
                    "[EmployeeRequestProcessor] No APPROVED employees found in eHealth for Tax ID: {$taxId} and applied filters."
                );

                return null;
            }

            $targetPosition = $request->position;
            $targetStartDate = $request->start_date; // Local request start date (Y-m-d)

            foreach ($employeesList as $remoteEmp) {
                if (!isset($remoteEmp['uuid'])) {
                    Log::warning("[EmployeeRequestProcessor] Skipping remote employee record without 'uuid' key.");
                    continue;
                }

                // 1. Check Position Code
                $posMatch = ($remoteEmp['position'] ?? '') === $targetPosition;

                // 2. Check Employee Type (This should match if filtered in API call, but safe to re-check)
                $typeMatch = ($remoteEmp['employee_type'] ?? '') === $employeeType;

                // 3. Check Start Date (Requires robust date comparison)
                $dateMatch = true;
                if ($targetStartDate && !empty($remoteEmp['start_date'])) {
                    try {
                        $remoteDate = Carbon::parse($remoteEmp['start_date']);
                        $localDate = Carbon::parse($targetStartDate);
                        $dateMatch = $remoteDate->isSameDay($localDate);
                    } catch (\Exception $e) {
                        Log::warning(
                            "[EmployeeRequestProcessor] Date parsing failed for remote start_date: {$remoteEmp['start_date']}. Skipping date check."
                        );
                        $dateMatch = false;
                    }
                }

                // 4. Check Division ID (Check only if a division filter was applied)
                $divisionMatch = true;
                if ($divisionUuid) {
                    $remoteDivisionId = $remoteEmp['division_id'] ?? null;
                    $divisionMatch = $remoteDivisionId === $divisionUuid;
                }

                if ($posMatch && $typeMatch && $dateMatch && $divisionMatch) {
                    Log::info("[EmployeeRequestProcessor] Found MATCHING Employee UUID: {$remoteEmp['uuid']}");

                    return $remoteEmp['uuid'];
                }
            }

            if (count($employeesList) === 1) {
                if (isset($employeesList[0]['uuid'])) {
                    Log::warning(
                        "[EmployeeRequestProcessor] Fuzzy match: taking the only found employee for this Tax ID."
                    );

                    return $employeesList[0]['uuid'];
                }
            }

        } catch (\Exception $e) {
            Log::error(
                "[EmployeeRequestProcessor] Search failed: " . $e->getMessage(),
                ['exception' => $e, 'tax_id' => $taxId]
            );
        }

        return null;
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
            ->where('legal_entity_id', $legalEntity->id)
            ->whereNull('applied_at')
            ->whereIn('uuid', $eHealthRequests->keys())
            ->with(['revision', 'employee', 'party', 'division'])
            ->cursor();

        $approvedCount = 0;

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
                    // Pass the specific item data, not the whole array
                    $this->applyApprovedRequest($localRequest, $remoteRequestData);
                    $approvedCount++;
                    Log::info(
                        "[EmployeeRequestProcessor] Request APPROVED and applied successfully. Request ID: {$localRequest->id}"
                    );

                } elseif (in_array($remoteStatus, ['REJECTED', 'EXPIRED'])) {
                    $newStatus = match ($remoteStatus) {
                        'REJECTED' => LocalStatus::REJECTED,
                        'EXPIRED' => LocalStatus::EXPIRED,
                        default => null,
                    };

                    if ($newStatus) {
                        $localRequest->update(
                            [
                                'status' => $newStatus,
                                'applied_at' => now(),
                            ]
                        );
                        $localRequest->revision?->update(
                            ['status' => RevisionStatus::OUTDATED]
                        );

                        Log::info(
                            "[EmployeeRequestProcessor] Request status updated to {$newStatus->value}. Request ID: {$localRequest->id}"
                        );
                    }
                }
            } catch (\Throwable $e) {
                Log::error(
                    "[EmployeeRequestProcessor] Failed to process request ID {$localRequest->id}: " . $e->getMessage(),
                    ['exception' => $e]
                );
            }
        }

        // Logic to insert missing requests from eHealth that don't exist locally
        $localEmployeeRequestUuids = EmployeeRequest::where('legal_entity_id', $legalEntity->id)
            ->pluck('uuid')
            ->toArray();

        $employeeRequestsUpsertData = [];

        foreach ($eHealthData as $ehealthEmployeeRequest) {
            if (in_array($ehealthEmployeeRequest['uuid'], $localEmployeeRequestUuids) || $ehealthEmployeeRequest['status'] !== Status::APPROVED->value) {
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
    private function assignUserRoles(Employee $employee, int $legalEntityId): void
    {
        $users = $employee->party->users;

        if ($users->isEmpty()) {
            return;
        }

        $roleName = $employee->employee_type;

        foreach ($users as $user) {
            // Link User to Party if missing
            if (!$user->party_id && $employee->party_id) {
                $user->party_id = $employee->party_id;
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
