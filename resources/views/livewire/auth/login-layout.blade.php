@php
    $chooseRole = __('auth.login.choose_role');
@endphp

<div class="fragment">
    <livewire:components.x-message :key="now()->timestamp" />

    <x-authentication-card>

        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
            {{ __('forms.enter') }}
        </h2>

        <form
            wire:submit.prevent="login"
            x-data="{
                roleMsg: '{{ $chooseRole }}',
                role: $wire.entangle('role'),
                isLocalAuth: $wire.entangle('isLocalAuth'),
                showRoleSelect: $wire.entangle('showRoleSelect'),
                isSingleRoleAuth: $wire.entangle('isSingleRoleAuth'),
                init() {
                    this.$watch('isSingleRoleAuth', value => {
                        if (this.isSingleRoleAuth && !this.isLocalAuth) {
                            this.showRoleSelect = true;
                        } else if (this.isFirstLogin) {
                            this.showRoleSelect = true;
                        } else {
                            this.showRoleSelect = false;
                            this.isSingleRoleAuth = false; // Reset single role auth when checkbox is still checked (for isLocalAuth case)
                            this.role = null;
                        }
                    });

                    this.$watch('role', value => {
                        if (value) {
                            this.roleMsg = '';
                        } else {
                            this.roleMsg = '{{ $chooseRole }}';
                        }
                    });
                }
            }"
        >
            <div class="form-group group">
                <input wire:model="email"
                       required
                       type="email"
                       placeholder=" "
                       id="email"
                       autocomplete="off"
                       name="email"
                       aria-describedby="{{ $hasEmailError ? 'hasEmailErrorHelp' : '' }}"
                       class="input {{ $hasEmailError  ? 'input-error border-red-500 focus:border-red-500' : ''}} peer"
                />

                @if($hasEmailError)
                    <p id="hasEmailErrorHelp" class="text-error">
                        {{ $errors->first('email') }}
                    </p>
                @endif

                <label for="email" class="label z-10">
                    {{ __('forms.email') }}
                </label>
            </div>

            {{-- Legal Entity Select --}}
            <x-forms.combobox :options="$legalEntitiesList"
                              x-show="!isLocalAuth"
                              x-cloak
                              x-transition:enter="transition ease-out duration-300"
                              x-transition:enter-start="opacity-0 scale-95"
                              x-transition:enter-end="opacity-100 scale-100"
                              is-required="!isLocalAuth"
                              bind="legalEntityUUID"
                              bindValue='uuid'
                              bindParam='name'
                              :label="__('Медичний Заклад')"
                              class="!z-[100] mt-6"
            />

            {{-- Role select --}}
            <div x-show="showRoleSelect && !isLocalAuth" x-cloak>
                <div class="form-group group">
                    <select wire:model="role" class="input-select peer">
                        <option value="" selected>{{ __('forms.select') }}</option>
                        @foreach($rolesList as $role)
                            <option value="{{ $role }}">{{ __("users.role.$role") }}</option>
                        @endforeach
                    </select>

                    <p x-text="roleMsg" class="text-error"></p>
                </div>
            </div>

            @yield('showPassword')

            <div class="flex items-center justify-end mt-4">
                <button type="submit"
                        id="submitButton"
                        class="login-button cursor-pointer"
                >
                    {{ __('forms.enter') }}
                </button>
            </div>

            <div class="mt-6 text-center">
                <p class="text-[0.8125rem] font-medium text-gray-400 dark:text-gray-400">
                    <a href="{{ route('register') }}"
                       wire:navigate
                       class="hover:text-gray-700 text-gray-400 dark:text-gray-400"
                    >
                        {{ __('forms.need_register') }} /
                    </a>

                    @if (Route::has('forgot.password'))
                        <a href="{{ route('forgot.password') }}"
                           wire:navigate
                           class="hover:text-gray-700 text-gray-400 dark:text-gray-400"
                        >
                            {{ __('auth.login.forgot_password') }}
                        </a>
                    @endif
                </p>
            </div>
        </form>
    </x-authentication-card>

    <x-forms.loading />
</div>
