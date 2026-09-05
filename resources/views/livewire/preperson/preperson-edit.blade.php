@use('App\Models\Preperson')

<div x-data="{ reason: $wire.entangle('form.reasonContext.reason') }">
    <x-header-navigation
        class="breadcrumb-form"
        :breadcrumbs="[
            ['label' => __('forms.home'), 'url' => route('dashboard', [legalEntity()])],
            ['label' => __('preperson.label'), 'url' => route('prepersons.index', [legalEntity()])],
            ['label' => __('preperson.update_patient')]
        ]"
    >
        <x-slot name="title">{{ __('preperson.update_patient') }}</x-slot>
    </x-header-navigation>

    <livewire:components.x-message :key="time()" />

    <div>
        @include('livewire.preperson.parts.preperson-reason')
        @include('livewire.preperson.parts.preperson-personal-data')
        @include('livewire.preperson.parts.emergency-contact', [
                    'showContactPersonOpen' => $this->form->hasEmergencyContactData()
                ])
    </div>

    @can('create', Preperson::class)
        <div class="flex flex-wrap items-center gap-4">
            <button
                type="submit"
                wire:click.prevent="updateLocally"
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
