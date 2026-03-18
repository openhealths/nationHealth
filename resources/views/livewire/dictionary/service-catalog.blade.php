<div>
    <x-header-navigation x-data="{ showFilter: false }" class="breadcrumb-form">
        <x-slot name="title">
            {{ __('dictionaries.service_catalog.title') }}
        </x-slot>

        <x-slot name="navigation">
            <div class="flex flex-col -my-4" x-data="{ showFilter: false }">

                <div class="flex mb-4 flex-col w-full">
                    <div class="w-full lg:w-96">
                        <label for="serviceSearchDropdown"
                               class="text-sm font-medium text-gray-900 dark:text-white block mb-2 flex items-center gap-1"
                        >
                            @icon('search-outline', 'w-4.5 h-4.5')
                            <span>{{ __('dictionaries.service_catalog.search_services') }}</span>
                        </label>

                        <div class="form-group group w-full">
                            <input type="text"
                                   id="serviceSearch"
                                   class="input peer w-full"
                                   placeholder=" "
                                   wire:model="searchBy"
                            />
                            <label for="serviceSearch" class="label">
                                {{ __('dictionaries.service_catalog.search_placeholder') }}
                            </label>
                        </div>
                    </div>
                </div>

                <div class="mb-4 mt-6 flex flex-col gap-2 w-full sm:flex-row">
                    <button type="button"
                            wire:click="search"
                            class="flex items-center gap-2 button-primary"
                    >
                        @icon('search', 'w-4 h-4')
                        <span>{{ __('forms.search') }}</span>
                    </button>
                    <button type="button"
                            wire:click="resetFilters"
                            class="button-primary-outline-red me-0"
                    >
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

                {{-- Filters --}}
                <div x-cloak
                     x-show="showFilter"
                     x-transition
                     class="grid grid-cols-1 sm:grid-cols-2 gap-4 md:gap-6 w-full mt-4 mb-9 md:mb-5"
                >
                    <div class="form-group group">
                        <select wire:model="serviceCategory"
                                id="filterServiceCategory"
                                class="peer input-select w-full"
                        >
                            <option value="" selected>{{ __('forms.select') }}</option>
                            @if(!empty($this->dictionaries['SERVICE_CATEGORY']))
                                @foreach($this->dictionaries['SERVICE_CATEGORY'] as $key => $value)
                                    <option value="{{ $key }}">{{ $value }}</option>
                                @endforeach
                            @endif
                        </select>
                        <label for="filterServiceCategory"
                               class="label peer-focus:text-blue-600 peer-valid:text-blue-600"
                        >
                            {{ __('dictionaries.service_catalog.service_category') }}
                        </label>
                    </div>
                    <div class="form-group group">
                        <select wire:model="serviceGroupActive"
                                id="filterServiceGroupActive"
                                class="peer input-select w-full"
                        >
                            <option value="" selected>{{ __('forms.select') }}</option>
                            <option value="1">{{ __('forms.yes') }}</option>
                            <option value="0">{{ __('forms.no') }}</option>
                        </select>
                        <label for="filterServiceGroupActive"
                               class="label peer-focus:text-blue-600 peer-valid:text-blue-600"
                        >
                            {{ __('dictionaries.service_catalog.service_group_active') }}
                        </label>
                    </div>
                    <div class="form-group group">
                        <select wire:model="serviceActive"
                                id="filterServiceActive"
                                class="peer input-select w-full"
                        >
                            <option value="" selected>{{ __('forms.select') }}</option>
                            <option value="1">{{ __('forms.yes') }}</option>
                            <option value="0">{{ __('forms.no') }}</option>
                        </select>
                        <label for="filterServiceActive"
                               class="label peer-focus:text-blue-600 peer-valid:text-blue-600"
                        >
                            {{ __('dictionaries.service_catalog.service_active') }}
                        </label>
                    </div>
                    <div class="form-group group">
                        <select wire:model="allowedForEn"
                                id="filterAllowedForEn"
                                class="peer input-select w-full"
                        >
                            <option value="" selected>{{ __('forms.select') }}</option>
                            <option value="1">{{ __('forms.yes') }}</option>
                            <option value="0">{{ __('forms.no') }}</option>
                        </select>
                        <label
                            for="filterAllowedForEn"
                            class="label peer-focus:text-blue-600 peer-valid:text-blue-600"
                        >
                            {{ __('dictionaries.service_catalog.allowed_for_en') }}
                        </label>
                    </div>
                </div>
            </div>
        </x-slot>
    </x-header-navigation>

    <div class="flow-root mt-8 shift-content pl-3.5">
        <div class="max-w-screen-xl">
            <div class="index-table-wrapper">
                <table class="index-table">
                    <thead class="index-table-thead">
                    <tr>
                        <th class="index-table-th w-[40%]">
                            {{ __('forms.name') }}
                        </th>
                        <th class="index-table-th w-[20%]">
                            {{ __('dictionaries.service_catalog.allowed_for_en') }}
                        </th>
                        <th class="index-table-th w-[20%]">
                            {{ __('forms.code') }}
                        </th>
                        <th class="index-table-th w-[20%]">
                            {{ __('forms.status.label') }}
                        </th>
                    </tr>
                    </thead>

                    <tbody x-data="{ openIds: {} }">
                    @forelse($services as $item)
                        @php
                            $itemId = $item['id'] ?? ('item-' . $loop->index);
                            $hasGroups = !empty($item['groups']);
                            $hasServices = !empty($item['services']);
                            $hasChildren = $hasGroups || $hasServices;
                        @endphp

                        {{-- Main category/service --}}
                        <tr class="index-table-tr">
                            <td class="index-table-td-primary">
                                <div class="flex items-start gap-3">
                                    <div class="mt-1 flex-shrink-0 w-6">
                                        @if ($hasChildren)
                                            <button type="button"
                                                    @click="openIds['{{ $itemId }}'] = !openIds['{{ $itemId }}']"
                                                    class="cursor-pointer p-0.5 rounded hover:bg-gray-100 dark:hover:bg-gray-700 inline-block"
                                                    :aria-expanded="!!openIds['{{ $itemId }}']"
                                            >
                                                <span class="inline-block transition-transform duration-200"
                                                      :class="openIds['{{ $itemId }}'] ? 'rotate-0' : '-rotate-90'"
                                                >
                                                    @icon('chevron-down', 'w-4 h-4 text-gray-800 dark:text-white')
                                                </span>
                                            </button>
                                        @endif
                                    </div>
                                    <div class="flex flex-col">
                                        <span class="font-semibold">{{ $item['name'] ?? '' }}</span>
                                    </div>
                                </div>
                            </td>
                            <td class="index-table-td">
                                @if (!empty($item['request_allowed']))
                                    <span class="text-lg font-semibold text-green-600">+</span>
                                @else
                                    <span class="text-lg font-semibold text-red-600">-</span>
                                @endif
                            </td>
                            <td class="index-table-td font-semibold">
                                {{ $item['code'] ?? '-' }}
                            </td>
                            <td class="index-table-td">
                                @if (!empty($item['is_active']))
                                    <span class="badge-green">
                                        {{ __('forms.status.active') }}
                                    </span>
                                @else
                                    <span class="badge-red">
                                        {{ __('forms.status.non_active') }}
                                    </span>
                                @endif
                            </td>
                        </tr>

                        {{-- Services directly under this item --}}
                        @if ($hasServices)
                            @foreach ($item['services'] as $service)
                                <tr x-cloak
                                    class="index-table-tr bg-blue-50/50 dark:bg-blue-900/20"
                                    x-show="openIds['{{ $itemId }}']"
                                    x-transition:enter="transition ease-out duration-150"
                                    x-transition:enter-start="opacity-0"
                                    x-transition:enter-end="opacity-100"
                                >
                                    <td class="index-table-td-primary">
                                        <div class="flex items-start gap-3">
                                            <div class="mt-1 flex-shrink-0 w-6 ml-6">
                                                @icon('chevron-right', 'w-3 h-3 text-gray-400 mt-1')
                                            </div>
                                            <div class="flex flex-col">
                                                <span>{{ $service['name'] ?? '' }}</span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="index-table-td">
                                        @if (!empty($service['request_allowed']))
                                            <span class="text-lg font-semibold text-green-600">+</span>
                                        @else
                                            <span class="text-lg font-semibold text-red-600">-</span>
                                        @endif
                                    </td>
                                    <td class="index-table-td font-semibold">
                                        {{ $service['code'] ?? '-' }}
                                    </td>
                                    <td class="index-table-td">
                                        @if (!empty($service['is_active']))
                                            <span class="badge-green">
                                                {{ __('forms.status.active') }}
                                            </span>
                                        @else
                                            <span class="badge-red">
                                                {{ __('forms.status.non_active') }}
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        @endif

                        {{-- Groups under this item --}}
                        @if ($hasGroups)
                            @foreach ($item['groups'] as $group)
                                @php
                                    $groupId = $group['id'] ?? ('group-' . $loop->parent->index . '-' . $loop->index);
                                    $groupHasGroups = !empty($group['groups']);
                                    $groupHasServices = !empty($group['services']);
                                    $groupHasChildren = $groupHasGroups || $groupHasServices;
                                @endphp

                                {{-- Group row --}}
                                <tr x-cloak
                                    class="index-table-tr bg-gray-50/50 dark:bg-gray-800/50"
                                    x-show="openIds['{{ $itemId }}']"
                                    x-transition:enter="transition ease-out duration-150"
                                    x-transition:enter-start="opacity-0"
                                    x-transition:enter-end="opacity-100"
                                >
                                    <td class="index-table-td-primary">
                                        <div class="flex items-start gap-3">
                                            <div class="mt-1 flex-shrink-0 w-6 ml-6">
                                                @if ($groupHasChildren)
                                                    <button type="button"
                                                            @click="openIds['{{ $groupId }}'] = !openIds['{{ $groupId }}']"
                                                            class="p-0.5 rounded hover:bg-gray-100 dark:hover:bg-gray-700 inline-block"
                                                            :aria-expanded="!!openIds['{{ $groupId }}']"
                                                    >
                                                        <span class="inline-block transition-transform duration-200"
                                                              :class="openIds['{{ $groupId }}'] ? 'rotate-0' : '-rotate-90'"
                                                        >
                                                            @icon('chevron-down', 'w-4 h-4 text-gray-600 dark:text-gray-400')
                                                        </span>
                                                    </button>
                                                @else
                                                    @icon('chevron-right', 'w-3 h-3 text-gray-400 mt-1')
                                                @endif
                                            </div>
                                            <div class="flex flex-col">
                                                <span class="font-medium">{{ $group['name'] ?? '' }}</span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="index-table-td">
                                        @if (!empty($group['request_allowed']))
                                            <span class="text-lg font-semibold text-green-600">+</span>
                                        @else
                                            <span class="text-lg font-semibold text-red-600">-</span>
                                        @endif
                                    </td>
                                    <td class="index-table-td font-semibold">
                                        {{ $group['code'] ?? '-' }}
                                    </td>
                                    <td class="index-table-td">
                                        @if (!empty($group['is_active']))
                                            <span class="badge-green">
                                                {{ __('forms.status.active') }}
                                            </span>
                                        @else
                                            <span class="badge-red">
                                                {{ __('forms.status.non_active') }}
                                            </span>
                                        @endif
                                    </td>
                                </tr>

                                {{-- Subgroups --}}
                                @if ($groupHasGroups)
                                    @foreach ($group['groups'] as $subgroup)
                                        @php
                                            $subgroupId = $subgroup['id'] ?? ('subgroup-' . $loop->parent->parent->index . '-' . $loop->parent->index . '-' . $loop->index);
                                            $subgroupHasServices = !empty($subgroup['services']);
                                        @endphp

                                        {{-- Subgroup row --}}
                                        <tr x-cloak
                                            class="index-table-tr bg-yellow-50/50 dark:bg-yellow-900/20"
                                            x-show="openIds['{{ $itemId }}'] && openIds['{{ $groupId }}']"
                                            x-transition:enter="transition ease-out duration-150"
                                            x-transition:enter-start="opacity-0"
                                            x-transition:enter-end="opacity-100"
                                        >
                                            <td class="index-table-td-primary">
                                                <div class="flex items-start gap-3">
                                                    <div class="mt-1 flex-shrink-0 w-6 ml-12">
                                                        @if ($subgroupHasServices)
                                                            <button type="button"
                                                                    @click="openIds['{{ $subgroupId }}'] = !openIds['{{ $subgroupId }}']"
                                                                    class="p-0.5 rounded hover:bg-gray-100 dark:hover:bg-gray-700 inline-block"
                                                                    :aria-expanded="!!openIds['{{ $subgroupId }}']"
                                                            >
                                                                <span
                                                                    class="inline-block transition-transform duration-200"
                                                                    :class="openIds['{{ $subgroupId }}'] ? 'rotate-0' : '-rotate-90'"
                                                                >
                                                                    @icon('chevron-down', 'w-4 h-4 text-gray-600 dark:text-gray-400')
                                                                </span>
                                                            </button>
                                                        @else
                                                            @icon('chevron-right', 'w-3 h-3 text-gray-400 mt-1')
                                                        @endif
                                                    </div>
                                                    <div class="flex flex-col">
                                                        <span>{{ $subgroup['name'] ?? '' }}</span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="index-table-td">
                                                @if (!empty($subgroup['request_allowed']))
                                                    <span class="text-lg font-semibold text-green-600">+</span>
                                                @else
                                                    <span class="text-lg font-semibold text-red-600">-</span>
                                                @endif
                                            </td>
                                            <td class="index-table-td font-semibold">
                                                {{ $subgroup['code'] ?? '-' }}
                                            </td>
                                            <td class="index-table-td">
                                                @if (!empty($subgroup['is_active']))
                                                    <span class="badge-green">
                                                        {{ __('forms.status.active') }}
                                                    </span>
                                                @else
                                                    <span class="badge-red">
                                                        {{ __('forms.status.non_active') }}
                                                    </span>
                                                @endif
                                            </td>
                                        </tr>

                                        {{-- Services under subgroup --}}
                                        @if ($subgroupHasServices)
                                            @foreach ($subgroup['services'] as $service)
                                                <tr x-cloak
                                                    class="index-table-tr bg-green-50/50 dark:bg-green-900/20"
                                                    x-show="openIds['{{ $itemId }}'] && openIds['{{ $groupId }}'] && openIds['{{ $subgroupId }}']"
                                                    x-transition:enter="transition ease-out duration-150"
                                                    x-transition:enter-start="opacity-0"
                                                    x-transition:enter-end="opacity-100"
                                                >
                                                    <td class="index-table-td-primary">
                                                        <div class="flex items-start gap-3">
                                                            <div class="mt-1 flex-shrink-0 w-6 ml-18">
                                                                @icon('chevron-right', 'w-3 h-3 text-gray-400 mt-1')
                                                            </div>
                                                            <div class="flex flex-col">
                                                                <span>{{ $service['name'] ?? '' }}</span>
                                                                @if (!empty($service['category']))
                                                                    <span
                                                                        class="text-xs text-gray-600 bg-gray-100 dark:bg-gray-700 px-1 rounded inline-block w-fit mt-1">
                                                                        {{ $this->dictionaries['SERVICE_CATEGORY'][$service['category']] }}
                                                                    </span>
                                                                @endif
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td class="index-table-td">
                                                        @if (!empty($service['request_allowed']))
                                                            <span class="text-lg font-semibold text-green-600">+</span>
                                                        @else
                                                            <span class="text-lg font-semibold text-red-600">-</span>
                                                        @endif
                                                    </td>
                                                    <td class="index-table-td font-semibold">
                                                        {{ $service['code'] ?? '-' }}
                                                    </td>
                                                    <td class="index-table-td">
                                                        @if (!empty($service['is_active']))
                                                            <span class="badge-green">
                                                                {{ __('forms.status.active') }}
                                                            </span>
                                                        @else
                                                            <span class="badge-red">
                                                                {{ __('forms.status.non_active') }}
                                                            </span>
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                        @endif
                                    @endforeach
                                @endif

                                {{-- Services directly under group --}}
                                @if ($groupHasServices && !$groupHasGroups)
                                    @foreach ($group['services'] as $service)
                                        <tr x-cloak
                                            class="index-table-tr bg-green-50/50 dark:bg-green-900/20"
                                            x-show="openIds['{{ $itemId }}'] && openIds['{{ $groupId }}']"
                                            x-transition:enter="transition ease-out duration-150"
                                            x-transition:enter-start="opacity-0"
                                            x-transition:enter-end="opacity-100"
                                        >
                                            <td class="index-table-td-primary">
                                                <div class="flex items-start gap-3">
                                                    <div class="mt-1 flex-shrink-0 w-6 ml-12">
                                                        @icon('chevron-right', 'w-3 h-3 text-gray-400 mt-1')
                                                    </div>
                                                    <div class="flex flex-col">
                                                        <span>{{ $service['name'] ?? '' }}</span>
                                                        @if (!empty($service['category']))
                                                            <span
                                                                class="text-xs text-gray-600 bg-gray-100 dark:bg-gray-700 px-1 rounded inline-block w-fit mt-1">
                                                                {{ $service['category'] }}
                                                            </span>
                                                        @endif
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="index-table-td">
                                                @if (!empty($service['request_allowed']))
                                                    <span class="text-lg font-semibold text-green-600">+</span>
                                                @else
                                                    <span class="text-lg font-semibold text-red-600">-</span>
                                                @endif
                                            </td>
                                            <td class="index-table-td font-semibold">
                                                {{ $service['code'] ?? '-' }}
                                            </td>
                                            <td class="index-table-td">
                                                @if (!empty($service['is_active']))
                                                    <span class="badge-green">
                                                        {{ __('forms.status.active') }}
                                                    </span>
                                                @else
                                                    <span class="badge-red">
                                                        {{ __('forms.status.non_active') }}
                                                    </span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                @endif
                            @endforeach
                        @endif
                    @empty
                    @endforelse
                    </tbody>
                </table>
            </div>

            @if ($services->isEmpty())
                <fieldset class="fieldset !mx-auto mt-8 shift-content">
                    <legend class="legend relative -top-5">
                        @icon('nothing-found', 'w-28 h-28')
                    </legend>
                    <div class="p-4 rounded-lg bg-blue-100 flex items-start mb-4">
                        <div class="flex items-start gap-3">
                            <div class="flex-shrink-0 mt-0.5">
                                @icon('alert-circle', 'w-5 h-5 text-blue-500 mr-3 mt-1')
                            </div>
                            <div class="flex-1">
                                <p class="font-bold text-blue-800">
                                    {{ __('forms.nothing_found') }}
                                </p>
                                <p class="text-sm text-blue-600">
                                    {{ __('forms.changing_search_parameters') }}
                                </p>
                            </div>
                        </div>
                    </div>
                </fieldset>
            @endif
        </div>
    </div>

    <div class="mt-8 pl-3.5 pb-8 lg:pl-8 2xl:pl-5">
        {{ $this->services->links() }}
    </div>

    <x-forms.loading />
    <livewire:components.x-message :key="time()" />
</div>
