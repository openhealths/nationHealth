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
    currentMethod: '',
    isEditModalOpen: false,
    editSnapshot: null,
    openEdit() {
        this.editSnapshot = JSON.parse(JSON.stringify(this.$wire.get('form.person')));
        this.isEditModalOpen = true;
    },
    cancelEdit() {
        this.$wire.set('form.person', this.editSnapshot, false);
        this.isEditModalOpen = false;
    },
    confirmEdit() {
        this.$wire.saveEdit(this.$wire.get('editingId')).then(() => {
            this.editSnapshot = JSON.parse(JSON.stringify(this.$wire.get('form.person')));
            this.isEditModalOpen = false;
        });
    },
    deathPrepersonId: {{ $preperson->id }},
    showRegisterDeathModal: false,
    showRegisterDeathDateModal: false
}">
    @use('App\Models\MedicalEvents\Sql\Encounter')
    <x-layouts.patient
        :prepersonId="$preperson->id"
        :patientFullName="$preperson->fullName"
        :title="'ID ' . $preperson->externalId"
        :activeTab="'patient-data'"
    >
        <x-slot name="headerActions">
            @can('create', Encounter::class)
                <a href="{{ route('prepersons.encounter.create', [legalEntity(), 'preperson' => $preperson->id]) }}"
                   class="flex items-center gap-2 button-primary px-5 py-2 text-sm shadow-sm"
                >
                    @icon('plus', 'w-4 h-4')
                    {{ __('patients.starts_interacting') }}
                </a>
            @endcan

            <livewire:preperson.preperson-merge :preperson="$preperson" />

            <button
                type="button"
                wire:click="syncFromEHealth({{ $preperson->id }})"
                wire:target="syncFromEHealth"
                wire:loading.attr="disabled"
                class="button-sync flex items-center gap-2 whitespace-nowrap px-5 py-2 text-sm shadow-sm"
            >
                @icon('refresh', 'w-4 h-4')
                {{ __('forms.synchronise_with_eHealth') }}
            </button>
        </x-slot>

        @include('livewire.preperson.parts.preperson-data')
    </x-layouts.patient>

    @if($editingId)
        @include('livewire.preperson.modals.edit-preperson')
    @endif
    @include('livewire.preperson.modals.register-death')
    @include('livewire.preperson.modals.register-death-date')
</div>


