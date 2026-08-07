<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Redirect;

class VerifyEmailController extends Controller
{
    /**
     * Mark the authenticated user's email address as verified.
     */
    public function __invoke(Request $request)
    {
        $userId = $request->route('id');
        $hash = $request->route('hash');

        /**
         * Static analyzers sometimes cannot accurately infer the type
         *
         * @var \App\Models\User $user
         */
        $user = User::findOrFail($userId);

        // Check if hash compares with its emeail
        if (!hash_equals((string) $hash, sha1(strtolower($user->getEmailForVerification())))) {
            abort(403, 'Invalid verification link');
        }

        // If user already verified
        if ($user->hasVerifiedEmail()) {
            return Redirect::route('login')->with('success', __('auth.login.error.email_already_verified'));
        }

        // Do verification
        $user->markEmailAsVerified();

        event(new Verified($user));

        Log::info("Email [{$user->email}] verified for user ID {$userId}");

        return Redirect::route('login')->with('success', __('auth.login.success.verification'));
    }
}
