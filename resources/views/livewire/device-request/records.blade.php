<fieldset>
    <legend>{{ __('device-requests.'.$recordTitle) }}</legend>
    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700 mb-6">
        <table class="table-input w-full text-left">
            <thead class="thead-input"><tr>
                @foreach (['date', 'type', 'record', 'action'] as $column)
                    <th scope="col" class="th-input">{{ __('device-requests.'.$column) }}</th>
                @endforeach
            </tr></thead>
            <tbody>
                @forelse ($request[$recordGroup] ?? [] as $index => $reference)
                    @php $record = $recordRows[($reference['type'] ?? '').'|'.($reference['uuid'] ?? '')] ?? []; @endphp
                    <tr class="border-t border-gray-200 dark:border-gray-700" wire:key="request-{{ $recordGroup }}-{{ $index }}-{{ $reference['uuid'] ?? '' }}">
                        <td class="td-input whitespace-nowrap">{{ $record['date'] ?? '—' }}</td>
                        <td class="td-input">{{ $record['typeLabel'] ?? __('device-requests.reason_types.'.($reference['type'] ?? '')) }}
                            <x-input-error for="request.{{ $recordGroup }}.{{ $index }}.type" /></td>
                        <td class="td-input">{{ $record['label'] ?? __('device-requests.validation.reason_unavailable') }}
                            <x-input-error for="request.{{ $recordGroup }}.{{ $index }}.uuid" /></td>
                        <td class="td-input">
                            @if ($recordGroup === 'reason_reference')
                                <button type="button" class="record-inner-action-btn hover:text-red-600"
                                    wire:click="removeReason({{ $index }})" aria-label="{{ __('forms.delete') }}">@icon('delete', 'w-4 h-4')</button>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="td-input text-gray-500">{{ __('device-requests.no_records') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <x-input-error for="request.{{ $recordGroup }}" />
    @if ($recordGroup === 'reason_reference')
        <button type="button" class="care-plan-add" @click="recordSearch = true">{{ __('device-requests.add_record') }}</button>
    @else
        <button type="button" class="care-plan-add disabled:opacity-50" aria-describedby="supporting-record-hint" disabled>{{ __('device-requests.add_record') }}</button>
        <p id="supporting-record-hint" class="mt-2 text-sm text-gray-500">{{ __('device-requests.supporting_unavailable') }}</p>
    @endif
</fieldset>
