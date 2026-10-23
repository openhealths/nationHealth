@if (is_array($value))
    @if (isset($value['start']))
        <span>{{ $value['start'] }} — {{ $value['end'] ?? __('care-plan.no_end_date') }}</span>
    @elseif (isset($value['display_value']))
        <span>{{ $value['display_value'] }}</span>
    @elseif (isset($value['coding']))
        @if (!empty($value['text']))
            <span>{{ $value['text'] }}</span>
        @endif
        @foreach ($value['coding'] as $coding)
            <span>{{ $coding['display'] ?? '' }} {{ $coding['code'] ?? '' }} ({{ $coding['system'] ?? '' }})</span>
        @endforeach
    @elseif (isset($value['identifier']))
        <span>{{ data_get($value, 'identifier.value') }}</span>
    @elseif (isset($value['value']))
        <span>{{ $value['value'] }} {{ $value['unit'] ?? $value['code'] ?? '' }}</span>
    @else
        @foreach ($value as $key => $item)
            <div>
                @if (!is_int($key))
                    <span>{{ __('device-requests.value_labels')[$key] ?? '' }}</span>
                @endif
                @include('livewire.device-request.record-value', ['value' => $item])
            </div>
        @endforeach
    @endif
@elseif (is_bool($value))
    {{ $value ? __('device-requests.form.yes') : __('device-requests.form.no') }}
@else
    {{ $value }}
@endif
