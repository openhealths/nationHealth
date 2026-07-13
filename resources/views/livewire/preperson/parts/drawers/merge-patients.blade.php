@use('App\Enums\Person\Gender')

<div x-data="{
    mergeSearchPatients: $wire.entangle('mergeSearchPatients'),
    showMergeResults: false
}">
    <x-dialog-drawer
        x-model="showMergePatientDrawer"
        onCloseClick="showMergePatientDrawer = false"
        maxWidth="4/5"
    >
        <x-slot name="title">
            {{ __('preperson.merge.title', ['uuid' => $prepersonUuid]) }}
        </x-slot>

        <div class="mt-4" x-data="{ showFilter: true }">
            <div class="mb-8 flex items-center gap-1 font-semibold text-gray-900 dark:text-white">
                @icon('search-outline', 'w-4.5 h-4.5')
                <p>{{ __('patients.patient_search') }}</p>
            </div>

            @include('livewire.person.parts.search-filter', ['context' => 'merge'])

            <div class="mb-9 mt-6 flex gap-2">
                <button
                    type="button"
                    class="flex items-center gap-2 button-primary"
                    @click.prevent="$wire.searchPerson().then(() => showMergeResults = true)"
                >
                    @icon('search', 'w-4 h-4')
                    <span>{{ __('forms.search') }}</span>
                </button>
                <button
                    type="button"
                    class="button-primary-outline-red"
                    @click.prevent="$wire.resetFilters(); showMergeResults = false"
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

                    <div
                        class="flex flex-wrap items-center justify-between gap-4 border-b border-gray-200 dark:border-gray-700 pb-4">
                        <div class="flex items-center flex-wrap gap-x-6 gap-y-2 text-sm text-gray-500 mt-2">
                        <span class="flex items-center gap-1.5" x-show="patient.birthDate">
                            @icon('calendar-outline', 'w-6 h-6 text-gray-800 dark:text-white')
                            <span x-text="'{{ __('forms.birth_date_abbreviated') }} ' + patient.birthDate"></span>
                        </span>

                            <span class="flex items-center gap-1.5 min-w-0" x-show="patient.phones?.[0]?.number">
                            @icon('tabler-phone', 'w-6 h-6 text-gray-800 dark:text-white')
                            <a :href="'tel:' + patient.phones?.[0]?.number"
                               class="truncate hover:underline font-medium text-gray-900 dark:text-gray-200 text-base"
                               x-text="patient.phones?.[0]?.number"
                            ></a>
                        </span>

                            <span class="flex items-center gap-1.5" x-show="patient.gender">
                            @foreach(Gender::cases() as $gender)
                                    <template x-if="patient.gender?.toUpperCase() === '{{ $gender->value }}'">
                                    <span class="flex items-center gap-1.5">
                                        @icon($gender->icon(), 'w-6 h-6 text-gray-800 dark:text-white')
                                        <span>{{ $gender->label() }}</span>
                                    </span>
                                </template>
                                @endforeach
                        </span>
                        </div>

                        <button type="button"
                                class="button-primary text-sm"
                                @click.prevent="$wire.selectPatient(patient.id).then(() => {
                                    selectedMergePatient = patient;
                                    showMergePatientDrawer = false;
                                    showMergeAuthDrawer = true;
                                })"
                        >
                            {{ __('preperson.merge.merge_patients') }}
                        </button>
                    </div>

                    <div class="flow-root mt-4">
                        <div class="max-w-7xl">
                            <table class="table-input w-full table-auto">
                                <thead class="thead-input">
                                <tr>
                                    <th scope="col" class="th-input">{{ strtoupper(__('forms.city')) }}</th>
                                    <th scope="col" class="th-input">{{ __('preperson.merge.tax_id') }}</th>
                                    <th scope="col" class="th-input">{{ __('preperson.merge.birth_certificate') }}</th>
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

    @include('livewire.preperson.parts.drawers.merge-auth-methods')
    @include('livewire.preperson.parts.drawers.merge-confirmation')
    @include('livewire.preperson.parts.drawers.merge-sms-verification')
    @include('livewire.preperson.parts.drawers.merge-documents-upload')
    @include('livewire.preperson.parts.drawers.merge-final-consent')
    @include('livewire.preperson.parts.drawers.merge-signature')
    @include('livewire.preperson.modals.consent-form-modal')

    <template x-teleport="body">
        <div>
            @include('livewire.components.x-message')
        </div>
    </template>
</div>
