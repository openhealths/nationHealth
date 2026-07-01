<div>
    <livewire:components.x-message :key="now()->timestamp"/>

    <div
        x-data="{ showSignatureModal: $wire.entangle('showSignatureModal') }"
        x-on:close-signature-modal.window="showSignatureModal = false"
        x-on:open-signature-modal.window="showSignatureModal = true"
    >
        <x-header-navigation class="breadcrumb-form shift-content">
            <x-slot name="title">{{ $pageTitle ?? '' }}</x-slot>
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

                {{-- 1: position (active, cause isPositionDataLocked=false) --}}
                @include('livewire.employee.parts.position') {{-- --}}

                {{--  2: doctor/specialist data (active) --}}
                <template x-if="isMedicalType()">
                    <div class="space-y-8" wire:key="doctor-specific-fields">
                        @include('livewire.employee.parts.education')
                        @include('livewire.employee.parts.specialities')
                        @include('livewire.employee.parts.science_degree')
                        @include('livewire.employee.parts.qualifications')
                    </div>
                </template>

                {{--  3: Party (disables, isPersonalDataLocked=true) --}}
                @include('livewire.employee.parts.party')

                {{--  4: Documents (disabled) --}}
                @include('livewire.employee.parts.documents')

                {{--  5: Buttons --}}
                @include('livewire.employee.parts.form-actions')

            </form>
        </section>

        @include('livewire.employee.parts.modals.signature-modal')
        <x-forms.loading/>

    </div>
</div>
