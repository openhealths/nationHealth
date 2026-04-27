<x-layouts.patient :id="$id" :uuid="$uuid" :patientFullName="$patientFullName">
    <div class="breadcrumb-form p-4 shift-content">
        <div class="flex-1 space-y-6">
            @include('livewire.care-plan.parts.doctors')
            @include('livewire.care-plan.parts.patient_data')
            @include('livewire.care-plan.parts.care_plan_data')
            @include('livewire.care-plan.parts.condition_diagnosis')
            @include('livewire.care-plan.parts.supporting_information')

            <div class="mt-6 flex flex-row items-center gap-4 pt-6 border-t border-gray-100 dark:border-gray-700">
                <div class="flex items-center space-x-3">
                    <a href=" " class="button-primary-outline-red">
                        {{ __('forms.delete') }}
                    </a>

                    <button type="button"
                            class="button-primary-outline flex items-center gap-2 px-4 py-2"
                            wire:click="save"
                    >
                        @icon('archive', 'w-4 h-4')
                        {{ __('forms.save') }}
                    </button>

                    <button type="button" @click="$wire.set('showSignatureModal', true)" class="button-primary">
                        {{ __('forms.save_and_send') }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    <x-signature-modal method="sign" />
</x-layouts.patient>
