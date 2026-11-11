<x-layouts.patient
    :personId="$personId"
    :prepersonId="$prepersonId"
    :patientFullName="$patientFullName"
    :hideNavigation="true"
    :showLegacyMessages="false"
    :title="__('encounters.referral_drawer.title')"
>
    <x-slot name="headerActions">
        <a href="{{ $this->encounterUrl }}" class="button-minor">{{ __('forms.back') }}</a>
    </x-slot>

    <livewire:components.x-message :consume-messages="true" :key="(string) str()->uuid()" />

    <div
        class="breadcrumb-form shift-content p-4"
        @if ($prepareReferralForSigning) wire:init="prepareSavedReferralForSigning" @endif
    >
        <div class="mt-6 w-full max-w-screen-xl">
            <p class="mb-6 text-sm text-gray-600 dark:text-gray-300">
                {{ __('encounters.eprescription.encounter_number', ['number' => $encounterId]) }}
            </p>
            @if ($referralReadOnly)
                <p role="status">{{ $referralSummary['status'] }} {{ $referralSummary['number'] }}</p>
            @endif
            @include('livewire.encounter-package.parts.encounter-referral-form', [
                'encounterReturnUrl' => $this->encounterUrl,
                'referralReadonly' => $referralReadOnly,
            ])
        </div>
    </div>

    <x-signature-modal method="sign" />
</x-layouts.patient>
