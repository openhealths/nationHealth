@use('App\Enums\MedicalEvents\RecordType')

<div
    x-data="{
        recordName(code) {
            const dictionaries = $wire.$parent.dictionaries;
            const dictionaryName =
                $wire.icd10Descriptions[code] ||
                dictionaries['eHealth/LOINC/observation_codes']?.[code] ||
                dictionaries['eHealth/ICF/classifiers']?.[code] ||
                dictionaries['eHealth/ICPC2/condition_codes']?.[code];

            if (dictionaryName) {
                return `${code} - ${dictionaryName}`;
            }

            const service = Object.values(dictionaries['custom/services'] ?? {}).find(
                (serviceOption) => serviceOption.id === code,
            );

            return service ? `${service.code} / ${service.name}` : code || '—';
        },
    }"
>
    <div class="mt-2 mb-4 flex items-center gap-1.5 pl-1 font-bold text-gray-900 dark:text-gray-100">
        @icon('search-outline', 'w-5 h-5 text-gray-800 dark:text-gray-200')
        <span class="text-base">{{ __('forms.search') }}</span>
    </div>

    <div class="mb-6 grid grid-cols-1 gap-6 md:grid-cols-2">
        <div class="form-group group">
            <select wire:model.live="recordType" id="{{ $this->getId() }}-record-type" class="input-select peer w-full">
                <option value="" selected>{{ __('forms.select') }}</option>
                @foreach ($recordTypes as $recordTypeValue)
                    <option value="{{ $recordTypeValue }}">{{ RecordType::from($recordTypeValue)->label() }}</option>
                @endforeach
            </select>
            <label for="{{ $this->getId() }}-record-type" class="label"> {{ __('forms.type') }} </label>
        </div>

        @if ($withEpisodeFilter)
            <div class="form-group group">
                <select
                    wire:model.live="filterEpisodeId"
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
    </div>

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
                    <th scope="col" class="th-input">{{ __('forms.date') }}</th>
                    <th scope="col" class="th-input">{{ __('medical-events.code_and_name') }}</th>
                    <th scope="col" class="th-input text-center">{{ __('forms.action') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($records as $record)
                    <tr
                        wire:key="supporting-info-{{ $record['type'] }}-{{ $record['uuid'] }}"
                        class="border-b border-gray-200 transition-colors hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800/40"
                    >
                        <td class="td-input text-[14px] text-gray-900 dark:text-gray-300">
                            {{ $record['ehealthInsertedAt'] ?: '—' }}
                        </td>
                        <td
                            class="td-input text-[14px] text-gray-900 dark:text-white"
                            x-text="recordName($wire.records[{{ $loop->index }}]?.code)"
                        ></td>
                        <td class="td-input text-center">
                            <template x-if="! {{ $isAddedCheck }}('{{ $record['uuid'] }}')">
                                <button
                                    type="button"
                                    wire:click="select('{{ $record['uuid'] }}')"
                                    class="inline-flex cursor-pointer items-center justify-center text-sm font-medium text-gray-900 transition-colors hover:text-blue-600 dark:text-white dark:hover:text-blue-400"
                                >
                                    @icon('plus', 'w-5 h-5')
                                </button>
                            </template>
                            <template x-if="{{ $isAddedCheck }}('{{ $record['uuid'] }}')">
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

        @if ($records === [])
            <div class="py-8 text-center text-gray-500 dark:text-gray-400">{{ __('forms.nothing_found') }}</div>
        @endif
    </div>
</div>
