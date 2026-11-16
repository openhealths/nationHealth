<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Middleware;

use App\Auth\SessionBinder;
use App\Classes\eHealth\EHealth;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\User;
use Closure;
use App\Auth\EHealth\Services\TokenStorage;
use App\Exceptions\EHealth\EHealthConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\Response;

class CheckSessionToken
{
    /**
     * Number of minutes of inactivity before automatic logout
     */
    private const int INACTIVITY_LIMIT_MINUTES = 60;

    public function __construct(protected TokenStorage $tokenStorage)
    {
    }

    /**
     * Handle an incoming request.
     *
     * @param  Request  $request
     * @param  Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Preserve the selected role in sessions created before the key was renamed.
        if (!Session::exists('single_role_login') && Session::exists('first_login_role')) {
            Session::put('single_role_login', Session::get('first_login_role'));
        }
        Session::forget('first_login_role');

        // Skip check
        if ($request->routeIs(['login', 'dev.login', 'logout'])) {
            return $next($request);
        }

        // Guards are checked explicitly: Livewire requests run this middleware before the route's auth middleware
        // switches the default guard, so Auth::check() would only see the web guard there
        $isEHealthSession = Auth::guard('ehealth')->check();

        // Tokens are dropped when eHealth answers 401, leaving the user signed in with nothing to call eHealth with
        if ($isEHealthSession && !$this->tokenStorage->hasBearerToken()) {
            $this->terminateSession();

            return $this->sessionEndedResponse($request, __('auth.login.error.legal_entity.invalid_session'));
        }

        if ($isEHealthSession || Auth::guard('web')->check()) {
            $lastActivityKey = 'last_activity';
            $lastActivityTime = Session::get($lastActivityKey);
            $now = now()->timestamp;

            // Log out after more than 60 minutes of inactivity; the first request of a session has nothing to compare with
            if ($lastActivityTime !== null && $now - $lastActivityTime > self::INACTIVITY_LIMIT_MINUTES * 60) {
                if ($isEHealthSession) {
                    try {
                        EHealth::auth()->logout($this->tokenStorage->getBearerToken());
                    } catch (EHealthConnectionException|EHealthValidationException|EHealthResponseException $exception) {
                        Log::channel('e_health_errors')->error("Error while logout: {$exception->getMessage()}", [
                            'exception' => $exception
                        ]);
                    }
                }

                $this->terminateSession();

                return $this->sessionEndedResponse($request, __('auth.session_expired'));
            }

            // Extend the token lifetime if the lifetime is 20 minutes or less
            if ($isEHealthSession && now()->addMinutes(20)->greaterThanOrEqualTo($this->tokenStorage->getExpiresAt())) {
                $refreshed = $this->tokenStorage->refreshBearerToken();

                if (!$refreshed) {
                    $this->terminateSession();

                    return $this->sessionEndedResponse($request, __('auth.session_refresh_failed'));
                }
            }

            // Update last activity time after every request
            Session::put($lastActivityKey, $now);
        }

        return $next($request);
    }

    /**
     * Tell the client its session has ended: the frontend's API calls get 401 to handle themselves, pages go to the login form.
     *
     * @param  Request  $request
     * @param  string  $message
     * @return Response
     */
    private function sessionEndedResponse(Request $request, string $message): Response
    {
        if ($request->is('api/*')) {
            return response()->json(['message' => $message], Response::HTTP_UNAUTHORIZED);
        }

        return Redirect::route('login')->with('error', $message);
    }

    /**
     * End the current session, dropping the binding that ties it to the user so no stale session is left behind on the user record.
     *
     * @return void
     */
    private function terminateSession(): void
    {
        $user = Auth::guard('ehealth')->user() ?? Auth::guard('web')->user();

        if ($user instanceof User) {
            new SessionBinder()->release($user);
        }

        Auth::logout();
        Session::flush();
    }
}
