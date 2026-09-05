<fieldset class="fieldset">
    <legend class="legend">{{ __('immunizations.reaction_on') }}</legend>

    <div>
        <template x-if="! modalObservation.reactionOn">
            @unless ($isReadonly)
                <button type="button" class="item-add my-5" @click.prevent="openReactionDrawer()">
                    {{ __('immunizations.add') }}
                </button>
            @endunless
        </template>

        <template x-if="modalObservation.reactionOn">
            <div class="overflow-x-auto">
                <table class="table-input w-inherit">
                    <thead class="thead-input">
                        <tr>
                            <th scope="col" class="th-input w-[15%] uppercase">{{ __('forms.date') }}</th>
                            <th scope="col" class="th-input w-[20%] uppercase">{{ __('forms.type') }}</th>
                            <th scope="col" class="th-input w-[55%] uppercase">{{ __('forms.name') }}</th>
                            <th scope="col" class="th-input w-[10%] text-center uppercase">{{ __('forms.action') }}</th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <td
                                class="td-input text-[14px] text-gray-900 dark:text-gray-300"
                                x-text="selectedReactionImmunization()?.date || '—'"
                            ></td>

                            <td class="td-input text-[14px] text-gray-900 dark:text-gray-300">
                                {{ __('immunizations.label') }}
                            </td>

                            <td
                                class="td-input text-[14px] text-gray-900 dark:text-white"
                                x-text="reactionImmunizationName(selectedReactionImmunization())"
                            ></td>

                            <td class="td-input text-center">
                                @unless ($isReadonly)
                                    <button
                                        type="button"
                                        @click.prevent="removeReactionImmunization()"
                                        class="inline-flex items-center justify-center p-1 text-gray-400 transition-colors hover:text-red-500 dark:text-gray-500 dark:hover:text-red-500"
                                    >
                                        @icon('delete', 'w-5 h-5')
                                    </button>
                                @endunless
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
    </div>
</fieldset>

<x-dialog-drawer
    x-model="showReactionDrawer"
    maxWidth="4/5"
    backdropClickThrough="true"
    stopClickPropagation="true"
    zIndex="70"
    panelZIndex="71"
>
    <x-slot name="title">{{ __('care-plan.search_medical_records') }}</x-slot>

    <div class="mt-2 mb-4 flex items-center gap-1.5 pl-1 font-bold text-gray-900 dark:text-gray-100">
        @icon('search-outline', 'w-5 h-5 text-gray-800 dark:text-gray-200')
        <span class="text-base">{{ __('care-plan.search') }}</span>
    </div>

    <div class="mb-8 grid grid-cols-1 gap-6 md:grid-cols-2">
        <div class="form-group group">
            <input
                type="text"
                id="reactionMedicalRecordType"
                class="input peer w-full"
                value="{{ __('immunizations.label') }}"
                disabled
                placeholder=" "
            />

            <label for="reactionMedicalRecordType" class="label">
                {{ mb_ucfirst(__('medical-events.medical_records_type')) }}
            </label>
        </div>

        <div class="form-group group">
            <select
                x-model="reactionEpisodeId"
                id="reactionEpisodeId"
                class="input-select peer w-full"
                @change="loadReactionImmunizations()"
            >
                <option value="">{{ __('forms.select') }}</option>

                @foreach ($this->episodes as $episode)
                    <option value="{{ data_get($episode, 'uuid') }}">
                        {{ data_get($episode, 'name') }} ({{ mb_strtolower(__('episodes.status.active')) }}) від {{ convertToAppDateFormat(data_get($episode, 'period.start')) }}
                    </option>
                @endforeach
            </select>

            <label for="reactionEpisodeId" class="label"> {{ __('episodes.label') }} </label>
        </div>
    </div>

    <div class="relative">
        <div
            x-show="reactionLoading"
            x-cloak
            class="absolute inset-0 z-10 flex items-center justify-center bg-white/70 dark:bg-gray-800/70"
        >
            <x-forms.loading />
        </div>

        <table class="table-input w-inherit">
            <thead class="thead-input">
                <tr>
                    <th scope="col" class="th-input w-[15%] uppercase">{{ __('forms.date') }}</th>
                    <th scope="col" class="th-input w-[20%] uppercase">{{ __('forms.type') }}</th>
                    <th scope="col" class="th-input w-[55%] uppercase">{{ __('forms.name') }}</th>
                    <th scope="col" class="th-input w-[10%] text-center uppercase">{{ __('forms.action') }}</th>
                </tr>
            </thead>

            <tbody>
                <template x-for="immunization in reactionCandidates()" :key="immunization.uuid">
                    <tr class="border-b border-gray-200 transition-colors hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800/40">
                        <td
                            class="td-input text-[14px] text-gray-900 dark:text-gray-300"
                            x-text="immunization.date || '—'"
                        ></td>

                        <td class="td-input text-[14px] text-gray-900 dark:text-gray-300">
                            {{ __('immunizations.label') }}
                        </td>

                        <td
                            class="td-input text-[14px] text-gray-900 dark:text-white"
                            x-text="reactionImmunizationName(immunization)"
                        ></td>

                        <td class="td-input text-center">
                            <button
                                type="button"
                                @click.prevent="selectReactionImmunization(immunization)"
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
            x-show="reactionHasSearched && ! reactionLoading && reactionCandidates().length === 0"
            x-cloak
            class="py-8 text-center text-gray-500 dark:text-gray-400"
        >
            {{ __('forms.nothing_found') }}
        </div>
    </div>

    <div class="mt-8 flex justify-start">
        <button type="button" class="button-minor" @click="showReactionDrawer = false">{{ __('forms.cancel') }}</button>
    </div>
</x-dialog-drawer>
