@php
    $nodeData = $data ?? [];
    $pathPrefix = $path ?? '';
@endphp

@if (is_array($nodeData) && $nodeData !== [])
    <dl class="min-w-0 space-y-3">
        @foreach ($nodeData as $key => $value)
            @php
                $fullPath = $pathPrefix === '' ? (string) $key : $pathPrefix . '.' . $key;
                $translationKey = 'contracts.ehealth_fields.' . $key;
                $label = is_int($key)
                    ? __('contracts.collection_item', ['number' => $key + 1])
                    : (__($translationKey) === $translationKey ? $key : __($translationKey));
            @endphp
            <div class="min-w-0 rounded-md border border-gray-200 dark:border-gray-700">
                <dt class="bg-gray-50 px-3 py-2 text-sm font-semibold break-words text-gray-700 dark:bg-gray-800 dark:text-gray-200">
                    {{ $label }}
                </dt>
                <dd class="min-w-0 px-3 py-2 text-sm break-words text-gray-900 dark:text-gray-200">
                    @if (is_array($value))
                        @include('livewire.contract-request.partials.ehealth-data-tree', ['data' => $value, 'path' => $fullPath])
                    @elseif (is_bool($value))
                        {{ $value ? __('forms.yes') : __('forms.no') }}
                    @elseif ($value === null)
                        {{ __('contracts.not_specified') }}
                    @elseif ($value === '')
                        {{ __('contracts.empty_value') }}
                    @else
                        <span class="[overflow-wrap:anywhere] whitespace-pre-wrap">{{ (string) $value }}</span>
                    @endif
                </dd>
            </div>
        @endforeach
    </dl>
@else
    <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('contracts.empty_collection') }}</span>
@endif
