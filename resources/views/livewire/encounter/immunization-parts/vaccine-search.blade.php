<fieldset class="fieldset">
    <legend class="legend">{{ __('immunizations.vaccine') }}</legend>

    {{-- Vaccine search --}}
    <div x-show="! modalImmunization.vaccineCode" x-cloak>
        <div class="mb-6 flex items-center gap-2 font-semibold text-gray-900 dark:text-gray-100">
            @icon('search-outline', 'w-5 h-5')
            <span>{{ __('immunizations.vaccine_search') }}</span>
        </div>

        <div class="form-row-3">
            <div class="form-group group">
                <label for="vaccineSearchName" class="label-modal"> {{ __('immunizations.vaccine_name') }} </label>

                <input
                    x-model="vaccineSearch.name"
                    @keydown.enter.prevent="searchVaccines()"
                    type="text"
                    id="vaccineSearchName"
                    class="input-modal"
                    autocomplete="off"
                />
            </div>

            <div class="form-group group">
                <label for="vaccineSearchCode" class="label-modal"> {{ __('immunizations.vaccine_code') }} </label>

                <input
                    x-model="vaccineSearch.code"
                    @keydown.enter.prevent="searchVaccines()"
                    type="text"
                    id="vaccineSearchCode"
                    class="input-modal"
                    autocomplete="off"
                />
            </div>

            <div class="form-group group">
                <label for="vaccineSearchDisease" class="label-modal"> {{ __('immunizations.disease') }} </label>

                <input
                    x-model="vaccineSearch.disease"
                    @keydown.enter.prevent="searchVaccines()"
                    type="text"
                    id="vaccineSearchDisease"
                    class="input-modal"
                    autocomplete="off"
                />
            </div>
        </div>

        <div class="mt-6 flex flex-wrap gap-3">
            <button type="button" @click.prevent="searchVaccines()" class="button-primary flex items-center gap-2">
                @icon('search', 'w-4 h-4')
                <span>{{ __('forms.search') }}</span>
            </button>

            <button type="button" @click.prevent="resetVaccineSearch()" class="button-primary-outline-red">
                {{ __('patients.reset_filters') }}
            </button>
        </div>

        {{-- Результати пошуку --}}
        <div x-show="vaccineSearchPerformed" x-cloak class="mt-8 overflow-x-auto">
            <table class="table-input w-inherit">
                <thead class="thead-input">
                    <tr>
                        <th scope="col" class="th-input">{{ __('forms.name') }}</th>

                        <th scope="col" class="th-input">{{ __('forms.code') }}</th>

                        <th scope="col" class="th-input">{{ __('immunizations.disease') }}</th>

                        <th scope="col" class="th-input text-center">{{ __('forms.action') }}</th>
                    </tr>
                </thead>

                <tbody>
                    <template x-for="vaccine in vaccineSearchResults" :key="vaccine.code">
                        <tr class="border-b border-gray-200 transition-colors hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800/40">
                            <td class="td-input text-sm text-gray-900 dark:text-white" x-text="vaccine.name"></td>

                            <td class="td-input text-sm text-gray-900 dark:text-white" x-text="vaccine.code"></td>

                            <td
                                class="td-input text-sm text-gray-900 dark:text-white"
                                x-text="
                                    vaccine.targetDiseases.length > 0
                                        ? vaccine.targetDiseases.map((targetDisease) => targetDisease.name).join(', ')
                                        : '-'
                                "
                            ></td>

                            <td class="td-input text-center">
                                <button
                                    type="button"
                                    @click.prevent="selectVaccine(vaccine.code)"
                                    class="inline-flex cursor-pointer items-center justify-center text-gray-900 transition-colors hover:text-blue-600 dark:text-white dark:hover:text-blue-400"
                                    title="{{ __('immunizations.select_vaccine') }}"
                                >
                                    @icon('plus-circle', 'w-6 h-6')

                                    <span class="sr-only"> {{ __('immunizations.select_vaccine') }} </span>
                                </button>
                            </td>
                        </tr>
                    </template>

                    <template x-if="vaccineSearchResults.length === 0">
                        <tr>
                            <td colspan="4" class="td-input py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                {{ __('forms.nothing_found') }}
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>

    {{-- Уже вибрана вакцина --}}
    <div
        x-show="modalImmunization.vaccineCode"
        x-cloak
        class="grid grid-cols-1 items-end gap-6 lg:grid-cols-[minmax(0,1fr)_auto]"
    >
        <div>
            <div class="label-modal">{{ __('immunizations.vaccine_code_and_name') }} *</div>

            <div
                class="min-h-11 border-b border-gray-300 px-0 py-3 text-base text-gray-900 dark:border-gray-600 dark:text-white"
                x-text="
                    `${modalImmunization.vaccineCode} - ${vaccineCodesDictionary[modalImmunization.vaccineCode] ?? ''}`
                "
            ></div>
        </div>

        @unless ($isReadonly)
            <button
                type="button"
                @click.prevent="chooseAnotherVaccine()"
                class="mb-3 cursor-pointer text-left font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
            >
                {{ __('immunizations.choose_another_vaccine') }}
            </button>
        @endunless
    </div>
</fieldset>
