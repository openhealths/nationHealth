<x-layouts.patient :showLegacyMessages="false"
    :personId="$personId"
    :uuid="$uuid"
    :patientFullName="$patientFullName"
    :hideNavigation="true"
    :breadcrumbs="[
        ['label' => __('general.home'), 'url' => route('dashboard', [legalEntity()])],
        ['label' => $patientFullName ?? __('care-plan.patient')]
    ]"
>
    <livewire:components.x-message :consume-messages="true" :key="(string) str()->uuid()" />

    <x-slot name="headerActions"></x-slot>

    <div class="shift-content mt-6 pl-4">
        <div class="w-full max-w-screen-xl">
            @include('livewire.care-plan.parts.doctors')
            @include('livewire.care-plan.parts.patient_data', ['medicalNumberField' => 'form.medicalNumber'])
            @include('livewire.care-plan.parts.care_plan_data')
            @include('livewire.care-plan.parts.condition_diagnosis')
            @include('livewire.care-plan.parts.supporting_information')
            @include('livewire.care-plan.parts.additional_info', ['showPlanReferences' => false])

            @if ($errors->any())
                <p role="alert" class="text-error mt-6">{{ $errors->first() }}</p>
            @endif

            <div class="mt-8 flex items-center gap-4 pt-4">
                <button
                    type="button"
                    wire:click="cancel"
                    class="button-primary-outline-red px-6 py-2.5"
                >
                    {{ __('forms.cancel') }}
                </button>

                <button
                    type="button"
                    class="button-primary flex items-center gap-2 px-6 py-2.5"
                    wire:click="save"
                    wire:loading.attr="disabled"
                    wire:target="save"
                >
                    @icon('archive', 'w-4 h-4')
                    <span>{{ __('forms.save') }}</span>
                </button>
            </div>
        </div>
    </div>

    <x-forms.loading />
</x-layouts.patient>
