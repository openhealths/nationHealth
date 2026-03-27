<fieldset class="fieldset">
    <legend class="legend px-2 text-lg font-bold text-slate-900">
        {{ __('care-plan.condition/diagnosis') }}
    </legend>

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

            <template x-for="(item, index) in items" :key="index">
                <tr>
                    <td class="py-4 px-4 text-sm text-gray-700" x-text="item.date"></td>
                    <td class="py-4 px-4 text-sm text-gray-900 font-medium" x-text="item.name"></td>
                </tr>
            </template>
            </tbody>
        </table>
    </div>
</fieldset>
