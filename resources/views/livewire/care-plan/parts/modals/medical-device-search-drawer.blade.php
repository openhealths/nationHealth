<x-dialog-drawer
    id="medical-device-search-drawer-right"
    x-model="showMedicalDeviceSearchDrawer"
    :stopClickPropagation="true"
    maxWidth="4/5"
    zIndex="48"
    customWidth="w-full sm:w-[calc(80%-30px)]"
    overlayWidth="80%"
    onCloseClick="showMedicalDeviceSearchDrawer = false"
>
    <x-slot name="title">{{ __('care-plan.medical_device_search') }}</x-slot>

    @php
        $deviceProgramId = $selectedProgram ?: ($activityForm->program ?? '');
        $deviceProgramName = $deviceProgramId !== ''
            ? ($dictionaries['medical_programs_device'][$deviceProgramId] ?? $dictionaries['medical_programs'][$deviceProgramId] ?? $deviceProgramId)
            : '';
    @endphp

    @if ($deviceProgramName !== '')
        <p class="mb-4 text-sm text-gray-600 dark:text-gray-300">
            {{ __('care-plan.program') }}: <span class="font-medium">{{ $deviceProgramName }}</span>
        </p>
    @else
        <p class="mb-4 text-sm text-gray-600 dark:text-gray-300">
            {{ __('care-plan.program') }}:
            <span class="font-medium">{{ __('care-plan.without_medical_program') }}</span>
        </p>
    @endif

    <div class="mb-4">
        <div class="relative">
            <div class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3">
                @icon('search-outline', 'w-5 h-5 text-gray-500')
            </div>
            <input
                type="text"
                x-ref="deviceSearchQuery"
                class="input peer w-full ps-10"
                placeholder="{{ __('care-plan.device_search_placeholder') }}"
                wire:model.live.debounce.400ms="searchQuery"
                x-on:keydown.enter.prevent="
                    $wire.set('searchQuery', $refs.deviceSearchQuery.value).then(() => $wire.searchMedicalDevices())
                "
            />
        </div>
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('care-plan.device_search_hint') }}</p>
    </div>

    <div class="mb-6 flex flex-wrap gap-2" x-data="{ showFilter: false }">
        <button
            type="button"
            class="button-primary flex items-center gap-2"
            x-on:click="
                $wire.set('searchQuery', $refs.deviceSearchQuery.value).then(() => $wire.searchMedicalDevices())
            "
        >
            @icon('search', 'w-4 h-4')
            <span>{{ __('forms.search') }}</span>
        </button>
        <button type="button" wire:click="resetDeviceSearchFilters" class="button-primary-outline-red">
            {{ __('forms.reset_all_filters') }}
        </button>
        <button type="button" class="button-minor flex items-center gap-2" @click="showFilter = ! showFilter">
            @icon('adjustments', 'w-4 h-4')
            <span>{{ __('forms.additional_search_parameters') }}</span>
        </button>

        <div x-show="showFilter" x-cloak x-transition class="mt-2 grid w-full grid-cols-1 gap-4 md:grid-cols-2">
            <div class="form-group group">
                <label for="device_search_model_number" class="label">
                    {{ __('care-plan.medical_device_model_number') }}
                </label>
                <input
                    type="text"
                    id="device_search_model_number"
                    class="input peer w-full"
                    placeholder="{{ __('care-plan.medical_device_model_number') }}"
                    wire:model.live.debounce.400ms="deviceSearchModelNumber"
                    wire:keydown.enter="searchMedicalDevices"
                />
            </div>
        </div>
    </div>

    @if ($deviceSearchTotalEntries > 0)
        <p class="mb-3 text-sm text-gray-600 dark:text-gray-300">
            {{ __('care-plan.device_search_results_count', ['count' => $deviceSearchTotalEntries]) }}
        </p>
    @endif

    <div class="mb-6 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="thead-input">
                <tr>
                    <th scope="col" class="px-4 py-3 font-medium">{{ __('care-plan.name') }}</th>
                    <th scope="col" class="px-4 py-3 font-medium">{{ __('care-plan.type') }}</th>
                    <th scope="col" class="px-4 py-3 font-medium">{{ __('care-plan.packaging') }}</th>
                    <th scope="col" class="px-4 py-3 font-medium">{{ __('care-plan.code') }}</th>
                    <th scope="col" class="px-4 py-3 font-medium">{{ __('care-plan.uuid') }}</th>
                    <th scope="col" class="px-4 py-3 text-right font-medium">Дія</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($searchResults as $device)
                    @php
                        $deviceSelectId = $device['display_uuid'] ?? $device['id'] ?? $device['uuid'] ?? '';
                    @endphp
                    <tr
                        class="hover:bg-gray-50 dark:hover:bg-gray-700/50"
                        wire:key="device-search-{{ $device['id'] ?? $loop->index }}"
                    >
                        <td class="px-4 py-3">
                            <span class="font-medium text-gray-900 dark:text-white">{{ $device['display_name'] ?? '' }}</span>
                            @if (!empty($device['model_number']))
                                <div class="text-xs text-gray-500">
                                    {{ __('care-plan.medical_device_model_number') }}: {{ $device['model_number'] }}
                                </div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-gray-500">{{ $device['display_type'] ?? '-' }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ $device['display_packaging'] ?? '-' }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $device['display_code'] ?? '-' }}</td>
                        <td
                            class="px-4 py-3 font-mono text-xs text-gray-500 dark:text-gray-400"
                            title="{{ $device['display_uuid'] ?? '' }}"
                        >
                            {{ !empty($device['display_uuid']) ? $device['display_uuid'] : '-' }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <button
                                type="button"
                                class="button-primary-outline text-xs"
                                wire:click="selectSearchedDevice(@js($deviceSelectId))"
                            >
                                Обрати
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-gray-400 italic">
                            @if ($deviceProgramId === '')
                                {{ __('care-plan.device_search_no_catalog_without_program') }}
                            @elseif (empty($searchQuery) && empty($deviceSearchModelNumber))
                                {{ __('care-plan.device_search_no_catalog') }}
                            @else
                                {{ __('care-plan.device_search_no_results', ['query' => $searchQuery ?: $deviceSearchModelNumber]) }}
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($deviceSearchTotalPages > 1)
        <div class="mb-6 flex flex-wrap items-center justify-center gap-2">
            <button
                type="button"
                class="button-minor text-sm"
                wire:click="goToDeviceSearchPage({{ max(1, $searchPage - 1) }})"
                @disabled($searchPage <= 1)
            >
                {{ __('pagination.previous') }}
            </button>
            <span class="text-sm text-gray-600 dark:text-gray-300">
                {{ $searchPage }} / {{ $deviceSearchTotalPages }}
            </span>
            <button
                type="button"
                class="button-minor text-sm"
                wire:click="goToDeviceSearchPage({{ min($deviceSearchTotalPages, $searchPage + 1) }})"
                @disabled($searchPage >= $deviceSearchTotalPages)
            >
                {{ __('pagination.next') }}
            </button>
        </div>
    @endif

    <div class="mt-6">
        <button type="button" class="button-minor" @click="showMedicalDeviceSearchDrawer = false">
            {{ __('forms.cancel') }}
        </button>
    </div>
</x-dialog-drawer>
