@if ($items !== [])
    <table class="w-full border-collapse text-sm">
        <caption class="text-left font-semibold">
            {{ __('medication-requests.dispense_history') }}
        </caption>
        <thead>
            <tr>
                <th>{{ __('medication-requests.dispensed_at') }}</th>
                <th>{{ __('medication-requests.status') }}</th>
                <th>{{ __('care-plan.quantity') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($items as $dispense)
                <tr wire:key="dispense-{{ $dispense['id'] }}">
                    <td>{{ $dispense['dispensed_at'] }}</td>
                    <td>{{ $dispense['status'] }}</td>
                    <td>{{ $dispense['medication_qty'] ?? data_get($dispense, 'medication.medication_qty') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
