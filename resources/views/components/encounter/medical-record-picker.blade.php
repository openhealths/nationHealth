@props ([
    'records', 'selectionEvent', 'isAddedCheck',
    'recordTypes' => [], 'episodes' => [], 'withEpisodeFilter' => false,
    'showTypeColumn' => false, 'showQuery' => true, 'selectionIcon' => 'plus',
    'searchAction' => '', 'recordsModel' => '', 'loadMoreAction' => '', 'hasMore' => false,
    'loadMoreEpisodesAction' => '', 'episodesHasMore' => false,
])
@php
    $typeOptions = $recordTypes ?: collect($records)->pluck('typeLabel', 'type')->all();
@endphp

<div
    {{ $attributes }}
    wire:key="medical-record-picker-{{ $selectionEvent }}-{{ $recordsModel !== '' ? 'remote' : md5(json_encode($records)) }}"
    x-id="['local-record-query', 'local-record-type', 'local-record-episode']"
    x-data="{
        query: '', recordType: '', episodeId: '',
        records: @js(collect($records)->values()->all()),
        matches(record) {
            const types = Object.keys(@js($recordTypes));
            return (!types.length || types.includes(record.type))
                && (!this.recordType || record.type === this.recordType)
                && (!this.episodeId || record.episode_uuid === this.episodeId)
                && (@js($searchAction !== '') || !this.query || (record.label || '').toLocaleLowerCase('uk').includes(this.query.toLocaleLowerCase('uk')));
        },
        get currentRecords() {
            const records = @if ($recordsModel !== '') Object.values($wire[@js($recordsModel)]) @else this.records @endif;
            const types = Object.keys(@js($recordTypes));
            return types.length ? records.filter(record => types.includes(record.type)) : records;
        },
        get hasMatches() { return this.currentRecords.some(record => this.matches(record)); },
        search() {
            @if ($searchAction !== '')
                $wire[@js($searchAction)](this.query, this.recordType, this.episodeId);
            @endif
        },
    }"
>
    @if ($withEpisodeFilter)
        <div
            class="mt-2 mb-4 flex items-center gap-1.5 pl-1 font-bold text-gray-900 dark:text-gray-100"
        >
            @icon ('search-outline', 'w-5 h-5 text-gray-800 dark:text-gray-200')
            <span
                class="text-base"
                >{{ __('encounters.referral_layout.search') }}</span
            >
        </div>
    @endif
    <div class="form-row-2 mb-6">
        @if ($showQuery)
            <div class="form-group">
                <input
                    type="search"
                    x-model="query"
                    @input.debounce.300ms="search()"
                    maxlength="100"
                    class="input peer"
                    placeholder=" "
                    :id="$id('local-record-query')"
                />
                <label
                    :for="$id('local-record-query')"
                    class="label border-0!"
                    >{{ __('forms.search') }}</label
                >
            </div>
        @endif
        <div class="form-group">
            <select
                x-model="recordType"
                @change="search()"
                class="input-select peer"
                :id="$id('local-record-type')"
                data-record-type
            >
                <option value="">
                    {{ $withEpisodeFilter ? __('referrals.registry.all') : __('forms.select') }}
                </option>
                @foreach ($typeOptions as $type => $label)
                    <option value="{{ $type }}">{{ $label }}</option>
                @endforeach
            </select>
            <label
                :for="$id('local-record-type')"
                class="label border-0!"
                >{{ $withEpisodeFilter ? mb_ucfirst(__('medical-events.medical_records_type')) : __('forms.type') }}</label
            >
        </div>
        @if ($withEpisodeFilter)
            <div class="form-group">
                <select
                    x-model="episodeId"
                    @change="search()"
                    class="input-select peer"
                    :id="$id('local-record-episode')"
                    data-record-episode
                >
                    <option value="">
                        {{ $withEpisodeFilter ? __('referrals.registry.all') : __('forms.select') }}
                    </option>
                    @foreach ($episodes as $episode)
                        <option value="{{ $episode['uuid'] }}">
                            {{ $episode['display'] ?? $episode['label'] ?? $episode['name'] }}
                        </option>
                    @endforeach
                </select>
                <label
                    :for="$id('local-record-episode')"
                    class="label border-0!"
                    >{{ __('episodes.label') }}</label
                >
                @if ($episodesHasMore && $loadMoreEpisodesAction !== '')
                    <button
                        type="button"
                        wire:click="{{ $loadMoreEpisodesAction }}"
                        wire:loading.attr="disabled"
                        class="button-minor mt-2"
                        data-more-episodes
                    >
                        {{ __('forms.show_more') }}: {{ __('episodes.plural') }}
                    </button>
                @endif
            </div>
        @endif
    </div>
    <div class="table-scroll">
        <table class="table-input w-inherit">
            <thead class="thead-input">
                <tr>
                    <th scope="col" class="th-input">{{ __('forms.date') }}</th>
                    @if ($showTypeColumn)
                        <th scope="col" class="th-input">
                            {{ __('forms.type') }}
                        </th>
                    @endif
                    <th scope="col" class="th-input">
                        {{ $showTypeColumn ? __('forms.name') : __('medical-events.code_and_name') }}
                    </th>
                    <th scope="col" class="th-input text-center">
                        {{ __('forms.action') }}
                    </th>
                </tr>
            </thead>
            <tbody>
                @foreach ($records as $record)
                    <tr
                        x-show="matches(@js($record))"
                        data-record-row="{{ $record['type'] }}:{{ $record['uuid'] }}"
                        class="border-b border-gray-200 transition-colors hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800/40"
                    >
                        <td
                            class="td-input text-sm text-gray-900 dark:text-gray-300"
                        >
                            {{ $record['date'] ?: '—' }}
                        </td>
                        @if ($showTypeColumn)
                            <td
                                class="td-input text-sm text-gray-900 dark:text-gray-300"
                            >
                                {{ $record['typeLabel'] ?? $typeOptions[$record['type']] ?? $record['type'] }}
                            </td>
                        @endif
                        <td
                            class="td-input text-sm text-gray-900 dark:text-white"
                        >
                            {{ $record['label'] }}
                        </td>
                        <td class="td-input text-center">
                            <button
                                type="button"
                                x-show="! {{ $isAddedCheck }}(@js($record['uuid']))"
                                @click="$dispatch(@js($selectionEvent), { record: @js($record) })"
                                aria-label="{{ __('forms.select') }}: {{ $record['label'] }}"
                                class="inline-flex cursor-pointer items-center justify-center text-gray-900 hover:text-blue-600 dark:text-white dark:hover:text-blue-400"
                            >
                                @icon ($selectionIcon, 'w-5 h-5')
                            </button>
                            <span
                                x-show="{{ $isAddedCheck }}(@js($record['uuid']))"
                                class="text-sm text-green-600 dark:text-green-400"
                            >
                                {{ __('medical-events.added') }}
                            </span>
                        </td>
                    </tr>
                @endforeach
                <tr x-show="!hasMatches">
                    <td
                        colspan="{{ $showTypeColumn ? 4 : 3 }}"
                        class="td-input text-sm text-gray-500 dark:text-gray-400"
                    >
                        {{ __('forms.nothing_found') }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    @if ($hasMore && $loadMoreAction !== '')
        <button
            type="button"
            wire:click="{{ $loadMoreAction }}"
            wire:loading.attr="disabled"
            class="button-minor mt-4"
        >
            {{ __('forms.show_more') }}
        </button>
    @endif
</div>
