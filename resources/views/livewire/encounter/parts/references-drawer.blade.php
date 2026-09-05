{{-- References Selection Drawer Teleport Root --}}
<template x-teleport="body">
    <div
        x-show="showReferencesDrawer"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        x-cloak
        class="fixed inset-0"
        style="z-index: 44"
        role="dialog"
        aria-modal="true"
    >
        <div class="absolute inset-0 bg-gray-900/50" aria-hidden="true" @click="cancelSelection()"></div>

        <div
            id="references-selection-drawer-right"
            x-show="showReferencesDrawer"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="translate-x-full"
            class="absolute top-0 right-0 flex h-screen flex-col justify-between border-l border-gray-100 bg-white p-6 pt-20 shadow-2xl dark:border-gray-700 dark:bg-gray-800"
            style="width: calc(80% - 30px)"
            tabindex="-1"
        >
            <div class="flex min-h-0 flex-1 flex-col">
                <div class="mb-6 flex items-center pb-5">
                    <h2 class="text-2xl font-bold text-gray-900 dark:text-white">
                        {{ __('care-plan.search_medical_records') }}
                    </h2>
                </div>

                <div class="form-row-3 mb-6">
                    <div class="form-group group">
                        <div class="relative">
                            <div class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3">
                                @icon('search-outline', 'w-5 h-5 text-gray-400')
                            </div>
                            <input
                                type="text"
                                x-model="searchQuery"
                                class="input with-leading-icon peer w-full"
                                placeholder=" "
                                id="drawerSearchQuery"
                            />
                            <label for="drawerSearchQuery" class="wrapped-label"> {{ __('forms.search') }} </label>
                        </div>
                    </div>

                    <div class="form-group group">
                        <select
                            x-model="selectedType"
                            id="drawerSelectedType"
                            class="input-select peer w-full"
                            @change="loadMedicalRecords()"
                        >
                            <option value="" selected>{{ __('forms.select') }} {{ __('forms.type') }}</option>
                            <option value="condition">{{ __('conditions.condition_or_diagnosis') }}</option>
                            <option value="observation">{{ __('observations.medical_label') }}</option>
                            <option value="diagnosticReport">{{ __('diagnostic-reports.label') }}</option>
                        </select>
                        <label for="drawerSelectedType" class="label"> {{ __('forms.type') }} </label>
                    </div>
                </div>

                <div class="mb-6 min-h-0 flex-1 overflow-y-auto pr-1">
                    <div
                        x-show="medicalRecordsLoading"
                        class="py-8 text-center text-gray-500 dark:text-gray-400"
                        x-cloak
                    >
                        {{ __('general.loading') }}
                    </div>

                    <div x-show="! medicalRecordsLoading && hasSearchedMedicalRecords" x-cloak>
                        <table class="table-input w-inherit">
                            <thead class="thead-input">
                                <tr>
                                    <th scope="col" class="th-input w-[15%] uppercase">{{ __('forms.date') }}</th>
                                    <th scope="col" class="th-input w-[20%] uppercase">{{ __('forms.type') }}</th>
                                    <th scope="col" class="th-input w-[55%] uppercase">{{ __('forms.name') }}</th>
                                    <th scope="col" class="th-input w-[10%] text-center uppercase">
                                        {{ __('forms.actions') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="record in filteredRecords()" :key="record.type + '-' + record.uuid">
                                    <tr class="border-b border-gray-200 transition-colors hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800/40">
                                        <td
                                            class="td-input text-[14px] text-gray-900 dark:text-gray-300"
                                            x-text="record.date || '—'"
                                        ></td>
                                        <td
                                            class="td-input text-[14px] text-gray-900 dark:text-gray-300"
                                            x-text="record.typeLabel || record.type"
                                        ></td>
                                        <td
                                            class="td-input text-[14px] text-gray-900 dark:text-white"
                                            x-text="
                                                (() => {
                                                    const dictName =
                                                        $wire.dictionaries['eHealth/LOINC/observation_codes'][
                                                            record.code
                                                        ] ||
                                                        $wire.dictionaries['eHealth/ICF/classifiers'][record.code] ||
                                                        $wire.dictionaries['eHealth/ICPC2/condition_codes'][
                                                            record.code
                                                        ];

                                                    if (dictName) {
                                                        return `${record.code} - ${dictName}`;
                                                    }

                                                    const service = Object.values(
                                                        $wire.dictionaries['custom/services'],
                                                    ).find((serviceOption) => serviceOption.id === record.code);
                                                    return service
                                                        ? `${service.code} / ${service.name}`
                                                        : record.name || record.code || '—';
                                                })()
                                            "
                                        ></td>
                                        <td class="td-input text-center">
                                            <button
                                                type="button"
                                                @click="addReference(record)"
                                                class="inline-flex items-center justify-center p-1 text-gray-900 transition-colors hover:text-blue-600 dark:text-white dark:hover:text-blue-400"
                                            >
                                                @icon('plus-circle', 'w-6 h-6')
                                            </button>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>

                        <div
                            x-show="hasSearchedMedicalRecords && filteredRecords().length === 0"
                            class="py-8 text-center text-gray-500 dark:text-gray-400"
                            x-cloak
                        >
                            {{ __('forms.nothing_found') }}
                        </div>
                    </div>
                </div>

                <div class="mt-auto flex justify-start border-t border-gray-100 pt-6 dark:border-gray-700">
                    <button type="button" class="button-minor" @click="cancelSelection()">
                        {{ __('forms.cancel') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
