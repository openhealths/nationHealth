<fieldset>
    <legend>{{ __('care-plan.grounds_for_prescription') }}</legend>
    @php
        $groundsDrawerState = match ($inputPrefix) {
            'service' => 'showServiceDrawer',
            'medication' => 'showMedicationFormDrawer',
            'device-new' => 'showMedicalDeviceDrawer',
            'device-edit' => 'showMedicalDeviceFormDrawer',
            default => null,
        };
    @endphp
    <div class="form-group group mb-8 max-w-sm">
        <div x-data="carePlanGroundsSearch(@js($groundsDrawerState))" data-icd10-search-url="{{ route('icd10.lookup', [legalEntity()]) }}" class="relative">
            <input type="search" id="{{ $inputPrefix }}-reason-code" class="input-select peer" autocomplete="off"
                :value="query"
                placeholder="{{ __('care-plan.select_icd10_code') }}"
                @input="editQuery($event.target.value)"
                @click.outside="showResults = false" />
            <p x-show="searchFailed" x-cloak class="text-error mt-2">{{ __('care-plan.icd10_search_unavailable') }}</p>
            <p x-show="showResults && !results.length" x-cloak class="mt-2 text-sm text-gray-500">{{ __('forms.nothing_found') }}</p>
            <div x-show="showResults && results.length" x-cloak class="absolute top-full z-50 max-h-60 w-full overflow-auto rounded-lg border bg-white p-2 shadow-lg dark:bg-gray-800">
                <template x-for="result in results" :key="result.code">
                    <button type="button" class="block w-full p-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-700" @click="selectCode(result)" x-text="result.code + ' - ' + result.description"></button>
                </template>
            </div>
        </div>
    </div>

    <h4 class="mb-8 text-base">{{ __('care-plan.justification_of_grounds') }}</h4>
    <div class="table-scroll">
        <table class="table-input w-full">
            <thead class="thead-input"><tr>
                <th class="th-input">{{ __('care-plan.date') }}</th>
                <th class="th-input">{{ __('care-plan.name') }}</th>
                <th class="th-input text-right">{{ __('care-plan.action') }}</th>
            </tr></thead>
            <tbody>
                @forelse ($linkedGrounds as $ground)
                    <tr wire:key="{{ $inputPrefix }}-ground-{{ $ground['uuid'] }}">
                        <td class="td-input whitespace-nowrap">{{ $ground['date'] }}</td>
                        <td class="td-input">{{ $ground['name'] }}</td>
                        <td class="td-input text-right">
                            <button type="button" wire:click="removeLinkedGround('{{ $ground['uuid'] }}')" aria-label="{{ __('forms.delete') }}" class="cursor-pointer">
                                @icon('delete', 'w-5 h-5')
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="td-input text-sm text-gray-400">{{ __('care-plan.no_medical_records_added') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <button type="button" class="care-plan-add mt-6" @click="showMedicalRecordsSearchDrawer = true">+ {{ __('care-plan.add_medical_record') }}</button>
</fieldset>
