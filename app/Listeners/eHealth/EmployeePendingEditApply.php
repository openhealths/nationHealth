<?php

declare(strict_types=1);

namespace App\Listeners\eHealth;

use App\Events\EHealthUserLogin;
use App\Models\Employee\EmployeeRequest;
use App\Services\Employee\EmployeeRequestProcessor;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * After #802/#803, EmployeeCreate skips pending edits on login (no EmployeeRequest API there).
 * Roles with employee_request:read still need a safe apply path after email confirmation:
 * Get Employee Request by ID via syncSinglePendingRequest for this user's pending edits only.
 *
 * Contract (team-lead aligned):
 * - Never call EmployeeRequest APIs from EmployeeCreate.
 * - Apply only when remote status is APPROVED (handled inside syncSinglePendingRequest).
 * - Only the latest pending edit per employee_id is synced; older ones are superseded after apply.
 * - Scoped to the current legal entity (same email must not pull another LE's revisions).
 * - Do not gate on applied_at: Owner/party submit may set it while status stays NEW.
 *
 * Runs after EmployeeCreate. Does nothing without the scope (avoids 403 for receptionist/med_admin).
 * Sync stays synchronous so the dashboard sees applied party data, but only one getDetails
 * per employee (latest pending). Failures are swallowed so a slow/failed eHealth call cannot 504 login.
 */
class EmployeePendingEditApply
{
    public function __construct(private EmployeeRequestProcessor $processor)
    {
    }

    public function handle(EHealthUserLogin $event): void
    {
        // Spatie team + guard must match the login context before can()/cannot() on scopes.
        setPermissionsTeamId($event->legalEntity->id);
        Auth::shouldUse($event->guard);

        $user = $event->user->loadMissing('roles', 'permissions');

        if ($user->cannot('employee_request:read')) {
            return;
        }

        $pendingEdits = EmployeeRequest::query()
            ->with(['revision', 'employee', 'party', 'division'])
            ->filterByLegalEntityId($event->legalEntity->id)
            ->where('email', $user->email)
            ->pendingEhealth()
            ->whereNotNull('employee_id')
            ->whereNotNull('uuid')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            // One sync per employee: the newest pending edit only (limits login latency).
            ->unique(fn (EmployeeRequest $request): int => (int) $request->employeeId)
            ->values();

        if ($pendingEdits->isEmpty()) {
            return;
        }

        foreach ($pendingEdits as $request) {
            try {
                $result = $this->processor->syncSinglePendingRequest($request, $event->legalEntity);

                if ($result['outcome'] === EmployeeRequestProcessor::OUTCOME_APPROVED) {
                    $this->processor->markOlderPendingEditsSuperseded($request);
                }
            } catch (Throwable $e) {
                // Do not break login if one request fails; remaining edits can retry on next login/sync.
                Log::error('[EmployeePendingEditApply] Sync failed for request.', [
                    'request_id' => $request->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
