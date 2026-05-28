@php
    $diagnoses = $diagnoses ?? $carePlan->addresses ?? [];
@endphp

<fieldset class="fieldset bg-white dark:bg-gray-800 !rounded-xl !shadow-none !border-gray-100 dark:!border-gray-700 !max-w-full !p-6 !mb-6">
    <legend class="legend">
        {{ __('care-plan.condition_diagnosis') ?? 'Стан/діагноз' }}
    </legend>

    <div class="mt-4 index-table-wrapper">
        <table class="index-table">
            <thead class="index-table-thead">
            <tr>
                <th class="index-table-th">
                    {{ __('care-plan.date') }}
                </th>
                <th class="index-table-th">
                    {{ __('care-plan.name') }}
                </th>
            </tr>
            </thead>
            <tbody>

            @forelse($diagnoses as $item)
                <tr class="index-table-tr">
                    <td class="index-table-td">{{ $item['date'] ?? '-' }}</td>
                    <td class="index-table-td-primary">{{ $item['name'] ?? '' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="2" class="index-table-td !py-3 text-center text-gray-400">
                        {{ __('care-plan.no_diagnoses') }}
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @error('form.encounter')
    <p class="text-error mt-2">{{ $message }}</p>
    @enderror
</fieldset>
