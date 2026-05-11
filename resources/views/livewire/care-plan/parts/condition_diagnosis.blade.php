<div class="mt-4">
    <table class="w-full text-left border-collapse">
        <thead>
        <tr class="bg-gray-50/50">
            <th class="py-3 px-4 text-xs font-semibold uppercase tracking-wider text-gray-500 border-b border-gray-100">
                {{ __('care-plan.date') }}
            </th>
            <th class="py-3 px-4 text-xs font-semibold uppercase tracking-wider text-gray-500 border-b border-gray-100">
                {{ __('care-plan.name') }}
            </th>
        </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">

        @forelse($diagnoses as $item)
            <tr>
                <td class="py-4 px-4 text-sm text-gray-700">{{ $item['date'] }}</td>
                <td class="py-4 px-4 text-sm text-gray-900 font-medium">{{ $item['name'] }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="2" class="py-4 px-4 text-sm text-gray-400 text-center">
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
