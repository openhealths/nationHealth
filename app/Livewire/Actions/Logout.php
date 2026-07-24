<?php

declare(strict_types=1);

namespace App\Livewire\Actions;

use App\Classes\eHealth\EHealth;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

class Logout
{
    /**
     * Log the current user out of the application.
     */
    public function __invoke(bool $redirect = true, ?string $message = null)
    {
        if (Auth::guard('ehealth')->check()
            && (Session::has(config('ehealth.api.auth_ehealth'))
                || Session::has(config('ehealth.api.oauth.bearer_token')))
        ) {
            try {
                EHealth::auth()->logout(Session::get('auth_token'));
            } catch (EHealthException|EHealthConnectionException $exception) {
                // Log the error but don't prevent logout
                Log::channel('e_health_errors')->error("Error while logout: {$exception->getMessage()}", [
                    'exception' => $exception,
                    'user_id' => Auth::id()
                ]);
            }
        }

        $sessionId = request()->session()->getId();

        if (config('session.driver') === 'database') {
            Session::getHandler()->destroy($sessionId);
        }

        Auth::logout();

        Session::invalidate();
        Session::regenerateToken();

        if ($message) {
            Session::put('success', $message);
        }

        $redirectRoute = redirect()->route(App::isLocal() ? 'dev.login' : 'login');

        return $redirect ? $redirectRoute : true;
    }
}
