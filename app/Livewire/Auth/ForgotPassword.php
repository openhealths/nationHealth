<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use Exception;
use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;

#[Layout('layouts.guest')]
class ForgotPassword extends Component
{
    public string $email = '';

    public function sendPasswordResetLink()
    {
        try {
            $this->validate([
                'email' => ['required', 'email'],
            ]);

            $status = Password::sendResetLink($this->only('email'));

            if ($status === Password::RESET_LINK_SENT) {
                Log::info('Password reset link sent', ['email' => $this->email]);

                session()->flash('success', __($status));

                $this->reset('email');

                return $this->redirectRoute('login', navigate: true);
            }

            // Password has 60 seconds timeout between sending another email
            if ($status === Password::RESET_THROTTLED) {
                Log::warning('Reset attempt throttled', ['time' => now(), 'email' => $this->email]);

                session()->flash('error', __('auth.login.error.throttle'));

                return null;
            }

            // If user trying get password reset link at unregistered email
            if ($status === Password::INVALID_USER) {
                Log::warning('Password reset. Wrong email', ['time' => now(), 'email' => $this->email]);

                session()->flash('error', __('auth.login.error.reset_link'));

                return null;
            }

            session()->flash('error', __($status));
        } catch (Exception $err) {
            Log::error('Failed to send reset link', ['error' => $err->getMessage()]);

            session()->flash('error', __('auth.login.error.reset_link'));
        }
    }
}
