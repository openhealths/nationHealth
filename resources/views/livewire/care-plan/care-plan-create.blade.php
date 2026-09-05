<x-layouts.patient
    :personId="$personId"
    :uuid="$uuid"
    :patientFullName="$patientFullName"
    :hideNavigation="$allowsPatientChange"
    :breadcrumbs="[
        ['label' => __('general.home') ?? 'Головна', 'url' => route('dashboard', [legalEntity()])],
        ['label' => $patientFullName ?? __('care-plan.patient') ?? 'Пацієнт']
    ]"
>
    <x-slot name="headerActions"></x-slot>

    <div class="shift-content mt-6 pl-4">
        <div class="w-full max-w-screen-xl">
            @include('livewire.care-plan.parts.doctors')
            @include('livewire.care-plan.parts.patient_data')
            @include('livewire.care-plan.parts.care_plan_data')
            @include('livewire.care-plan.parts.condition_diagnosis')
            @include('livewire.care-plan.parts.supporting_information')
            @include('livewire.care-plan.parts.additional_info', ['context' => 'create'])

            <div class="mt-8 flex items-center gap-4 pt-4">
                <button
                    type="button"
                    wire:click.prevent="{{ (isset($carePlan) && $carePlan->exists) ? 'delete' : 'cancel' }}"
                    class="button-primary-outline-red px-6 py-2.5"
                >
                    {{ __('forms.delete') ?? 'Видалити' }}
                </button>

                <button
                    type="button"
                    class="button-primary-outline flex items-center gap-2 px-6 py-2.5"
                    wire:click="save"
                >
                    @icon('archive', 'w-4 h-4')
                    <span>{{ __('forms.save') ?? 'Зберегти' }}</span>
                </button>

                <button type="button" wire:click="startSigningProcess" class="button-primary px-8 py-2.5">
                    {{ __('care-plan.create_care_plan') ?? 'Створити план лікування' }}
                </button>
            </div>
        </div>
    </div>

    @include('livewire.care-plan.modals.authentication')
    @include('livewire.care-plan.modals.method-selection')

    {{-- Async approval job polling — only active when $isPolling is true --}}
    @if ($isPolling)
        <div wire:poll.2s="checkApprovalJobStatus" class="fixed right-6 bottom-6 z-50">
            <div class="flex items-center gap-3 rounded-xl border border-blue-200 bg-white px-4 py-3 text-sm text-blue-700 shadow-lg dark:border-blue-700 dark:bg-gray-800 dark:text-blue-300">
                <svg class="h-4 w-4 flex-shrink-0 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                </svg>
                {{ __('care-plan.approval_processing') }}
            </div>
        </div>
    @endif

    <x-signature-modal method="sign" />
    <x-forms.loading />
</x-layouts.patient>
