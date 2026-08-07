<fieldset class="fieldset !mb-6 !max-w-full !rounded-xl !border-gray-100 bg-white !p-6 !shadow-none dark:!border-gray-700 dark:bg-gray-800">
    <legend class="legend">{{ __('care-plan.condition_diagnosis') ?? 'Стан/діагноз' }}</legend>

    <div class="index-table-wrapper mt-4">
        <table class="index-table">
            <thead class="index-table-thead">
                <tr>
                    <th class="index-table-th">{{ __('care-plan.date') }}</th>
                    <th class="index-table-th">{{ __('care-plan.name') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($diagnoses as $item)
                    <tr class="index-table-tr">
                        <td class="index-table-td">{{ $item['date'] }}</td>
                        <td class="index-table-td-primary">{{ $item['name'] }}</td>
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
