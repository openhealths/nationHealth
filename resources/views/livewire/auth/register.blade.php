<div class="fragment">
    <x-authentication-card>

        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
            {{ __('forms.register') }}
        </h2>

        <!-- ====== Forms Section Start -->
        <form wire:submit.prevent="register">
            <div class="form-group group mt-6">
                <input
                    required
                    id="email"
                    type="email"
                    placeholder=" "
                    autocomplete="off"
                    wire:model="email"
                    aria-describedby="@error('email') hasEmailErrorHelp @enderror"
                    class="input @error('email') input-error border-red-500 focus:border-red-500 @enderror peer"
                />

                @error('email')
                    <p id="hasEmailErrorHelp" class="text-error">
                        {{ $message }}
                    </p>
                @enderror

                <label for="email" class="label z-10">
                    {{ __('forms.email') }}
                </label>
            </div>

            <div class="form-group group mt-6">
                <x-password-field>
                    <input
                        required
                        id="password"
                        type="password"
                        placeholder=" "
                        autocomplete="off"
                        wire:model="password"
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
            </div>

            <div class="form-group group mt-6">
                <x-password-field>
                    <input
                        required
                        type="password"
                        placeholder=" "
                        autocomplete="off"
                        id="passwordConfirmation"
                        wire:model="passwordConfirmation"
                        aria-describedby="@error('passwordConfirmation') hasPasswordConfirmationErrorHelp @enderror"
                        class="input @error('passwordConfirmation') input-error border-red-500 focus:border-red-500 @enderror peer"
                    />

                    @error('passwordConfirmation')
                        <p id="hasPasswordConfirmationErrorHelp" class="text-error">
                            {{ $message }}
                        </p>
                    @enderror

                    <label for="passwordConfirmation" class="label z-10">
                        {{ __('forms.password_confirmation') }}
                    </label>
                </x-password-field>
            </div>

            <div class="flex items-center justify-between mt-12 flex-wrap">
                <div class="mt-1 text-left">
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">
                        {{ __('forms.has_account') }}
                        <a
                            wire:navigate
                            href="{{ route('login') }}"
                            class="text-blue-400 hover:text-blue-700 dark:text-blue-600"
                        >
                            {{ __('forms.to_enter') }}
                        </a>
                    </p>
                </div>

                <button
                    type="submit"
                    id="submitButton"
                    class="login-button cursor-pointer"
                >
                    {{ __('forms.register')  }}
                </button>
            </div>
        </form>
        <!-- ====== Forms Section End -->

    </x-authentication-card>

    <x-forms.loading />
    <livewire:components.x-message :key="now()->timestamp" />
</div>
