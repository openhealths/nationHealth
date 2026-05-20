{{-- References Selection Drawer Teleport Root --}}
<template x-teleport="body">
    <div x-show="showReferencesDrawer"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         x-cloak
         class="fixed inset-0"
         style="z-index: 44;"
         role="dialog"
         aria-modal="true"
    >
        <div class="absolute inset-0 bg-gray-900/50"
             aria-hidden="true"
             @click="cancelSelection()"
        ></div>

        <div id="references-selection-drawer-right"
             x-show="showReferencesDrawer"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="translate-x-full"
             x-transition:enter-end="translate-x-0"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="translate-x-0"
             x-transition:leave-end="translate-x-full"
             class="absolute top-0 right-0 h-screen pt-20 p-6 bg-white dark:bg-gray-800 shadow-2xl flex flex-col justify-between border-l border-gray-100 dark:border-gray-700"
             style="width: calc(80% - 30px);"
             tabindex="-1"
        >
            <div class="flex-1 flex flex-col min-h-0">
                <div class="flex items-center pb-5 mb-6">
                    <h2 class="text-2xl font-bold text-gray-900 dark:text-white">
                        {{ __('care-plan.search_medical_records') }}
                    </h2>
                </div>

                <div class="mb-6">
                    <div class="flex items-center gap-2 pb-2.5">
                        @icon('search-outline', 'w-5 h-5 text-gray-400')
                        <span class="text-sm font-medium text-gray-900 dark:text-white">{{ __('patients.search') }}</span>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                    <div class="form-group group">
                        <select x-model="selectedType"
                                id="drawerSelectedType"
                                class="input-select peer w-full"
                        >
                            <option value="ALL">{{ __('forms.all') }}</option>
                            <option value="condition">{{ __('patients.conditions') }}</option>
                            <option value="observation">{{ __('patients.medical_observation') }}</option>
                            <option value="diagnostic-report">{{ __('patients.diagnostic_reports') }}</option>
                        </select>
                        <label for="drawerSelectedType" class="label">
                            {{ __('forms.type') }}
                        </label>
                    </div>

                    <div class="form-group group">
                        <select x-model="selectedEpisode"
                                id="drawerSelectedEpisode"
                                class="input-select peer w-full"
                        >
                            <option value="ALL">{{ __('forms.all') }}</option>
                            <option value="ep-1">{{ __('patients.mock.episode_1') }}</option>
                            <option value="ep-2">{{ __('patients.mock.episode_2') }}</option>
                        </select>
                        <label for="drawerSelectedEpisode" class="label">
                            {{ __('care-plan.episode') }}
                        </label>
                    </div>
                </div>

                <div class="flex-1 overflow-y-auto min-h-0 mb-6 pr-1">
                    <table class="table-input w-inherit">
                        <thead class="thead-input">
                            <tr>
                                <th scope="col" class="th-input w-[15%] uppercase">{{ mb_strtoupper(__('forms.date')) }}</th>
                                <th scope="col" class="th-input w-[20%] uppercase">{{ mb_strtoupper(__('forms.type')) }}</th>
                                <th scope="col" class="th-input w-[55%] uppercase">{{ mb_strtoupper(__('forms.name')) }}</th>
                                <th scope="col" class="th-input text-center w-[10%] uppercase">{{ mb_strtoupper(__('forms.actions')) }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="record in filteredRecords()" :key="record.id">
                                <tr class="border-b border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors">
                                    <td class="td-input text-[14px] text-gray-900 dark:text-gray-300" x-text="record.date"></td>
                                    <td class="td-input text-[14px] text-gray-900 dark:text-gray-300" x-text="record.typeLabel"></td>
                                    <td class="td-input text-[14px] text-gray-900 dark:text-white" x-text="record.name"></td>
                                    <td class="td-input text-center">
                                        <button type="button"
                                                @click="addReference(record)"
                                                class="inline-flex items-center justify-center text-gray-900 hover:text-blue-600 dark:text-white dark:hover:text-blue-400 transition-colors p-1"
                                        >
                                            @icon('plus-circle', 'w-6 h-6')
                                        </button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                    <div x-show="filteredRecords().length === 0" class="text-center py-8 text-gray-500 dark:text-gray-400" x-cloak>
                        {{ __('forms.nothing_found') }}
                    </div>
                </div>
            </div>

            <div class="pt-6 border-t border-gray-100 dark:border-gray-700 flex justify-start mt-auto">
                <button type="button"
                        class="button-minor"
                        @click="cancelSelection()"
                >
                    {{ __('forms.cancel') }}
                </button>
            </div>
        </div>
    </div>
</template>
