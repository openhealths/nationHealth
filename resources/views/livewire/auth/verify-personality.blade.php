<div>
    <img class="mx-auto" src="{{ Vite::asset('resources/images/logo.webp') }}" alt="OpenHealth Logo">

    <fieldset class="fieldset">
        <legend class="legend">{{ __('forms.enter') }}</legend>

        <div class="bg-blue-100 rounded-lg">
            <div class="p-4">
                <div class="flex items-center gap-2 mb-2">
                    @icon('alert-circle', 'w-5 h-5 text-blue-700')
                    <p class="font-semibold text-blue-700">{{ __('auth.login.additional_verification') }}</p>
                </div>
                <p class="text-sm text-blue-700">{{ __('auth.login.first_login_info') }}</p>
            </div>
        </div>

        {{-- KEP Provider --}}
        <form class="form flex flex-col gap-2">
            <div class="form-group">
                <label for="knedp" class="label">{{ __('forms.knedp') }} *</label>

                <select class="input-select peer" wire:model="knedp" id="knedp" required>
                    <option value="" selected>{{ __('forms.select') }}</option>
                    @foreach(signatureService()->getCertificateAuthorities() as $certificateType)
                        <option value="{{ $certificateType['id'] }}" wire:key="{{ $certificateType['id'] }}">
                            {{ $certificateType['name'] }}
                        </option>
                    @endforeach
                </select>

                @error('knedp') <p class="text-error">{{ $message }}</p> @enderror
            </div>

            {{-- Key File --}}
            <div>
                <label for="keyContainerUpload" class="default-label">{{ __('forms.key_container_upload') }} *</label>
                <div x-data="{ fileName: '{{ __('forms.no_file_chosen') }}' }" class="file-input-wrapper">
                    <label for="keyContainerUpload" class="file-input-button">
                        {{ __('forms.choose_file') }}
                    </label>
                    <span class="file-input-text" x-text="fileName"></span>
                    <input wire:model="keyContainerUpload"
                           type="file"
                           class="hidden"
                           id="keyContainerUpload"
                           name="keyContainerUpload"
                           accept=".dat,.pfx,.pk8,.zs2,.jks,.p7s"
                           @change="fileName = $event.target.files[0] ? $event.target.files[0].name : '{{ __('forms.no_file_chosen') }}'"
                    >
                </div>
                <div wire:loading wire:target="keyContainerUpload" class="text-sm text-gray-500 mt-2">
                    {{ __('general.loading') }}...
                </div>
            </div>

            @error('keyContainerUpload') <p class="text-error">{{ $message }}</p> @enderror

            {{-- Password --}}
            <div class="form-group">
                <input
                    wire:model="password"
                    class="input peer @error('form.patient.firstName') input-error @enderror"
                    type="password"
                    id="password"
                    placeholder=" "
                >
                <label for="password" class="label">{{ __('forms.password') }} *</label>
            </div>

            @error('password') <p class="text-error">{{ $message }}</p> @enderror

            {{-- Action buttons --}}
            <div class="flex gap-8 mt-6">
                <button type="button" class="button-minor">
                    {{ __('forms.cancel') }}
                </button>

                <button
                    wire:click="login"
                    type="button"
                    class="button-primary"
                    wire:loading.attr="disabled"
                    wire:target="login"
                >
                    <span wire:loading.remove wire:target="login">{{ __('forms.to_enter') }}</span>
                    <span wire:loading wire:target="login">{{ __('general.loading') }}</span>
                </button>
            </div>
        </form>
    </fieldset>

    <x-forms.loading />
    <livewire:components.x-message :key="time()" />
</div>
