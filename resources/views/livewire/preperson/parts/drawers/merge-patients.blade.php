@use('App\Enums\Person\Gender')

<div
    x-data="{
        mergeSearchPatients: $wire.entangle('mergeSearchPatients'),
        showMergeResults: false,
    }"
>
    <x-dialog-drawer x-model="showMergePatientDrawer" onCloseClick="showMergePatientDrawer = false" maxWidth="4/5">
        <x-slot name="title">{{ __('preperson.merge.title', ['uuid' => $prepersonUuid]) }}</x-slot>

        <div class="mt-4" x-data="{ showFilter: true }">
            <div class="mb-8 flex items-center gap-1 font-semibold text-gray-900 dark:text-white">
                @icon('search-outline', 'w-4.5 h-4.5')
                <p>{{ __('patients.patient_search') }}</p>
            </div>

            @include('livewire.person.parts.search-filter', ['context' => 'merge'])

            <div class="mt-6 mb-9 flex gap-2">
                <button
                    type="button"
                    class="button-primary flex items-center gap-2"
                    @click.prevent="$wire.searchPerson().then(() => (showMergeResults = true))"
                >
                    @icon('search', 'w-4 h-4')
                    <span>{{ __('forms.search') }}</span>
                </button>
                <button
                    type="button"
                    class="button-primary-outline-red"
                    @click.prevent="
                        $wire.resetFilters();
                        showMergeResults = false;
                    "
                >
                    {{ __('forms.reset_all_filters') }}
                </button>
            </div>
        </div>

        <div class="mt-6 space-y-6" wire:ignore x-show="showMergeResults" x-transition x-cloak>
            <template x-for="patient in mergeSearchPatients" :key="patient.id">
                <fieldset class="fieldset">
                    <legend class="legend">
                        <template x-for="(patientName, index) in patient.names" :key="index">
                            <span
                                class="block"
                                x-text="
                                    `${patientName.lastName ?? ''} ${patientName.firstName} ${patientName.secondName ?? ''}`.trim()
                                "
                            ></span>
                        </template>
                    </legend>

                    <div class="flex flex-wrap items-center justify-between gap-4 border-b border-gray-200 pb-4 dark:border-gray-700">
                        <div class="mt-2 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm text-gray-500">
                            <span class="flex items-center gap-1.5" x-show="patient.birthDate">
                                @icon('calendar-outline', 'w-6 h-6 text-gray-800 dark:text-white')
                                <span x-text="'{{ __('forms.birth_date_abbreviated') }} ' + patient.birthDate"></span>
                            </span>

                            <span class="flex min-w-0 items-center gap-1.5" x-show="patient.phones?.[0]?.number">
                                @icon('tabler-phone', 'w-6 h-6 text-gray-800 dark:text-white')
                                <a
                                    :href="'tel:' + patient.phones?.[0]?.number"
                                    class="truncate text-base font-medium text-gray-900 hover:underline dark:text-gray-200"
                                    x-text="patient.phones?.[0]?.number"
                                ></a>
                            </span>

                            <span class="flex items-center gap-1.5" x-show="patient.gender">
                                @foreach (Gender::cases() as $gender)
                                    <template x-if="patient.gender?.toUpperCase() === '{{ $gender->value }}'">
                                        <span class="flex items-center gap-1.5">
                                            @icon($gender->icon(), 'w-6 h-6 text-gray-800 dark:text-white')
                                            <span>{{ $gender->label() }}</span>
                                        </span>
                                    </template>
                                @endforeach
                            </span>
                        </div>

                        <button
                            type="button"
                            class="button-primary text-sm"
                            @click.prevent="
                                $wire.selectPatient(patient.id).then(() => {
                                    selectedMergePatient = patient;
                                    showMergePatientDrawer = false;
                                    showMergeAuthDrawer = true;
                                })
                            "
                        >
                            {{ __('preperson.merge.merge_patients') }}
                        </button>
                    </div>

                    <div class="mt-4 flow-root">
                        <div class="max-w-7xl">
                            <table class="table-input w-full table-auto">
                                <thead class="thead-input">
                                    <tr>
                                        <th scope="col" class="th-input">{{ strtoupper(__('forms.city')) }}</th>
                                        <th scope="col" class="th-input">{{ __('preperson.merge.tax_id') }}</th>
                                        <th scope="col" class="th-input">
                                            {{ strtoupper(__('forms.document_type')) }}
                                        </th>
                                        <th scope="col" class="th-input">
                                            {{ strtoupper(__('forms.document_number')) }}
                                        </th>
                                        <th scope="col" class="th-input">{{ strtoupper(__('forms.status.label')) }}</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <tr>
                                        <td
                                            class="td-input overflow-hidden align-top font-bold text-ellipsis whitespace-nowrap text-gray-900 dark:text-white"
                                            x-text="patient.birthSettlement || '-'"
                                        ></td>
                                        <td
                                            class="td-input overflow-hidden align-top font-bold text-ellipsis whitespace-nowrap text-gray-900 dark:text-white"
                                            x-text="patient.taxId || '-'"
                                        ></td>
                                        <td
                                            class="td-input overflow-hidden align-top font-bold text-ellipsis whitespace-nowrap text-gray-900 dark:text-white"
                                            x-text="
                                                patient.documents
                                                    ?.map(
                                                        (patientDocument) =>
                                                            $wire.dictionaries.DOCUMENT_TYPE[patientDocument.type] ??
                                                            patientDocument.type,
                                                    )
                                                    .join(', ') || '-'
                                            "
                                        ></td>
                                        <td
                                            class="td-input overflow-hidden align-top font-bold text-ellipsis whitespace-nowrap text-gray-900 dark:text-white"
                                            x-text="
                                                patient.documents
                                                    ?.map((patientDocument) => patientDocument.number)
                                                    .join(', ') || '-'
                                            "
                                        ></td>
                                        <td class="td-input align-top whitespace-nowrap">
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

        <div class="mt-6 flex gap-3">
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
            <livewire:components.x-message :key="time()" />
        </div>
    </template>
</div>
