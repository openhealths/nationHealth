{{-- With the record type fixed and no filters to fill in, the search runs once the list comes into view --}}
<div @if ($fixedRecordType && !$withConditionFilters) x-intersect.once="$wire.search()" @endif>
    @if (!$fixedRecordType || $episodes !== [] || $withConditionFilters)
        <div class="mt-2 mb-4 flex items-center gap-1.5 pl-1 font-bold text-gray-900 dark:text-gray-100">
            @icon('search-outline', 'w-5 h-5 text-gray-800 dark:text-gray-200')
            <span class="text-base">{{ __('forms.search') }}</span>
        </div>

        <div class="mb-6 grid grid-cols-1 gap-6 md:grid-cols-2">
            @if ($withConditionFilters)
                <div class="form-group group">
                    <input
                        type="text"
                        wire:model="filterCode"
                        id="{{ $this->getId() }}-code"
                        class="input peer w-full"
                        placeholder=" "
                    />
                    <label for="{{ $this->getId() }}-code" class="label">{{ __('conditions.code') }}</label>
                </div>
            @endif

            @unless ($fixedRecordType)
                <div class="form-group group">
                    <select
                        wire:model.live="recordType"
                        id="{{ $this->getId() }}-record-type"
                        class="input-select peer w-full"
                    >
                        <option value="" selected>{{ __('forms.select') }}</option>
                        <option value="condition">{{ __('conditions.condition_or_diagnosis') }}</option>
                        <option value="observation">{{ __('conditions.evidence_observations') }}</option>
                    </select>
                    <label for="{{ $this->getId() }}-record-type" class="label">
                        {{ mb_ucfirst(__('medical-events.medical_records_type')) }}
                    </label>
                </div>
            @endunless

            @if ($episodes !== [])
                <div class="form-group group">
                    <select
                        @if ($withConditionFilters) wire:model="filterEpisodeId" @else wire:model.live="filterEpisodeId" @endif
                        id="{{ $this->getId() }}-episode"
                        class="input-select peer w-full"
                    >
                        <option value="" selected>{{ __('forms.select') }}</option>
                        @foreach ($episodes as $episode)
                            <option value="{{ data_get($episode, 'uuid') }}">
                                {{ data_get($episode, 'name') }} ({{ mb_strtolower(__('episodes.status.active')) }}) від {{ convertToAppDateFormat(data_get($episode, 'period.start')) }}
                            </option>
                        @endforeach
                    </select>
                    <label for="{{ $this->getId() }}-episode" class="label"> {{ __('episodes.label') }} </label>
                </div>
            @endif

            @if ($withConditionFilters)
                {{-- The picker keeps both bounds in one field, while the search takes them apart --}}
                <div class="form-group group" wire:ignore>
                    <div class="datepicker-wrapper">
                        <input
                            type="text"
                            id="{{ $this->getId() }}-onset-date"
                            @change="
                                const bounds = $event.target.value.split(' — ');
                                $wire.set('filterOnsetDateFrom', bounds.length === 2 ? bounds[0] : '', false);
                                $wire.set('filterOnsetDateTo', bounds.length === 2 ? bounds[1] : '', false);
                            "
                            class="daterangepicker-uk with-leading-icon input peer w-full"
                            placeholder=" "
                            autocomplete="off"
                        />
                        <label for="{{ $this->getId() }}-onset-date" class="wrapped-label">
                            {{ __('conditions.condition_start_date') }}
                        </label>
                    </div>
                </div>
            @endif
        </div>

        @if ($withConditionFilters)
            <div class="mb-6 flex flex-wrap gap-2">
                <button
                    type="button"
                    wire:click="search"
                    class="button-primary flex cursor-pointer items-center gap-2 px-5 py-2.5 text-sm shadow-sm"
                >
                    @icon('search', 'w-4 h-4')
                    <span>{{ __('forms.search') }}</span>
                </button>
                <button
                    type="button"
                    wire:click="resetFilters"
                    @click="
                        const onsetDate = document.getElementById('{{ $this->getId() }}-onset-date');
                        onsetDate.value = '';
                        onsetDate._flatpickr?.clear();
                    "
                    class="button-primary-outline-red cursor-pointer px-5 py-2.5 text-sm"
                >
                    {{ __('forms.reset_all_filters') }}
                </button>
            </div>
        @endif
    @endif

    <div class="relative">
        <div
            wire:loading.flex
            wire:target="recordType, filterEpisodeId, search"
            class="absolute inset-0 z-10 items-center justify-center bg-white/70 dark:bg-gray-800/70"
        >
            <x-forms.loading />
        </div>

        <table class="table-input w-inherit">
            <thead class="thead-input">
                <tr>
                    <th scope="col" class="th-input">
                        {{ $withConditionFilters ? __('conditions.condition_start_date') : __('forms.date') }}
                    </th>
                    @unless ($withConditionFilters)
                        <th scope="col" class="th-input">{{ __('forms.type') }}</th>
                    @endunless
                    <th scope="col" class="th-input">{{ __('medical-events.code_and_name') }}</th>
                    @if ($withConditionFilters)
                        <th scope="col" class="th-input">{{ __('episodes.label') }}</th>
                    @endif
                    <th scope="col" class="th-input text-center">{{ __('forms.action') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($records as $record)
                    <tr
                        wire:key="medical-record-{{ $record['id'] }}"
                        class="border-b border-gray-200 transition-colors hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800/40"
                    >
                        <td class="td-input text-[14px] text-gray-900 dark:text-gray-300">
                            {{ $withConditionFilters ? $record['onsetDate'] : $record['ehealthInsertedAt'] }}
                        </td>
                        @unless ($withConditionFilters)
                            <td class="td-input text-[14px] text-gray-900 dark:text-gray-300">
                                {{ $record['type'] === 'condition' ? __('conditions.condition_or_diagnosis') : __('conditions.evidence_observations') }}
                            </td>
                        @endunless
                        <td
                            class="td-input text-[14px] text-gray-900 dark:text-white"
                            x-text="
                                `{{ $record['codeCode'] }} - ${
                                    $wire.icd10Descriptions['{{ $record['codeCode'] }}'] ||
                                    $wire.$parent.dictionaries['eHealth/LOINC/observation_codes']['{{ $record['codeCode'] }}'] ||
                                    $wire.$parent.dictionaries['eHealth/ICF/classifiers']['{{ $record['codeCode'] }}'] ||
                                    $wire.$parent.dictionaries['eHealth/ICPC2/condition_codes']['{{ $record['codeCode'] }}'] ||
                                    ''
                                }`
                            "
                        ></td>
                        @if ($withConditionFilters)
                            <td class="td-input text-[14px] text-gray-900 dark:text-gray-300">
                                {{ $record['episodeName'] }}
                            </td>
                        @endif
                        <td class="td-input text-center">
                            <template x-if="! {{ $isAddedCheck }}('{{ $record['id'] }}')">
                                <button
                                    type="button"
                                    wire:click="select('{{ $record['id'] }}')"
                                    class="inline-flex cursor-pointer items-center justify-center text-sm font-medium text-gray-900 transition-colors hover:text-blue-600 dark:text-white dark:hover:text-blue-400"
                                >
                                    @icon('plus', 'w-5 h-5')
                                </button>
                            </template>
                            <template x-if="{{ $isAddedCheck }}('{{ $record['id'] }}')">
                                <span class="inline-flex items-center text-sm font-medium text-green-600 dark:text-green-400">
                                    @icon('check-circle', 'w-5 h-5')
                                    {{ __('medical-events.added') }}
                                </span>
                            </template>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if ($records === [] && ($hasSearched || !$withConditionFilters))
            <div class="py-8 text-center text-gray-500 dark:text-gray-400">{{ __('forms.nothing_found') }}</div>
        @endif
    </div>
</div>
