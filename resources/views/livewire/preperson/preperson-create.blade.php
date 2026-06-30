@use('App\Models\Preperson')

<div x-data="{ unidentifiedReason: $wire.entangle('form.person.unidentifiedReason') }">
    <div wire:key="preperson-form-{{ $formKey }}">
        @include('livewire.preperson.parts.preperson-reason')
        @include('livewire.preperson.parts.preperson-personal-data')
        @include('livewire.preperson.parts.unidentified-contact-person')
    </div>

    @can('create', Preperson::class)
        <div class="flex flex-wrap gap-4 items-center">
            <button
                type="submit"
                wire:click.prevent="createLocally"
                class="button-primary-outline flex items-center gap-2"
            >
                @icon('archive', 'w-4 h-4')
                {{ __('forms.save') }}
            </button>
            <button
                type="button"
                @click.prevent="showUnidentifiedPatientModal = true"
                class="button-primary"
            >
                {{ __('forms.create') }}
            </button>
        </div>
    @endcan
</div>
