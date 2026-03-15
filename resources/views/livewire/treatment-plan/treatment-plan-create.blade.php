@use('App\Livewire\TreatmentPlan\TreatmentPlanCreate')

<section class="section-form">
    <x-header-navigation x-.data="{ showFilter: false }" class="breadcrumb-form">
        <x-slot name="title">
            {{ __('treatment-plan.new_treatment_plan') }}
        </x-slot>
    </x-header-navigation>

    <div x-data="{ showSignatureModal: $wire.entangle('showSignatureModal').live }" class="form shift-content" wire:key="{{ time() }}">

        @include('livewire.treatment-plan.parts.doctors')
        @include('livewire.treatment-plan.parts.patient_data')
        @include('livewire.treatment-plan.parts.treatment_plan_data')
        @include('livewire.treatment-plan.parts.condition_diagnosis')
        @include('livewire.treatment-plan.parts.supporting_information')
        @include('livewire.treatment-plan.parts.additional_info', ['context' => 'create'])

        <div class="mt-6 flex flex-row items-center gap-4 pt-6">
            <div class="flex items-center space-x-3">
                <button type="button"
                        class="button-primary-outline flex items-center gap-2 px-4 py-2"
                        wire:click="save"
                >
                    @icon('archive', 'w-4 h-4')
                    {{ __('forms.save') }}
                </button>

                <button type="button" @click="showSignatureModal = true" class="button-primary">
                    {{ __('forms.sign_with_KEP') }}
                </button>
            </div>
        </div>

        @include('components.signature-modal', ['method' => 'sign'])
    </div>

    <x-messages/>
    <x-forms.loading/>
</section>
