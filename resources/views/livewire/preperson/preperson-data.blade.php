<div x-data="{
    showMergePatientDrawer: false,
    showMergeAuthDrawer: false,
    showMergeConfirmationDrawer: false,
    showMergeDocumentsDrawer: false,
    showMergeSmsDrawer: false,
    showMergeFinalConsentDrawer: false,
    showMergeSignatureDrawer: false,
    showConsentFormModal: false,
    selectedMergePatient: null,
    currentMethod: ''
}">
    <x-layouts.patient
        :prepersonId="$preperson->id"
        :patientFullName="$preperson->fullName"
        :title="'ID ' . $preperson->externalId"
        :activeTab="'patient-data'"
    >
        <x-slot name="headerActions">
            <livewire:preperson.preperson-merge :preperson="$preperson" />
        </x-slot>

        @include('livewire.preperson.parts.preperson-data')
    </x-layouts.patient>
</div>
