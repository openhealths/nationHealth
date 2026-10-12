<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Enums\Employee\RequestStatus;
use App\Mail\UserCredentialsMail;
use App\Models\Employee\EmployeeRequest;
use App\Models\User;
use App\Notifications\TwoFactorCodeNotification;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportRedirects\Redirector;
use Random\RandomException;
use Throwable;

#[Layout('layouts.guest')]
class MisLogin extends Component
{
    public string $email = '';

    public string $password = '';

    public string $code = '';

    /**
     * Whether the user is signing in for the first time and has no password yet.
     *
     * @var bool
     */
    public bool $isFirstLogin = false;

    /**
     * Whether the one-time code form is currently displayed.
     *
     * @var bool
     */
    public bool $showCodeForm = false;

    /**
     * Number of minutes a generated one-time code stays valid.
     */
    private const int CODE_TTL_MINUTES = 10;

    /**
     * Validate the submitted credentials and email a one-time code to the user.
     *
     * @return RedirectResponse|Redirector|null
     */
    public function login(): RedirectResponse|Redirector|null
    {
        $key = $this->throttleKey();

        $this->validate([
            'email' => 'required|email',
            'password' => $this->isFirstLogin ? 'nullable' : 'required|string'
        ]);

        if (!$this->ensureIsNotRateLimited()) {
            $seconds = RateLimiter::availableIn($key);

            return Redirect::route('mis.login')->with('error', __('auth.throttle', [
                'minutes' => ceil($seconds / 60),
                'seconds' => $seconds
            ]));
        }

        $user = User::where('email', $this->email)->first();

        if ($this->isFirstLogin && $user) {
            $this->addError('email', __('auth.login.error.account_exists'));

            return null;
        }

        if (!$user) {
            return $this->handleMissingUser($key);
        }

        if (!Hash::check($this->password, $user->password)) {
            RateLimiter::hit($key, config('ehealth.auth.delay_seconds'));

            $this->addError('email', __('auth.login.error.validation.credentials'));

            return null;
        }

        if (!$user->hasVerifiedEmail()) {
            Session::put('unverified_user_id', $user->id);

            return Redirect::route('verification.notice');
        }

        if (!$this->sendCode($user)) {
            $this->addError('email', __('auth.login.error.common'));

            return null;
        }

        $this->showCodeForm = true;
        $this->reset('password');

        return null;
    }

    /**
     * Handle a login attempt for an email that has no user yet.
     *
     * @param  string  $key  The rate limiting throttle key.
     * @return RedirectResponse|Redirector|null
     */
    protected function handleMissingUser(string $key): RedirectResponse|Redirector|null
    {
        $employeeRequest = EmployeeRequest::whereEmail($this->email)
            ->whereStatus(RequestStatus::APPROVED)
            ->first();

        if (!$employeeRequest) {
            RateLimiter::hit($key, config('ehealth.auth.delay_seconds'));

            $this->addError('email', __('auth.login.error.validation.credentials'));

            return null;
        }

        if (!$this->provisionUser($employeeRequest->partyId)) {
            $this->addError('email', __('auth.login.error.common'));

            return null;
        }

        $this->reset('password', 'isFirstLogin');

        Session::flash('success', __('auth.login.success.account_provisioned'));

        return null;
    }

    /**
     * Create a new user for the entered email and email them a generated password.
     *
     * @param  int|null  $partyId
     * @return bool Whether the user was successfully created and notified.
     */
    protected function provisionUser(?int $partyId): bool
    {
        $password = Str::password(8);

        try {
            $user = User::create([
                'email' => $this->email,
                'password' => Hash::make($password),
                'party_id' => $partyId,
                'must_change_password' => true
            ]);

            $user->markEmailAsVerified();

            Mail::to($this->email)->send(new UserCredentialsMail($this->email, $password));
        } catch (Throwable $exception) {
            Log::error('MisLogin: failed to provision user from employee request', [
                'exception_type' => $exception::class,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Verify the submitted one-time code and redirect to the eHealth login.
     *
     * @return RedirectResponse|Redirector|null
     */
    public function verify(): RedirectResponse|Redirector|null
    {
        $key = $this->throttleKey();

        $this->validate(['code' => 'required|string']);

        if (!$this->ensureIsNotRateLimited()) {
            $seconds = RateLimiter::availableIn($key);

            return Redirect::route('mis.login')->with('error', __('auth.throttle', [
                'minutes' => ceil($seconds / 60),
                'seconds' => $seconds
            ]));
        }

        $user = User::where('email', $this->email)->first();

        $isCodeValid = $user
            && $user->twoFactorCode
            && $user->twoFactorCodeExpiresAt?->isFuture()
            && Hash::check($this->code, $user->twoFactorCode);

        if (!$isCodeValid) {
            RateLimiter::hit($key, config('ehealth.auth.delay_seconds'));

            $this->addError('code', __('auth.login.two_factor.invalid_code'));

            return null;
        }

        $user->forceFill([
            'two_factor_code' => null,
            'two_factor_code_expires_at' => null
        ])->save();

        RateLimiter::clear($this->throttleKey());

        Session::put('mis_2fa', [
            'user_id' => $user->id,
            'email' => $user->email,
            'verified_at' => CarbonImmutable::now()->toIso8601String()
        ]);

        return Redirect::route('login');
    }

    /**
     * Re-send a fresh one-time code to the user's email.
     *
     * @return void
     */
    public function resendCode(): void
    {
        if (!$this->ensureIsNotRateLimited()) {
            return;
        }

        $resendKey = 'resend_code:' . $this->throttleKey();

        $executed = RateLimiter::attempt($resendKey, 1, function (): void {
            $user = User::where('email', $this->email)->first();

            if ($user && $this->sendCode($user)) {
                Session::flash('success', __('auth.login.two_factor.sent'));
            }
        });

        if (!$executed) {
            Session::flash('error', __('auth.login.two_factor.resend_throttled', [
                'seconds' => RateLimiter::availableIn($resendKey)
            ]));
        }
    }

    /**
     * Generate, store and email a one-time code for the given user.
     *
     * @param  User  $user
     * @return bool Whether the code was successfully generated and sent.
     */
    protected function sendCode(User $user): bool
    {
        try {
            $code = (string)random_int(100000, 999999);
        } catch (RandomException $exception) {
            Session::flash('error', __('auth.login.two_factor.generation_failed'));
            Log::error('Failed to generate a two-factor code', ['exception_type' => $exception::class]);

            return false;
        }

        $user->forceFill([
            'two_factor_code' => Hash::make($code),
            'two_factor_code_expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES)
        ])->save();

        $user->notify(new TwoFactorCodeNotification($code, self::CODE_TTL_MINUTES));

        return true;
    }

    /**
     * Ensure the authentication request is not rate limited.
     *
     * @return bool
     */
    protected function ensureIsNotRateLimited(): bool
    {
        if (!RateLimiter::tooManyAttempts($this->throttleKey(), config('ehealth.auth.max_login_attempts'))) {
            return true;
        }

        Log::warning(__('auth.login.error.lockout', [], 'en'), [
            'ip' => request()->ip(),
        ]);

        $this->addError('email', __('auth.login.error.exceed_login_attempts'));

        return false;
    }

    /**
     * Get the authentication rate limiting throttle key.
     *
     * @return string
     */
    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->email) . '|' . request()->ip());
    }

    /**
     * Render the component view.
     *
     * @return View
     */
    public function render(): View
    {
        return view('livewire.auth.mis-login');
    }
}
