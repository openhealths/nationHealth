<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use Exception;
use App\Models\Role;
use App\Models\User;
use App\Models\LegalEntity;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Layout;
use App\Classes\eHealth\EHealth;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportRedirects\Redirector;

#[Layout('layouts.guest')]
class LoginDev extends Login
{
    #[Locked]
    public bool $isLocalAuth = false;

    /**
     * Handle an incoming authentication request.
     *
     * @return RedirectResponse|Redirector
     */
    public function login(): RedirectResponse|Redirector
    {
        $key = $this->throttleKey();
        $credentials = $this->validate();

        // Check if user doesn't block by attempts exceeding
        if (!$this->ensureIsNotRateLimited($credentials)) {
            // Number of seconds before login retry
            $seconds = RateLimiter::availableIn($key);

            return Redirect::route('login')->with('error', __('auth.throttle', [
                'minutes' => ceil($seconds / 60),
                'seconds' => $seconds
            ]));
        }

        try {
            $accessToken = EHealth::auth()->login($credentials['email'], $credentials['password']);
        } catch (Exception $e) {
            session()->flash('error', $e->getMessage());

            return back();
        }
        $accessToken = $accessToken->validate();
        $user = User::withLegalEntityAccess($accessToken['user_id'], $this->legalEntityUUID)->first();

        $accessToken = $accessToken['value'];
        $selectedLegalEntityId = LegalEntity::whereUuid($this->legalEntityUUID)->value('id');
        setPermissionsTeamId($selectedLegalEntityId);

        if ($user && !$this->isSingleRoleAuth) {
            $scopes = $user->getScopes();
            Session::put(config('ehealth.api.auth_ehealth'), $user->id);
        } else {
            $this->isFirstLogin = !(bool)$user; // If user exists - it's not first login, otherwise - it's first login and we need to show role select

            if (empty($this->role)) {
                $this->showRoleSelect = true;

                return Redirect::back()->withInput();
            }

            $role = Role::findByName($this->role)->loadMissing('permissions', 'legalEntityTypes');
            $permissions = $role->permissions->pluck('name')->unique()->toArray();
            $scopes = implode(' ', $permissions);

            Session::put('first_login_role', $this->role);
        }

        try {
            $code = EHealth::auth()->authorize($accessToken, $scopes, $credentials['legalEntityUUID']);
        } catch (Exception $e) {
            Log::channel('e_health_errors')->error('Authorization error: ' . $e->details['error']['message'] ?? $e->getMessage(), ['exception' => $e]);

            session()->flash('error', $e->getMessage());

            return back();
        }

        $code = $code->validate();
        $legalEntityUuid = data_get($code, 'details.client_id');
        $code = $code['value'];

        Session::put('selected_legal_entity_uuid_for_ehealth', $legalEntityUuid);
        Session::put('selected_email', $this->email);
        Session::put('logined_guard', 'ehealth');

        return Redirect::route(
            'ehealth.oauth.callback',
            ['code' => $code],
            headers: ['Sec-Fetch-Mode' => 'cors']
        );
    }

    protected function rules(): array
    {
        $rules = parent::rules();
        $rules['password'] = 'required|string';

        return $rules;
    }

    public function render()
    {
        return view('livewire.auth.login-dev')->with([
            'hasEmailError' => $this->getErrorBag()->has('email'),
            'hasPasswordError' => $this->getErrorBag()->has('password'),
        ]);
    }
}
