<x-dialog-drawer
    x-model="showMergePatientDrawer"
    onCloseClick="showMergePatientDrawer = false"
    maxWidth="4/5"
>
    <x-slot name="title">
        {{ __('patients.merge.title', ['uuid' => strtoupper($uuid)]) }}
    </x-slot>

    <div class="mt-4" x-data="{ showFilter: true }">
        <div class="mb-8 flex items-center gap-1 font-semibold text-gray-900 dark:text-white">
            @icon('search-outline', 'w-4.5 h-4.5')
            <p>{{ __('patients.merge.search_patient') }}</p>
        </div>

        @include('livewire.person.parts.search-filter', ['context' => 'merge'])

        <div class="mb-9 mt-6 flex gap-2">
            <button type="button"
                    class="flex items-center gap-2 button-primary"
                    @click.prevent="searchForMergePatient()"
            >
                @icon('search', 'w-4 h-4')
                <span>{{ __('forms.search') }}</span>
            </button>
            <button type="button"
                    class="button-primary-outline-red"
                    @click="
                        $wire.form.firstName = '';
                        $wire.form.lastName = '';
                        $wire.form.birthDate = '';
                        $wire.form.secondName = '';
                        $wire.form.taxId = '';
                        $wire.form.phoneNumber = '';
                        $wire.form.birthCertificate = '';
                        mergeSearchPatients = [];
                        showMergeResults = false;
                    "
            >
                {{ __('forms.reset_all_filters') }}
            </button>
        </div>
    </div>

    <div class="space-y-6 mt-6" x-show="showMergeResults" x-transition x-cloak>
        <template x-for="patient in mergeSearchPatients" :key="patient.id">
            <fieldset class="fieldset">
                <legend class="legend"
                        x-text="`${patient.lastName} ${patient.firstName} ${patient.secondName || ''}`"
                ></legend>

                <div class="flex flex-wrap items-center justify-between gap-4 border-b border-gray-200 dark:border-gray-700 pb-4">
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
                            <span x-text="'{{ __('forms.birth_date_abbreviated') }} ' + patient.birthDate"></span>
                        </span>

                        <span class="flex items-center gap-1.5 min-w-0" x-show="patient.phones && patient.phones[0]">
                            @icon('tabler-phone', 'w-6 h-6 text-gray-800 dark:text-white')
                            <a :href="'tel:' + patient.phones[0].number"
                               class="truncate hover:underline font-medium text-gray-900 dark:text-gray-200 text-base"
                               x-text="patient.phones[0].number"
                            ></a>
                        </span>

                        <span class="flex items-center gap-1.5" x-show="patient.gender">
                            <template x-if="patient.gender === 'male'">
                                <span class="flex items-center gap-1.5">
                                    @icon('men', 'w-6 h-6 text-gray-800 dark:text-white')
                                    <span>{{ __('patients.merge.gender_male') }}</span>
                                </span>
                            </template>
                            <template x-if="patient.gender === 'female'">
                                <span class="flex items-center gap-1.5">
                                    @icon('women', 'w-6 h-6 text-gray-800 dark:text-white')
                                    <span>{{ __('patients.merge.gender_female') }}</span>
                                </span>
                            </template>
                        </span>
                    </div>

                    <button type="button"
                            class="button-primary text-sm"
                            @click.prevent="selectMergePatient(patient)"
                    >
                        {{ __('patients.merge.merge_patients') }}
                    </button>
                </div>

                <div class="flow-root mt-4">
                    <div class="max-w-screen-xl">
                        <table class="table-input w-full table-auto">
                            <thead class="thead-input">
                            <tr>
                                <th scope="col" class="th-input">{{ strtoupper(__('forms.city')) }}</th>
                                <th scope="col" class="th-input">{{ __('patients.merge.tax_id_label') }}</th>
                                <th scope="col" class="th-input">{{ __('patients.merge.birth_certificate_label') }}</th>
                                <th scope="col" class="th-input">{{ strtoupper(__('forms.status.label')) }}</th>
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
                                    <span class="badge-green">ЕСОЗ</span>
                                </td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </fieldset>
        </template>

        <template x-if="mergeSearchPatients.length === 0">
            <x-nothing-found class="mx-auto" maxWidth="" />
        </template>
    </div>

    <div class="flex gap-3 mt-6">
        <button class="button-minor" type="button" @click="showMergePatientDrawer = false">
            {{ __('forms.back') }}
        </button>
    </div>
</x-dialog-drawer>
