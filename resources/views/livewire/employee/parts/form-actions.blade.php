{{-- Action Buttons --}}
<div class="mt-6 flex flex-row items-center gap-4 border-t border-gray-200 pt-6">
    <div class="flex items-center space-x-4">
        <a href="{{ route('employee.index', legalEntity()) }}" class="button-minor">
            {{ __('forms.cancel') }}
        </a>
        {{-- This button now just toggles the Alpine.js modal --}}
        <button type="button" wire:click="prepareForSigning" class="button-primary">
            {{ __('forms.complete_the_interaction_and_sign') }}
        </button>
    </div>
    <div class="flex items-center space-x-4">
        <button
            type="submit"
            class="button-primary-outline flex items-center gap-2 px-4 py-2"
            wire:loading.attr="disabled"
            wire:target="save"
        >
            <svg
                class="w-5 h-5"
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="2"
            >
                <path stroke-linejoin="round" d="M10 12v1h4v-1m4 7H6a1 1 0 0 1-1-1V9h14v9a1 1 0 0 1-1 1ZM4 5h16a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Z"/>
            </svg>
            <span wire:loading.remove wire:target="save">{{ __('forms.save') }}</span>
            <span wire:loading wire:target="save">{{ __('forms.saving') }}...</span>
        </button>
    </div>
</div>
