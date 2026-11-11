@props (['records', 'selectionEvent', 'isAddedCheck'])

<div {{ $attributes }} x-data="{ query: '', recordType: '' }">
    <div class="form-row-2 mb-6">
        <div class="form-group">
            <input
                type="search"
                x-model="query"
                class="input peer"
                placeholder=" "
                id="local-record-query"
            />
            <label
                for="local-record-query"
                class="label"
                >{{ __('forms.search') }}</label
            >
        </div>
        <div class="form-group">
            <select
                x-model="recordType"
                class="input-select peer"
                id="local-record-type"
            >
                <option value="">{{ __('forms.select') }}</option>
                @foreach (collect($records)->pluck('typeLabel', 'type') as $type => $label)
                    <option value="{{ $type }}">{{ $label }}</option>
                @endforeach
            </select>
            <label
                for="local-record-type"
                class="label"
                >{{ __('forms.type') }}</label
            >
        </div>
    </div>

    <div class="table-scroll">
    <table class="table-input w-inherit">
        <thead class="thead-input">
            <tr>
                <th scope="col" class="th-input">{{ __('forms.date') }}</th>
                <th scope="col" class="th-input">
                    {{ __('medical-events.code_and_name') }}
                </th>
                <th scope="col" class="th-input text-center">
                    {{ __('forms.action') }}
                </th>
            </tr>
        </thead>
        <tbody>
            @forelse ($records as $record)
                <tr
                    x-show="(!recordType || recordType === @js($record['type'])) && (!query || @js($record['label']).toLowerCase().includes(query.toLowerCase()))"
                    class="border-b border-gray-200 transition-colors hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800/40"
                >
                    <td
                        class="td-input text-sm text-gray-900 dark:text-gray-300"
                    >
                        {{ $record['date'] ?: '—' }}
                    </td>
                    <td class="td-input text-sm text-gray-900 dark:text-white">
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
                            @icon ('plus', 'w-5 h-5')
                        </button>
                        <span
                            x-show="{{ $isAddedCheck }}(@js($record['uuid']))"
                            class="text-sm text-green-600 dark:text-green-400"
                        >
                            {{ __('medical-events.added') }}
                        </span>
                    </td>
                </tr>
            @empty
                <tr>
                    <td
                        colspan="3"
                        class="td-input text-sm text-gray-500 dark:text-gray-400"
                    >
                        {{ __('device-requests.no_available_records') }}
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
    </div>
</div>
