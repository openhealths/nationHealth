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
            @include('livewire.care-plan.parts.patient_data')
            @include('livewire.care-plan.parts.care_plan_data')
            @include('livewire.care-plan.parts.condition_diagnosis')
            @include('livewire.care-plan.parts.supporting_information')
            @include('livewire.care-plan.parts.additional_info', ['context' => 'create'])

            @if ($errors->any())
                <p role="alert" class="text-error mt-6">{{ $errors->first() }}</p>
            @endif

            <div class="mt-8 flex items-center gap-4 pt-4">
                <button
                    type="button"
                    wire:click.prevent="{{ method_exists($this, 'delete') ? 'delete' : 'cancel' }}"
                    class="button-primary-outline-red px-6 py-2.5"
                >
                    {{ __('forms.delete') }}
                </button>

                <button
                    type="button"
                    class="button-primary-outline flex items-center gap-2 px-6 py-2.5"
                    wire:click="save"
                >
                    @icon('archive', 'w-4 h-4')
                    <span>{{ __('forms.save') }}</span>
                </button>

                <button type="button" wire:click="startSigningProcess" class="button-primary px-8 py-2.5">
                    {{ __('care-plan.create_care_plan') }}
                </button>
            </div>
        </div>
    </div>

    <x-signature-modal method="sign" />
    <x-forms.loading />
</x-layouts.patient>
