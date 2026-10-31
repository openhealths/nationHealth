@extends('livewire.auth.login-layout')

@section('showPassword')
    <div class="mt-4"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
    >
        <div class="form-group group pb-5">
            <x-password-field>
                <input
                    wire:model="password"
                    type="password"
                    placeholder=" "
                    autocomplete="off"
                    id="password"
                    aria-describedby="@error('password') hasPasswordErrorHelp @enderror"
                    class="input @error('password') input-error border-red-500 focus:border-red-500 @enderror peer"
                />

                @error('password')
                    <p id="hasPasswordErrorHelp" class="text-error">
                        {{ $message }}
                    </p>
                @enderror

                <label for="password" class="label z-10">
                    {{ __('forms.password') }}
                </label>
            </x-password-field>

            <div class="form-group group mt-4">
                <input
                    x-model="isSingleRoleAuth"
                    :checked="isSingleRoleAuth"
                    type="checkbox"
                    id="is_single_role_auth"
                    class="default-checkbox text-blue-500 focus:ring-blue-300"
                >

                <label for="is_single_role_auth" class="ms-2 text-xs font-medium text-gray-500 dark:text-gray-300">
                    {{ __('auth.login.single_role_auth') }}
                </label>
            </div>
        </div>
    </div>
@endsection
