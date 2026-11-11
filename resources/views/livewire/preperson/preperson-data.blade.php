@use('App\Models\MedicalEvents\Sql\Encounter')

<div
    x-data="{
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
}"
>
    <x-layouts.patient
        :prepersonId="$preperson->id"
        :patientFullName="$preperson->fullName"
        :title="'ID ' . $preperson->externalId"
        :activeTab="'patient-data'"
    >
        <x-slot name="headerActions">
            @can('create', Encounter::class)
                <a
                    href="{{ route('prepersons.encounters.create', [legalEntity(), 'preperson' => $preperson->id]) }}"
                    class="button-primary flex items-center gap-2 px-5 py-2 text-sm shadow-sm"
                >
                    @icon('plus', 'w-4 h-4')
                    {{ __('encounters.new') }}
                </a>

                <div x-data="{ open: false }" class="relative">
                    <button
                        @click="open = ! open"
                        @click.outside="open = false"
                        class="button-primary-outline flex items-center gap-2 px-4 py-2 text-sm shadow-sm"
                        style="margin: 0 !important"
                    >
                        {{ __('forms.actions') }}
                        @icon('menu', 'w-4 h-4')
                    </button>

                    <div
                        x-show="open"
                        x-cloak
                        x-transition:enter="transition ease-out duration-100"
                        x-transition:enter-start="transform opacity-0 scale-95"
                        x-transition:enter-end="transform opacity-100 scale-100"
                        x-transition:leave="transition ease-in duration-75"
                        x-transition:leave-start="transform opacity-100 scale-100"
                        x-transition:leave-end="transform opacity-0 scale-95"
                        class="dropdown-menu right-0 flex max-h-87.5 w-max max-w-100 min-w-65 flex-col"
                    >
                        <div class="custom-scrollbar overflow-y-auto">
                            <a
                                href="{{ route('prepersons.encounters.create', [legalEntity(), 'preperson' => $preperson->id, 'type' => 'discharge']) }}"
                                class="dropdown-item"
                            >
                                @icon('hospital-bed', 'w-4 h-4 text-gray-500 shrink-0')
                                {{ __('patients.discharge_patient') }}
                            </a>
                            <a
                                href="{{ route('prepersons.encounters.create', [legalEntity(), 'preperson' => $preperson->id, 'type' => 'hospitalization_refusal']) }}"
                                class="dropdown-item"
                            >
                                @icon('cancel', 'w-4 h-4 text-gray-500 shrink-0')
                                {{ __('patients.hospitalization_refusal') }}
                            </a>
                        </div>
                    </div>
                </div>
            @endcan

            <livewire:preperson.preperson-merge :preperson="$preperson" />

            <button
                type="button"
                wire:click="syncFromEHealth({{ $preperson->id }})"
                wire:target="syncFromEHealth"
                wire:loading.attr="disabled"
                class="button-sync flex items-center gap-2 px-5 py-2 text-sm whitespace-nowrap shadow-sm"
            >
                @icon('refresh', 'w-4 h-4')
                {{ __('forms.synchronise_with_eHealth') }}
            </button>
        </x-slot>

        @include('livewire.preperson.parts.preperson-data')
    </x-layouts.patient>

    @if ($editingId)
        @include('livewire.preperson.modals.edit-preperson')
    @endif
    @include('livewire.preperson.modals.register-death')
    @include('livewire.preperson.modals.register-death-date')
</div>
