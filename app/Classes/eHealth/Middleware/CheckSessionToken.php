<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Middleware;

use App\Classes\eHealth\EHealth;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use Closure;
use App\Auth\EHealth\Services\TokenStorage;
use App\Exceptions\EHealth\EHealthConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Session;

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
        // Skip check
        if ($request->routeIs(['login', 'dev.login', 'logout'])) {
            return $next($request);
        }

        if (Auth::check() && Auth::guard('ehealth')->check()) {
            $lastActivityKey = 'last_activity';
            $lastActivityTime = Session::get($lastActivityKey);
            $now = now()->timestamp;

            // If this is the first request, just set the timestamp
            if ($lastActivityTime === null) {
                Session::put($lastActivityKey, $now);

                return $next($request);
            }

            // Checking if more than 60 minutes of inactivity have passed
            $inactivityLimitSeconds = self::INACTIVITY_LIMIT_MINUTES * 60;
            $timeSinceLastActivity = $now - $lastActivityTime;

            //  If time expired, log out and redirect with an error
            if ($timeSinceLastActivity > $inactivityLimitSeconds) {
                try {
                    EHealth::auth()->logout($this->tokenStorage->getBearerToken());
                } catch (EHealthConnectionException|EHealthValidationException|EHealthResponseException $exception) {
                    Log::channel('e_health_errors')->error("Error while logout: {$exception->getMessage()}", [
                        'exception' => $exception
                    ]);
                }

                Auth::logout();
                Session::flush();

                return Redirect::route('login')
                    ->with('error', __('auth.session_expired'));
            }

            // Extend the token lifetime if the lifetime is 20 minutes or less
            $expiresAt = $this->tokenStorage->getExpiresAt();

            if (now()->addMinutes(20)->greaterThanOrEqualTo($expiresAt)) {
                $refreshed = $this->tokenStorage->refreshBearerToken();

                if (!$refreshed) {
                    Auth::logout();
                    Session::flush();

                    return Redirect::route('login')->with('error', 'Could not refresh eHealth session.');
                }
            }

            // Update last activity time after every request
            Session::put($lastActivityKey, $now);
        }

        return $next($request);
    }
}
