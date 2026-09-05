@use('App\Models\Preperson')

<div x-data="{ reason: $wire.entangle('form.reasonContext.reason') }">
    <livewire:components.x-message :key="time()" />

    @can('create', Preperson::class)
        <div>
            @include('livewire.preperson.parts.preperson-reason')
            @include('livewire.preperson.parts.preperson-personal-data')
            @include('livewire.preperson.parts.emergency-contact')
        </div>

        <div class="flex flex-wrap items-center gap-4">
            <button
                type="submit"
                wire:click.prevent="createLocally"
                class="button-primary-outline flex items-center gap-2"
            >
                @icon('archive', 'w-4 h-4')
                {{ __('forms.save') }}
            </button>
            <button type="button" wire:click.prevent="create" class="button-primary">{{ __('forms.create') }}</button>
        </div>
    @endcan

    <div x-data="{ showAlternativeIdentificationModal: $wire.entangle('showAlternativeIdentificationModal') }">
        @include('livewire.preperson.modals.warning')
    </div>
</div>
