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

        <form class="form flex flex-col gap-2">
            <x-signature-fields />

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
