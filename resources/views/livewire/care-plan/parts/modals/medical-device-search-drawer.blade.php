{{-- Medical Device Search Drawer — same structure as medication-search-drawer (no teleport). --}}
<div
    x-show="showMedicalDeviceSearchDrawer"
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="opacity-0"
    x-transition:enter-end="opacity-100"
    x-transition:leave="transition ease-in duration-200"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    x-cloak
    @click="showMedicalDeviceSearchDrawer = false"
    class="fixed top-0 right-0 h-screen w-4/5 bg-gray-900/50 pt-20"
    style="z-index: 48"
></div>

<div
    id="medical-device-search-drawer-right"
    x-show="showMedicalDeviceSearchDrawer"
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="translate-x-full"
    x-transition:enter-end="translate-x-0"
    x-transition:leave="transition ease-in duration-200"
    x-transition:leave-start="translate-x-0"
    x-transition:leave-end="translate-x-full"
    x-cloak
    class="fixed top-0 right-0 h-screen overflow-y-auto bg-white p-4 pt-20 shadow-2xl dark:bg-gray-800"
    style="z-index: 49; width: calc(80% - 30px)"
    tabindex="-1"
>
    <h3 class="modal-header">{{ __('care-plan.medical_device_search') }}</h3>

    @php
        $deviceProgramId = $selectedProgram ?: ($activityForm['program'] ?? '');
        $deviceProgramName = $deviceProgramId !== ''
            ? ($dictionaries['medical_programs_device'][$deviceProgramId] ?? $dictionaries['medical_programs'][$deviceProgramId] ?? $deviceProgramId)
            : '';
    @endphp

    @if ($deviceProgramName !== '')
        <p class="mb-4 text-sm text-gray-600 dark:text-gray-300">
            {{ __('care-plan.program') }}: <span class="font-medium">{{ $deviceProgramName }}</span>
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
                                {{ __('care-plan.select_program_first') }}
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
</div>
