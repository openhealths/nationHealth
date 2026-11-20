<x-dialog-drawer id="medical-device-search-drawer-right" x-model="showMedicalDeviceSearchDrawer" :stopClickPropagation="true" maxWidth="4/5" topClass="top-[57px]" zIndex="48" customWidth="w-full sm:w-[calc(80%-30px)]" overlayWidth="100%" onCloseClick="showMedicalDeviceSearchDrawer = false">
    <x-slot name="title">{{ __('care-plan.medical_device_search') }}</x-slot>
    <div class="care-plan-service-form" x-data="{ showFilter: true, selectedParticipant: null, type: '' }"
        x-init="$watch('showMedicalDeviceSearchDrawer', (open, wasOpen) => {
            selectedParticipant = null; type = '';
            if (wasOpen && !open && !showMedicalDeviceFormDrawer) $wire.set('showMedicalDeviceDrawer', true);
        })">
        <div class="form-group group max-w-sm">
            <span class="svg-input">@icon('search', 'w-4 h-4')</span>
            <input id="device-catalog-query" type="search" class="input peer" placeholder=" " wire:model="searchQuery" wire:keydown.enter.prevent="searchMedicalDevices" @keydown.enter="selectedParticipant = null" />
            <label for="device-catalog-query" class="label">{{ __('care-plan.device_search_placeholder') }}</label>
        </div>
        <div class="mt-6 mb-8 flex flex-wrap gap-3">
            <button type="button" class="button-primary flex items-center gap-2" wire:click="searchMedicalDevices" @click="selectedParticipant = null">@icon('search', 'w-4 h-4') {{ __('forms.search') }}</button>
            <button type="button" class="button-primary-outline-red" wire:click="resetDeviceSearchFilters" @click="selectedParticipant = null; type = ''">{{ __('forms.reset_all_filters') }}</button>
            <button type="button" class="button-minor flex items-center gap-2" @click="showFilter = !showFilter" :aria-expanded="showFilter">@icon('adjustments', 'w-4 h-4') {{ __('forms.additional_search_parameters') }}</button>
        </div>
        <div class="care-plan-medication-grid mb-10" x-show="showFilter" x-cloak>
            <div class="form-group group">
                <select id="device-catalog-type" class="input-select peer" x-model="type" @change="selectedParticipant = null"><option value="">{{ __('forms.select') }}</option>@foreach (($dictionaries['device_definition_classification_type'] ?? []) as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach</select>
                <label for="device-catalog-type" class="label">{{ __('care-plan.medical_device_type') }}</label>
            </div>
            <div class="form-group group">
                <input id="device-catalog-model" type="text" class="input peer" placeholder=" " wire:model="deviceSearchModelNumber" wire:keydown.enter.prevent="searchMedicalDevices" @keydown.enter="selectedParticipant = null" />
                <label for="device-catalog-model" class="label">{{ __('care-plan.medical_device_model_number') }}</label>
            </div>
        </div>
        <fieldset class="!p-4">
            <div class="overflow-x-auto">
                <table class="table-input w-full text-left text-sm">
                    <thead class="thead-input"><tr>
                        <th class="px-4 py-4">{{ __('care-plan.name') }}</th><th class="px-4 py-4">{{ __('care-plan.type') }}</th><th class="px-4 py-4">{{ __('care-plan.packaging') }}</th><th class="px-4 py-4">{{ __('dictionaries.medical_device.table.program_participants') }}</th><th class="px-4 py-4">{{ __('forms.actions') }}</th>
                    </tr></thead>
                    <tbody>
                        @forelse ($searchResults as $device)
                            <tr class="border-b border-gray-200 dark:border-gray-700" wire:key="device-catalog-{{ $device['id'] ?? $loop->index }}" data-types="{{ collect($device['classification_types'] ?? [])->pluck('code')->implode(',') }}" x-show="!type || $el.dataset.types.split(',').includes(type)">
                                <td class="px-4 py-4"><div>{{ $device['display_name'] ?? '-' }}</div><div class="mt-1 text-xs text-gray-500">{{ __('care-plan.medical_device_model_number') }}: {{ $device['model_number'] ?? '-' }}</div><div class="mt-1 break-all text-xs text-gray-500">ID: {{ $device['id'] ?? $device['uuid'] ?? '-' }}</div></td><td class="px-4 py-4 text-gray-500">{{ $device['display_type'] ?? '-' }}</td><td class="px-4 py-4 text-gray-500">{{ $device['display_packaging'] ?? '-' }}</td>
                                <td class="px-4 py-4 text-gray-500">
                                    @forelse (($device['program_devices'] ?? []) as $row)
                                        @php
                                            $programId = $row['medical_program_id'] ?? $row['program_id'] ?? '';
                                            $name = $row['name'] ?? data_get($row, 'medical_program.name') ?? $dictionaries['medical_programs_device'][$programId] ?? $programId;
                                            $unit = data_get($device, 'packaging.packaging_unit', '');
                                            $participant = [
                                                'name' => $name, 'title' => $row['display_name'] ?? $name,
                                                'period' => (filled($row['start_date'] ?? null) ? convertToAppDateFormat($row['start_date']) : '-').' — '.(filled($row['end_date'] ?? null) ? convertToAppDateFormat($row['end_date']) : __('dictionaries.medical_device.participant_details.end_date_not_set')),
                                                'maxDailyCount' => $row['max_daily_count'] ?? '-',
                                                'unit' => $dictionaries['device_unit'][$unit] ?? $unit,
                                            ];
                                        @endphp
                                        <div class="flex items-center gap-2"><span>{{ $participant['name'] }}</span><button type="button" class="record-inner-action-btn p-1" @click="selectedParticipant = @js($participant)" aria-label="{{ __('device-requests.patient.participant_details') }} — {{ $participant['name'] }}">@icon('question-mark-circle', 'w-4 h-4')</button></div>
                                    @empty - @endforelse
                                </td>
                                <td class="px-4 py-4"><button type="button" class="record-inner-action-btn p-1" wire:click="selectSearchedDevice(@js($device['id'] ?? $device['uuid'] ?? ''))" aria-label="{{ __('care-plan.select_medical_device') }} — {{ $device['display_name'] ?? '' }}">@icon('plus-circle', 'w-5 h-5')</button></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-8 text-center text-gray-500">{{ __('care-plan.device_search_no_results', ['query' => $searchQuery ?: $deviceSearchModelNumber]) }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </fieldset>
        <template x-if="selectedParticipant">
            <fieldset class="relative text-gray-500">
                <legend x-text="selectedParticipant.title"></legend>
                <button type="button" class="record-inner-action-btn absolute right-4 top-3 p-1" @click="selectedParticipant = null" aria-label="{{ __('forms.close') }}">@icon('close', 'w-5 h-5')</button>
                <div class="space-y-2 pr-4 font-semibold">
                    <p>{{ __('dictionaries.medical_device.participant_details.period') }}: <span x-text="selectedParticipant.period"></span></p>
                    <p>{{ __('dictionaries.medical_device.participant_details.max_daily_dose') }}: <span x-text="`${selectedParticipant.maxDailyCount} ${selectedParticipant.unit}`"></span></p>
                </div>
            </fieldset>
        </template>
        @if ($deviceSearchTotalPages > 1)
            <div class="mb-8 flex items-center justify-center gap-3">
                <button type="button" class="button-minor" @click="selectedParticipant = null" wire:click="goToDeviceSearchPage({{ max(1, $searchPage - 1) }})" @disabled($searchPage <= 1)>{{ __('pagination.previous') }}</button>
                <span>{{ $searchPage }} / {{ $deviceSearchTotalPages }}</span>
                <button type="button" class="button-minor" @click="selectedParticipant = null" wire:click="goToDeviceSearchPage({{ min($deviceSearchTotalPages, $searchPage + 1) }})" @disabled($searchPage >= $deviceSearchTotalPages)>{{ __('pagination.next') }}</button>
            </div>
        @endif
        <button type="button" class="button-minor" @click="showMedicalDeviceSearchDrawer = false">{{ __('forms.cancel') }}</button>
    </div>
</x-dialog-drawer>
