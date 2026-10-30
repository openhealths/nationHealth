<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Core\EHealthJob;
use Closure;
use Illuminate\Support\Facades\Auth;

/** Scope role-dependent requests and response processing to this job's facility. */
class UseEHealthPermissionContext
{
    public function handle(EHealthJob $job, Closure $next): mixed
    {
        $previousTeamId = getPermissionsTeamId();
        $previousGuard = Auth::getDefaultDriver();
        setPermissionsTeamId($job->legalEntity?->id);
        Auth::shouldUse('ehealth');

        // A serialized user may contain relations loaded under another team. The allowed-role
        // cache itself is keyed by team/guard, but these Eloquent relations are not.
        $job->user?->unsetRelation('roles')->unsetRelation('permissions');

        try {
            return $next($job);
        } finally {
            $job->user?->unsetRelation('roles')->unsetRelation('permissions');
            setPermissionsTeamId($previousTeamId);
            Auth::shouldUse($previousGuard);
        }
    }
}
