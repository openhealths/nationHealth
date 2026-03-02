<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use Exception;
use App\Models\User;
use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;

#[Layout('layouts.guest')]
class VerifyEmail extends Component
{
    /*
     * Send an email verification notification to the user.
     */
    public function sendVerification()
    {
        $user = User::find(session('unverified_user_id'));

        if (!$user) {
            return Redirect::route('login')->with('error', __('auth.login.error.wrong_session'));
        }

        try {
            $user->sendEmailVerificationNotification();

            session()->forget('unverified_user_id');

            return Redirect::route('login')->with('success', __('auth.login.vlink_sent'));
        } catch (Exception $err) {
            Log::error('Failed to send verification email', [$err->getMessage()]);

            session()->flash('error', __('auth.login.error.vlink_not_sent'));
        }
    }
}
