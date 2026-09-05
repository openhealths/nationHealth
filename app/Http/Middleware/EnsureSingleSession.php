<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\SessionBinder;
use App\Livewire\Actions\Logout;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class EnsureSingleSession
{
    /**
     * @param  SessionBinder  $binder
     */
    public function __construct(protected SessionBinder $binder)
    {
    }

    /**
     * Terminate the session as soon as it stops being the only one bound to the user.
     *
     * @param  Request  $request
     * @param  Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $this->authenticatedUser();

        if (!$user || $this->binder->verify($request, $user)) {
            return $next($request);
        }

        Log::warning('Session is no longer bound to the user it was issued to, terminating it', [
            'user_id' => $user->id,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent()
        ]);

        $logout = new Logout();

        return $logout()->with('error', __('auth.session_terminated'));
    }

    /**
     * Get the user behind the request, whichever guard they signed in with.
     *
     * @return User|null
     */
    protected function authenticatedUser(): ?User
    {
        foreach (['ehealth', 'web'] as $guard) {
            $user = Auth::guard($guard)->user();

            if ($user instanceof User) {
                return $user;
            }
        }

        return null;
    }
}
