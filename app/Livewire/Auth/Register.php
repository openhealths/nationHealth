<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Models\User;
use Exception;
use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Hash;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;

#[Layout('layouts.guest')]
class Register extends Component
{
    public string $email = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    /**
     * Handle an incoming registration request.
     */
    public function register(): void
    {
        $userData = $this->validate([
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:' . User::class],
            'password' => ['required', 'string', Password::defaults()],
            'passwordConfirmation' => ['required', 'string', 'same:password']
        ]);

        try {
            $user = DB::transaction(function () use ($userData) {
                $user = new User();
                $user->password = Hash::make($userData['password']);
                $user->email = $userData['email'];
                $user->save();
                $user->refresh();

                return $user;
            });

            event(new Registered($user));

            session()->flash('success', __('auth.login.success.new_user_create'));

            $this->redirect(route('login', absolute: false), navigate: true);

        } catch (Exception $err) {
            Log::error('Register: ', ['error' => $err->getMessage()]);

            session()->flash('error', __('Помилка при створенні користувача. Зверніться до адміністратора'));

            // Stay on the Register page even if an error(s) occur
            $this->redirect(request()->header('Referer'), navigate: true);
        }
    }

    public function messages(): array
    {
        return [
            'passwordConfirmation.required' => __('forms.field_empty'),
            'passwordConfirmation.same' => __('auth.login.error.validation.confirm_mismatch'),
        ];
    }
}
