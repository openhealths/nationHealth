@php
    $searchQuery = $searchQuery ?? '';
    $searchResults = $searchResults ?? [];
@endphp

<x-dialog-drawer
    x-model="showServiceSearchDrawer"
    noTeleport="true"
    topClass="top-[57px]"
    zIndex="42"
    customWidth="w-full sm:w-[calc(80%-15%)]"
    overlayWidth="80%"
    hasClose="true"
    onCloseClick="showServiceSearchDrawer = false"
>
    <div x-data="{ showFilter: false }" class="flex flex-col h-full w-full">
    <div class="mb-6">
        <h2 class="text-2xl font-bold text-gray-900 dark:text-white" id="service-search-drawer-label">
            {{ __('care-plan.search_service') }}
        </h2>
    </div>

    {{-- Search Input --}}
    <div class="mb-4">
        <div class="relative">
            <div class="absolute inset-y-0 start-0 flex items-center ps-3 pointer-events-none">
                @icon('search-outline', 'w-5 h-5 text-gray-500')
            </div>
            <input type="text"
                   id="service-search-input"
                   class="input peer ps-10 w-full"
                   placeholder="Киснева терапія"
                   wire:model.live.debounce.400ms="searchQuery"
                   wire:keydown.enter="searchServices"
            />
        </div>
    </div>

    {{-- Action Buttons --}}
    <div class="flex flex-wrap gap-2 mb-6">
        <button type="button" wire:click="searchServices" class="button-primary flex items-center gap-2">
            @icon('search', 'w-4 h-4')
            <span>{{ __('forms.search') }}</span>
        </button>
        <button type="button" wire:click="$set('searchQuery', '')" class="button-primary-outline-red">
            {{ __('forms.reset_all_filters') }}
        </button>
        <button type="button"
                class="button-minor flex items-center gap-2"
                @click="showFilter = !showFilter"
        >
            @icon('adjustments', 'w-4 h-4')
            <span>{{ __('forms.additional_search_parameters') }}</span>
        </button>
    </div>

    <div x-show="showFilter" x-cloak x-transition class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
        <div class="form-group group">
            <label class="label">
                {{ __('care-plan.service_category') }}
            </label>
            <select class="input-select peer w-full">
                <option selected value="">{{ __('care-plan.procedures_on_nervous_system') }}</option>
            </select>
        </div>
        <div class="form-group group">
            <label class="label">
                {{ __('care-plan.service_group_active') }}
            </label>
            <select class="input-select peer w-full">
                <option selected value="yes">{{ __('care-plan.yes') }}</option>
            </select>
        </div>
        <div class="form-group group">
            <label class="label">
                {{ __('care-plan.service_active') }}
            </label>
            <select class="input-select peer w-full">
                <option selected value="yes">{{ __('care-plan.yes') }}</option>
            </select>
        </div>
        <div class="form-group group">
            <label class="label">
                {{ __('care-plan.allowed_in_em') }}
            </label>
            <select class="input-select peer w-full">
                <option selected value="yes">{{ __('care-plan.yes') }}</option>
            </select>
        </div>
    </div>

    {{-- Results Table --}}
    <div class="overflow-x-auto mb-6 flex-1">
        <table class="w-full text-sm text-left">
            <thead class="thead-input">
                <tr>
                    <th scope="col" class="px-4 py-3 font-medium">{{ __('care-plan.name') }}</th>
                    <th scope="col" class="px-4 py-3 font-medium">{{ __('care-plan.allowed_in_em_short') }}</th>
                    <th scope="col" class="px-4 py-3 font-medium">{{ __('care-plan.code') }}</th>
                    <th scope="col" class="px-4 py-3 font-medium">{{ __('care-plan.status_title') }}</th>
                    <th scope="col" class="px-4 py-3 font-medium text-right">{{ __('care-plan.action') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($searchResults as $service)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                        <td class="px-4 py-3">
                            <div>
                                <p class="font-medium text-gray-900 dark:text-white">{{ $service['name'] ?? '' }}</p>
                                <p class="text-xs text-gray-500">{{ $service['code'] ?? '' }}</p>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-gray-500">
                            {{ ($service['request_allowed'] ?? true) ? '+' : '-' }}
                        </td>
                        <td class="px-4 py-3 font-mono text-xs">
                            {{ $service['code'] ?? '' }}
                        </td>
                        <td class="px-4 py-3">
                            @if($service['is_active'] ?? true)
                                <span class="px-2 py-1 text-xs font-medium rounded-full bg-green-100 text-green-800">{{ __('care-plan.active') }}</span>
                            @else
                                <span class="px-2 py-1 text-xs font-medium rounded-full bg-gray-100 text-gray-800">{{ __('care-plan.inactive') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <button type="button"
                                    wire:click="selectProduct({{ json_encode($service) }}, 'service_request')"
                                    class="button-primary-outline text-xs"
                            >
                                {{ __('forms.select') }}
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-gray-400 italic">
                            @if(empty($searchQuery))
                                {{ __('care-plan.enter_service_search_query') }}
                            @else
                                {{ __('care-plan.nothing_found_for_query') }} "{{ $searchQuery }}"
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-auto pt-6 border-t border-gray-100 dark:border-gray-700">
        <button type="button"
                class="button-minor"
                @click="showServiceSearchDrawer = false"
        >
            {{ __('forms.cancel') }}
        </button>
    </div>
    </div>
</x-dialog-drawer>
