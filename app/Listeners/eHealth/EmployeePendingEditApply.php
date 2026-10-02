<?php

declare(strict_types=1);

namespace App\Listeners\eHealth;

use App\Events\EHealthUserLogin;
use App\Models\Employee\EmployeeRequest;
use App\Services\Employee\EmployeeRequestProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Crypt;
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
 * Queued (#842): eHealth HTTP must not block the login HTTP request (504 risk).
 * Runs after EmployeeCreate is registered; the job executes asynchronously on the sync queue.
 *
 * Gate uses $event->scopes (same login-resolved list as syncPermissions), not Spatie can()/Auth::shouldUse —
 * queue workers have no login HTTP context and must not depend on the default guard.
 */
class EmployeePendingEditApply implements ShouldQueue
{
    use InteractsWithQueue;

    private const string REQUIRED_SCOPE = 'employee_request:read';

    /**
     * Same queue as other eHealth login sync listeners.
     *
     * @var string|null
     */
    public $queue = 'sync';

    /**
     * Budget for a small number of getDetails / employee-list calls.
     */
    public int $timeout = 90;

    public int $tries = 2;

    public function __construct(private EmployeeRequestProcessor $processor)
    {
    }

    public function handle(EHealthUserLogin $event): void
    {
        // Login already merged OAuth + LE role scopes into $event->scopes — no Auth/Spatie team needed.
        if (!in_array(self::REQUIRED_SCOPE, $event->scopes, true)) {
            return;
        }

        $user = $event->user;

        if (!$this->restoreBearerToken($event)) {
            Log::error('[EmployeePendingEditApply] Missing or invalid eHealth token on queued apply.', [
                'user_id' => $user->id,
                'legal_entity_id' => $event->legalEntity->id,
            ]);

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
            // One sync per employee: the newest pending edit only.
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
                // Do not fail the whole job if one request fails; remaining edits can retry on next login/sync.
                Log::error('[EmployeePendingEditApply] Sync failed for request.', [
                    'request_id' => $request->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function failed(EHealthUserLogin $event, Throwable $exception): void
    {
        Log::error('[EmployeePendingEditApply] Queued listener failed.', [
            'user_id' => $event->user->id,
            'legal_entity_id' => $event->legalEntity->id,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Queue workers have no login session — restore the bearer token captured on EHealthUserLogin.
     */
    private function restoreBearerToken(EHealthUserLogin $event): bool
    {
        if ($event->token === '') {
            return filled(session()->get(config('ehealth.api.oauth.bearer_token')));
        }

        try {
            $plainToken = Crypt::decryptString($event->token);
        } catch (Throwable) {
            return false;
        }

        if ($plainToken === '') {
            return false;
        }

        session()->put(config('ehealth.api.oauth.bearer_token'), $plainToken);

        return true;
    }
}
