<x-layouts.patient
    :personId="$personId"
    :patientFullName="$patientFullName"
    :hideNavigation="true"
    :showLegacyMessages="false"
    :title="__('encounters.eprescription.title')"
>
    <x-slot name="headerActions">
        <a href="{{ $this->encounterUrl }}" class="button-minor">{{ __('forms.back') }}</a>
    </x-slot>

    <livewire:components.x-message :consume-messages="true" :key="(string) str()->uuid()" />

    <div class="breadcrumb-form shift-content p-4">
        <div class="mt-6 w-full max-w-screen-xl">
            <p class="mb-6 text-sm text-gray-600 dark:text-gray-300">
                {{ __('encounters.eprescription.encounter_number', ['number' => $encounterId]) }}
            </p>
            @include('livewire.encounter-package.parts.encounter-eprescription-form', ['encounterReturnUrl' => $this->encounterUrl])
        </div>
    </div>

    <x-signature-modal method="sign" />
</x-layouts.patient>
