<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use Exception;
use App\Models\Role;
use App\Models\User;
use App\Models\LegalEntity;
use Illuminate\View\View;
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

    // Redeclared to drop the #[Locked] attribute from the parent: dev login lets the developer type any email.
    public string $email = '';

    /**
     * Dev login is independent of the MIS two-factor flow, so the email
     * must always stay selectable regardless of any leftover gate session.
     *
     * @return void
     */
    public function mount(): void
    {
        parent::mount();

        $this->reset(['email', 'isEmailLocked']);
    }

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
        } catch (Exception $exception) {
            Session::flash('error', $exception->getMessage());

            return Redirect::back();
        }
        $accessToken = $accessToken->validate();
        $user = User::withLegalEntityAccess($accessToken['user_id'], $this->legalEntityUuid)->first();

        $accessToken = $accessToken['value'];
        $selectedLegalEntityId = LegalEntity::whereUuid($this->legalEntityUuid)->value('id');
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
            $code = EHealth::auth()->authorize($accessToken, $scopes, $credentials['legalEntityUuid']);
        } catch (Exception $exception) {
            Log::channel('e_health_errors')->error('Authorization error: ' . (data_get($exception, 'details.error.message') ?? $exception->getMessage()), ['exception' => $exception]);

            Session::flash('error', $exception->getMessage());

            return Redirect::back();
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

    public function render(): View
    {
        return view('livewire.auth.login-dev');
    }
}
