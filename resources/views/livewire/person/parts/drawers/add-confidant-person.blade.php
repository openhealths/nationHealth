<div x-show="showConfidantPersonDrawer"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="translate-x-full"
     x-transition:enter-end="translate-x-0"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="translate-x-0"
     x-transition:leave-end="translate-x-full"
     x-cloak
     class="fixed top-0 right-0 z-40 h-screen pt-20 p-4 overflow-y-auto transition-transform bg-white w-4/5 dark:bg-gray-800 shadow-2xl"
     x-data="{
         showResults: false,
         showDocumentDrawer: false,
     }"
     id="legal-representative-drawer"
     tabindex="-1"
>
    <h3 class="modal-header"
        x-text="isEditingLegalRep ? '{{ __('patients.edit_confidant_person') }}' : '{{ __('patients.add_confidant_person') }}'"
    ></h3>

    <div class="mt-4" x-data="{ showFilter: true }">
        <div class="mb-8 flex items-center gap-1 font-semibold text-gray-900 dark:text-white">
            @icon('search-outline', 'w-4.5 h-4.5')
            <p>{{ __('patients.patient_search') }}</p>
        </div>

        @include('livewire.person.parts.search-filter', ['context' => 'create'])
        <div class="mb-9 mt-6 flex gap-2">
            <button type="button"
                    class="flex items-center gap-2 button-primary"
                    @click="showResults = true"
                    wire:click.prevent="searchForPerson"
            >
                @icon('search', 'w-4 h-4')
                <span>{{ __('patients.search') }}</span>
            </button>
            <button type="button"
                    class="button-primary-outline-red"
                    @click="showResults = false; resetSearchFilters()"
            >
                {{ __('forms.reset_all_filters') }}
            </button>
        </div>
    </div>

    {{-- Results of founded --}}
    <div class="space-y-6 mt-6" x-show="showResults" x-transition x-cloak>
        <template x-for="patient in $wire.confidantPerson" :key="patient.id">
            <fieldset class="fieldset" :class="{ 'ring-2 ring-blue-500': selectedPatient?.id === patient.id }">
                <legend class="legend"
                        x-text="`${patient.lastName} ${patient.firstName} ${patient.secondName || ''}`"
                ></legend>

                <div
                        class="flex flex-wrap items-center justify-between gap-4 border-b border-gray-200 dark:border-gray-700 pb-4">
                    <div class="flex items-center flex-wrap gap-x-6 gap-y-2 text-sm text-gray-500 mt-2">
                        <span class="flex items-center gap-1.5" x-show="patient.birthDate">
                            <svg class="w-6 h-6 text-gray-800 dark:text-white" aria-hidden="true"
                                 xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none"
                                 viewBox="0 0 24 24">
                                <path stroke="currentColor" stroke-linecap="round" stroke-width="2"
                                      d="M8 4H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2H8z" />
                                <path stroke="currentColor" stroke-linecap="round" stroke-width="2"
                                      d="M16 2v4M8 2v4M3 10h18" />
                            </svg>
                            <span x-text="patient.birthDate"></span>
                        </span>

                        <span class="flex items-center gap-1.5 min-w-0" x-show="patient.phone">
                            @icon('tabler-phone', 'w-6 h-6 text-gray-800 dark:text-white')
                            <a :href="'tel:' + patient.phone"
                               class="truncate hover:underline font-medium text-gray-900 dark:text-gray-200 text-base"
                               x-text="patient.phone"
                            ></a>
                        </span>

                        <span class="flex items-center gap-1.5" x-show="patient.gender">
                            <template x-if="patient.gender === 'male'">
                                <span class="flex items-center gap-1.5">
                                    @icon('men', 'w-6 h-6 text-gray-800 dark:text-white')
                                    <span>{{ __('patients.male') }}</span>
                                </span>
                            </template>
                            <template x-if="patient.gender === 'female'">
                                <span class="flex items-center gap-1.5">
                                    @icon('women', 'w-6 h-6 text-gray-800 dark:text-white')
                                    <span>{{ __('patients.female') }}</span>
                                </span>
                            </template>
                        </span>
                    </div>

                    <button type="button"
                            class="button-primary text-sm"
                            @click="selectedPatient = patient"
                            wire:click.prevent="chooseConfidantPerson(patient)"
                    >
                        {{ __('forms.select') }}
                    </button>
                </div>

                <div class="flow-root mt-4">
                    <div class="max-w-screen-xl">
                        <table class="table-input w-full table-auto">
                            <thead class="thead-input">
                            <tr>
                                <th scope="col" class="th-input">{{ __('forms.city') }}</th>
                                <th scope="col" class="th-input">{{ __('forms.rnokpp') }}</th>
                                <th scope="col" class="th-input">{{ __('patients.birth_certificate') }}</th>
                                <th scope="col" class="th-input">{{ __('forms.status.label') }}</th>
                            </tr>
                            </thead>

                            <tbody>
                            <tr>
                                <td class="td-input whitespace-nowrap overflow-hidden text-ellipsis align-top font-bold text-gray-900 dark:text-white"
                                    x-text="patient.birthSettlement || '-'"
                                ></td>
                                <td class="td-input whitespace-nowrap overflow-hidden text-ellipsis align-top font-bold text-gray-900 dark:text-white"
                                    x-text="patient.taxId || '-'"
                                ></td>
                                <td class="td-input whitespace-nowrap overflow-hidden text-ellipsis align-top font-bold text-gray-900 dark:text-white"
                                    x-text="patient.birthCertificate || '-'"
                                ></td>
                                <td class="td-input whitespace-nowrap align-top">
                                    <span class="badge-green">{{ __('patients.source.ehealth') }}</span>
                                </td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div x-show="$wire.invalidPersonId === patient.id"
                     x-cloak
                     class="mt-4 p-4 rounded-lg bg-red-50 dark:bg-red-900/20"
                >
                    <div class="flex items-center gap-2">
                        @icon('alert-circle', 'w-5 h-5 text-red-700 dark:text-red-400')
                        <p class="font-semibold text-red-700 dark:text-red-400">
                            {{ __('patients.age_insufficient_for_confidant_person') }}
                        </p>
                    </div>
                </div>
            </fieldset>
        </template>

        <template x-if="$wire.confidantPerson.length === 0">
            <x-nothing-found class="mx-auto" :maxW2xl="false" />
        </template>
    </div>

    {{-- Documents inside drawer --}}
    @include('livewire.person.parts.drawers.modals.documents')

    {{-- Drawer for adding documents that confirm confidant --}}
    @include('livewire.person.parts.drawers.add-documents-relationship')

    <div class="flex gap-3 mt-6">
        <button class="button-minor" type="button" @click="showConfidantPersonDrawer = false">
            {{ __('forms.cancel') }}
        </button>
        <button x-show="isEditingLegalRep && selectedPatient"
                class="button-primary"
                type="button"
                @click="saveConfidantPerson()"
        >
            {{ __('forms.save') }}
        </button>

        @if($this instanceof \App\Livewire\Person\PersonUpdate)
            <button type="button"
                    class="button-primary"
                    wire:click.prevent="createNewConfidantPersonRelationshipRequest"
            >
                {{ __('patients.add_confidant_person') }}
            </button>
        @else
            <button type="button"
                    class="button-primary"
                    @click="addConfidantPersonToForm(); showConfidantPersonDrawer = false"
            >
                {{ __('patients.add_confidant_person') }}
            </button>
        @endif
    </div>
</div>
