<div class="fragment">
    <x-authentication-card>
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
            {{ __('forms.new_password') }}
        </h2>

        <!-- ====== Forms Section Start -->
        <form
            autocomplete="off"
            wire:keydown.enter="resetPassword"
            class="mb-0"
        >
            <div class="form-group group mt-6">
                <x-password-field>
                    <input
                        required
                        type="password"
                        placeholder=" "
                        id="password"
                        wire:model="password"
                        aria-describedby="@error('password') hasPasswordErrorHelp @enderror"
                        class="input @error('password') input-error border-red-500 focus:border-red-500 @enderror peer"
                    />

                    @error('password')
                        <p id="hasPasswordErrorHelp" class="text-error">
                            {{ $message }}
                        </p>
                    @enderror

                    <p id="passwordResetNewOneHelp" class="text-note">
                        {{ __('forms.type_new_password') }}
                    </p>

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

            <div class="flex items-center mt-8">
                <button
                    type="button"
                    id="submitButton"
                    class="default-button cursor-pointer w-full"
                    wire:click="resetPassword"
                >
                    {{ __('forms.set_new_password')  }}
                </button>
            </div>
        </form>
        <!-- ====== Forms Section End -->

    </x-authentication-card>

    <x-forms.loading />
    <livewire:components.x-message :key="now()->timestamp" />
</div>
