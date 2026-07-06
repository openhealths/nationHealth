<x-layouts.patient
    :prepersonId="$preperson->id"
    :patientFullName="$preperson->fullName"
    :title="'ID ' . $preperson->externalId"
    :activeTab="'patient-data'"
>
    <x-slot name="headerActions">
        @include('livewire.preperson.parts.drawers.merge-patients')
        @include('livewire.preperson.parts.drawers.merge-auth-methods')
        @include('livewire.preperson.parts.drawers.merge-confirmation')
        @include('livewire.preperson.parts.drawers.merge-sms-verification')
        @include('livewire.preperson.parts.drawers.merge-documents-upload')
        @include('livewire.preperson.parts.drawers.merge-final-consent')
        @include('livewire.preperson.modals.consent-form-modal')
        @include('livewire.preperson.parts.drawers.merge-signature')
    </x-slot>

    @include('livewire.preperson.parts.preperson-data')
</x-layouts.patient>
