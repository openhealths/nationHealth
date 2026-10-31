<div class="fragment">
    <x-authentication-card>
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
            {{ __('forms.enter') }}
        </h2>

        @if (!$showCodeForm)
            <form wire:submit.prevent="login" x-data="{ isFirstLogin: $wire.entangle('isFirstLogin') }">
                <div class="form-group group pt-5">
                    <input
                        wire:model="email"
                        required
                        type="email"
                        placeholder=" "
                        id="email"
                        autocomplete="off"
                        name="email"
                        aria-describedby="@error('email') emailErrorHelp @enderror"
                        class="input @error('email') input-error border-red-500 focus:border-red-500 @enderror peer"
                    />

                    @error('email')
                        <p id="emailErrorHelp" class="text-error">
                            {{ $message }}
                        </p>
                    @enderror

                    <label for="email" class="label z-10 mb-4">
                        {{ __('forms.email') }}
                    </label>
                </div>

                <div class="block mt-4">
                    <div class="form-group group">
                        <input
                            x-model="isFirstLogin"
                            type="checkbox"
                            id="is_first_login"
                            class="default-checkbox text-blue-500 focus:ring-blue-300"
                        >

                        <label for="is_first_login" class="ms-2 text-xs font-medium text-gray-500 dark:text-gray-300">
                            {{ __('forms.first_login') }}
                        </label>
                    </div>
                </div>

                <div
                    class="form-group group pt-5"
                    x-show="!isFirstLogin"
                    x-cloak
                >
                    <x-password-field>
                        <input
                            wire:model="password"
                            :required="!isFirstLogin"
                            type="password"
                            placeholder=" "
                            autocomplete="off"
                            id="password"
                            aria-describedby="@error('password') passwordErrorHelp @enderror"
                            class="input @error('password') input-error border-red-500 focus:border-red-500 @enderror peer"
                        />

                        @error('password')
                            <p id="passwordErrorHelp" class="text-error">
                                {{ $message }}
                            </p>
                        @enderror

                        <label for="password" class="label z-10">
                            {{ __('forms.password') }}
                        </label>
                    </x-password-field>
                </div>

                <div class="flex items-center justify-end mt-4">
                    <button type="submit" id="submitButton" class="login-button cursor-pointer">
                        {{ __('forms.enter') }}
                    </button>
                </div>

                <div class="mt-6 text-center">
                    <p class="text-[13px] font-medium text-gray-400 dark:text-gray-400">
                        <a
                            href="{{ route('register') }}"
                            wire:navigate
                            class="hover:text-gray-700 text-gray-400 dark:text-gray-400"
                        >
                            {{ __('forms.need_register') }} /
                        </a>

                        @if (Route::has('forgot.password'))
                            <a
                                href="{{ route('forgot.password') }}"
                                wire:navigate
                                class="hover:text-gray-700 text-gray-400 dark:text-gray-400"
                            >
                                {{ __('auth.login.forgot_password') }}
                            </a>
                        @endif
                    </p>
                </div>
            </form>
        @else
            <form wire:submit.prevent="verify">
                <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                    {{ __('auth.login.two_factor.prompt') }}
                </p>

                <div class="form-group group">
                    <input
                        wire:model="code"
                        required
                        type="text"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        placeholder=" "
                        id="code"
                        aria-describedby="@error('code') codeErrorHelp @enderror"
                        class="input @error('code') input-error border-red-500 focus:border-red-500 @enderror peer"
                    />

                    @error('code')
                        <p id="codeErrorHelp" class="text-error">
                            {{ $message }}
                        </p>
                    @enderror

                    <label for="code" class="label z-10">
                        {{ __('auth.login.two_factor.code_label') }}
                    </label>
                </div>

                <div class="flex items-center justify-between mt-4">
                    <button
                        type="button"
                        wire:click="resendCode"
                        class="text-[13px] font-medium text-gray-400 hover:text-gray-700 dark:text-gray-400 cursor-pointer"
                    >
                        {{ __('auth.login.two_factor.resend') }}
                    </button>

                    <button type="submit" id="verifyButton" class="login-button cursor-pointer">
                        {{ __('forms.enter') }}
                    </button>
                </div>
            </form>
        @endif
    </x-authentication-card>

    <x-forms.loading />
    <livewire:components.x-message :key="now()->timestamp" />
</div>
