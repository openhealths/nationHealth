<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Validation\Rules\Password as PasswordRule;

#[Layout('layouts.guest')]
class ResetPassword extends Component
{
    #[Locked]
    public string $token = '';

    public string $email = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;

        $this->email = request()->string('email');
    }

    /*
     * Reset the password for the given user.
     */
    public function resetPassword(): void
    {
        $data = $this->validate([
            'token' => ['required'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string', PasswordRule::defaults()],
            'passwordConfirmation' => ['required', 'string', 'same:password']
        ]);

        /*
         * Here we will attempt to reset the user's password. If it is successful we
         * will update the password on an actual user model and persist it to the
         * database. Otherwise we will parse the error and return the response.
         */
        $status = Password::reset(
            $data,
            function ($user) {
                $user->forceFill([
                    'password' => Hash::make($this->password)
                ])->save();

                event(new PasswordReset($user));
            }
        );

        /*
         * If the password was successfully reset, we will redirect the user back to
         * the application's home authenticated view. If there is an error we can
         * redirect them back to where they came from with their error message.
         */
        if ($status !== Password::PasswordReset) {
            Log::error('Reset password:', ['email' => $this->email, 'status' => __($status)]);

            session()->flash('error', __('auth.login.error.reset_password'));

            return;
        }

        session()->flash('success', __($status));

        $this->redirectRoute('login', navigate: true);
    }

    public function messages(): array
    {
        return [
            'passwordConfirmation.required' => __('forms.field_empty'),
            'passwordConfirmation.same' => __('auth.login.error.validation.confirm_mismatch'),
        ];
    }
}
