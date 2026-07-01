<div
    x-data="{ showSignatureModal: $wire.entangle('showSignatureModal') }"
    x-on:close-signature-modal.window="showSignatureModal = false"
    x-on:open-signature-modal.window="showSignatureModal = true"
>
    <livewire:components.x-message :key="now()->timestamp"/>

    <x-header-navigation class="breadcrumb-form shift-content">
        <x-slot name="title">
            {{ $pageTitle ?? __('forms.employee') }}
        </x-slot>
    </x-header-navigation>

    <section
        class="section-form shift-content"
        x-data="{
            employeeType: $wire.entangle('form.employeeType'),
            isMedicalType() {
                return {{ Js::from(config('ehealth.medical_employees')) }}.includes(this.employeeType);
            }
        }"
    >
        <form wire:submit.prevent="save" class="form space-y-8">

            {{-- Part 1: Personal Data --}}
            @include('livewire.employee.parts.party')

            {{-- Part 2: Documents --}}
            @include('livewire.employee.parts.documents')

            {{-- Part 3: Position --}}
            @include('livewire.employee.parts.position')

            {{-- Part 4: Doctor-specific fields --}}
            <template x-if="isMedicalType()">
                <div class="space-y-8" wire:key="doctor-specific-fields">
                    @include('livewire.employee.parts.education')
                    @include('livewire.employee.parts.specialities')
                    @include('livewire.employee.parts.science_degree')
                    @include('livewire.employee.parts.qualifications')
                </div>
            </template>

            {{-- Action Buttons --}}
            <div class="mt-6 flex flex-row items-center gap-4 border-t border-gray-200 pt-6">
                <div class="flex items-center space-x-4">
                    <a href="{{ route('employee.index', ['legalEntity' => legalEntity()->id]) }}" class="button-minor">
                        {{__('forms.cancel')}}
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
        </form>
    </section>

    @include('livewire.employee.parts.modals.signature-modal')

    <x-forms.loading/>
</div>
