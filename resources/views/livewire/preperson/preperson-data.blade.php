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
    form: {
        firstName: '',
        lastName: '',
        birthDate: '',
        secondName: '',
        taxId: '',
        phoneNumber: '',
        birthCertificate: ''
    },
    mergeSearchPatients: [
        {
            id: 1,
            uuid: '8aeb957b-cb5f-4fb4-ac38-f8401551f3a8',
            firstName: 'Михайло',
            lastName: 'Грушевський',
            secondName: 'Сергійович',
            birthDate: '07.01.1985',
            gender: 'male',
            phones: [{ number: '+380951234567' }],
            birthSettlement: 'Київ',
            taxId: '1234567890',
            birthCertificate: '-'
        }
    ],
    showMergeResults: true,
    currentMethod: 'SMS',
    async searchForMergePatient() {
        this.mergeSearchPatients = [
            {
                id: 1,
                uuid: '8aeb957b-cb5f-4fb4-ac38-f8401551f3a8',
                firstName: 'Михайло',
                lastName: 'Грушевський',
                secondName: 'Сергійович',
                birthDate: '07.01.1985',
                gender: 'male',
                phones: [{ number: '+380951234567' }],
                birthSettlement: 'Київ',
                taxId: '1234567890',
                birthCertificate: '-'
            }
        ];
        this.showMergeResults = true;
    },
    selectMergePatient(patient) {
        this.selectedMergePatient = patient;
        this.showMergePatientDrawer = false;
        this.showMergeAuthDrawer = true;
    },
    async completeMerge(method) {
        window.location.href = '{{ route('persons.patient-data', [legalEntity(), 'person' => 1]) }}';
    }
}">
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
</div>
