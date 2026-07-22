<?php

declare(strict_types=1);

namespace App\Services\Employee;

use App\Classes\eHealth\EHealth;
use App\Models\Employee\EmployeeRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Shared matching rules for linking local employee requests to eHealth employees.
 * Used by EmployeeRequestProcessor (syncOne / apply) and login EmployeeCreate.
 */
class EmployeeRequestMatcher
{
    /**
     * Remote Create Employee Request statuses that mean "not decided yet".
     */
    public static function isRemoteStillPending(mixed $status): bool
    {
        $value = $status instanceof \BackedEnum ? $status->value : $status;

        return is_string($value) && in_array($value, ['NEW', 'SIGNED'], true);
    }

    /**
     * Same-calendar-day comparison for local vs remote start dates.
     * Returns false when either value is empty or unparseable.
     */
    public static function datesMatchSameDay(mixed $localDate, mixed $remoteDate): bool
    {
        if ($localDate === null || $localDate === '' || $remoteDate === null || $remoteDate === '') {
            return false;
        }

        try {
            return Carbon::parse($localDate)->isSameDay(Carbon::parse($remoteDate));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Pick APPROVED employee from an eHealth list for this request.
     * Match: position + employee_type + start_date (same day when both set) + division when set.
     * Fuzzy fallback: sole candidate for the filtered list.
     *
     * @param  list<array<string, mixed>>  $employeesList
     * @return array<string, mixed>|null
     */
    public function pickFromApprovedList(EmployeeRequest $request, array $employeesList): ?array
    {
        if ($employeesList === []) {
            return null;
        }

        $divisionUuid = $request->division?->uuid;
        $employeeType = $request->employeeType;
        $targetPosition = $request->position;
        $targetStartDate = $request->startDate;

        foreach ($employeesList as $remoteEmp) {
            if (!isset($remoteEmp['uuid'])) {
                Log::warning('[EmployeeRequestMatcher] Skipping remote employee record without uuid.');

                continue;
            }

            $posMatch = ($remoteEmp['position'] ?? '') === $targetPosition;
            $typeMatch = ($remoteEmp['employee_type'] ?? '') === $employeeType;

            $dateMatch = true;
            if ($targetStartDate && !empty($remoteEmp['start_date'])) {
                $dateMatch = self::datesMatchSameDay($targetStartDate, $remoteEmp['start_date']);
            }

            $divisionMatch = true;
            if ($divisionUuid) {
                $divisionMatch = ($remoteEmp['division_id'] ?? null) === $divisionUuid;
            }

            if ($posMatch && $typeMatch && $dateMatch && $divisionMatch) {
                Log::info('[EmployeeRequestMatcher] Found MATCHING Employee UUID: ' . $remoteEmp['uuid']);

                return $remoteEmp;
            }
        }

        if (count($employeesList) === 1 && isset($employeesList[0]['uuid'])) {
            Log::warning(
                '[EmployeeRequestMatcher] Fuzzy match: taking the only found employee for this Tax ID.'
            );

            return $employeesList[0];
        }

        return null;
    }

    /**
     * Search eHealth APPROVED employees by tax_id and pick a match for the request.
     *
     * @return array<string, mixed>|null
     */
    public function findApprovedForRequest(
        EmployeeRequest $request,
        string $taxId,
        string $legalEntityUuid
    ): ?array {
        Log::info("[EmployeeRequestMatcher] Searching eHealth for Employee by TaxID: {$taxId}");

        try {
            $divisionUuid = $request->division?->uuid;
            $employeeType = $request->employeeType;

            $params = [
                'tax_id' => $taxId,
                'status' => 'APPROVED',
                'legal_entity_id' => $legalEntityUuid,
                'page_size' => 50,
            ];

            if ($divisionUuid) {
                $params['division_id'] = $divisionUuid;
            }

            if ($employeeType) {
                $params['employee_type'] = $employeeType;
            }

            $employeesList = EHealth::employee()->getMany($params)->validate();

            if (empty($employeesList)) {
                Log::warning(
                    "[EmployeeRequestMatcher] No APPROVED employees found in eHealth for Tax ID: {$taxId}."
                );

                return null;
            }

            return $this->pickFromApprovedList($request, $employeesList);
        } catch (\Throwable $e) {
            Log::error(
                '[EmployeeRequestMatcher] Search failed: ' . $e->getMessage(),
                ['exception' => $e, 'tax_id' => $taxId]
            );

            return null;
        }
    }
}
