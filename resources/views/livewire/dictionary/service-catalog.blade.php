<div>
    <x-header-navigation x-data="{ showFilter: false }" class="breadcrumb-form">
        <x-slot name="title">
            {{ __('dictionaries.service_catalog.title') }}
        </x-slot>

        <x-slot name="navigation">
            <div class="flex flex-col -my-4" x-data="{ showFilter: false }">

                <div class="flex mb-4 flex-col w-full">
                    <div class="w-full lg:w-96">
                        <label
                            for="serviceSearchDropdown"
                            class="text-sm font-medium text-gray-900 dark:text-white block mb-2 flex items-center gap-1"
                        >
                            @icon('search-outline', 'w-4.5 h-4.5')
                            <span>{{ __('dictionaries.service_catalog.search_services') }}</span>
                        </label>

                        <div
                            class="form-group group w-full"
                            x-data="{ open: false }"
                        >
                            <div class="relative">
                                <div
                                    class="absolute inset-y-0 left-0 flex items-center ps-0 pointer-events-none"
                                >
                                </div>

                                <input
                                    type="text"
                                    id="serviceSearchDropdown"
                                    class="input peer w-full cursor-pointer text-gray-500 dark:text-gray-400 ps-7 pr-9"
                                    placeholder=" "
                                    wire:model="search"
                                    @click="open = !open"
                                    readonly
                                />
                                <label
                                    for="serviceSearchDropdown"
                                    class="label"
                                >
                                    {{ __('dictionaries.service_catalog.search_placeholder') }}
                                </label>
                                @icon(
                                    'chevron-down',
                                    'w-3.5 h-3.5 absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 dark:text-gray-400 pointer-events-none'
                                )

                                <div x-show="open"
                                     @click.away="open = false"
                                     x-transition
                                     x-cloak
                                     class="absolute z-10 mt-2 w-full bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg shadow-lg"
                                >
                                    <ul class="py-2 px-3 space-y-1 text-sm text-gray-700 dark:text-gray-200">
                                        @foreach($searchSuggestions as $suggestion)
                                            <li>
                                                <button type="button"
                                                        wire:click="selectSearchSuggestion({{ $loop->index }})"
                                                        @click="open = false"
                                                        class="flex items-center gap-2 w-full text-left py-2.5 px-3 rounded-md hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors"
                                                >
                                                    <span>{{ $suggestion }}</span>
                                                </button>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mb-4 mt-6 flex flex-col gap-2 w-full sm:flex-row">
                    <button
                        type="button"
                        wire:click="search"
                        class="flex items-center gap-2 button-primary"
                    >
                        @icon('search', 'w-4 h-4')
                        <span>{{ __('forms.search') }}</span>
                    </button>
                    <button
                        type="button"
                        wire:click="resetFilters"
                        class="button-primary-outline-red me-0"
                    >
                        {{ __('forms.reset_all_filters') }}
                    </button>
                    <button
                        type="button"
                        class="button-minor flex items-center gap-2"
                        @click="showFilter = !showFilter"
                    >
                        @icon('adjustments', 'w-4 h-4')
                        <span>{{ __('forms.additional_search_parameters') }}</span>
                    </button>
                </div>

                <div
                    x-cloak
                    x-show="showFilter"
                    x-transition
                    class="grid grid-cols-1 sm:grid-cols-2 gap-4 md:gap-6 w-full mt-4 mb-9 md:mb-5"
                >
                    <div class="form-group group">
                        <select
                            wire:model="serviceCategory"
                            id="filterServiceCategory"
                            class="peer input-select w-full"
                        >
                            <option value="" selected>{{ __('forms.select') }}</option>
                            @foreach($serviceCategories as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <label
                            for="filterServiceCategory"
                            class="label peer-focus:text-blue-600 peer-valid:text-blue-600"
                        >
                            {{ __('dictionaries.service_catalog.service_category') }}
                        </label>
                    </div>
                    <div class="form-group group">
                        <select
                            wire:model="serviceGroupActive"
                            id="filterServiceGroupActive"
                            class="peer input-select w-full"
                        >
                            <option value="" selected>{{ __('forms.select') }}</option>
                            <option value="1">{{ __('forms.yes') }}</option>
                            <option value="0">{{ __('forms.no') }}</option>
                        </select>
                        <label
                            for="filterServiceGroupActive"
                            class="label peer-focus:text-blue-600 peer-valid:text-blue-600"
                        >
                            {{ __('dictionaries.service_catalog.service_group_active') }}
                        </label>
                    </div>
                    <div class="form-group group">
                        <select
                            wire:model="serviceActive"
                            id="filterServiceActive"
                            class="peer input-select w-full"
                        >
                            <option value="" selected>{{ __('forms.select') }}</option>
                            <option value="1">{{ __('forms.yes') }}</option>
                            <option value="0">{{ __('forms.no') }}</option>
                        </select>
                        <label
                            for="filterServiceActive"
                            class="label peer-focus:text-blue-600 peer-valid:text-blue-600"
                        >
                            {{ __('dictionaries.service_catalog.service_active') }}
                        </label>
                    </div>
                    <div class="form-group group">
                        <select
                            wire:model="allowedForEn"
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

                    <tbody
                        x-data="{ openIds: {} }"
                    >
                    @forelse($services as $service)
                        @php
                            $serviceId = $service['id'] ?? ('service-' . $loop->index);
                            $hasChildren = !empty($service['children']) && count($service['children']) > 0;
                        @endphp
                        <tr class="index-table-tr">
                            <td class="index-table-td-primary">
                                <div class="flex items-start gap-3">
                                    <div class="mt-1 flex-shrink-0 w-6">
                                        @if ($hasChildren)
                                            <button
                                                type="button"
                                                @click="openIds['{{ $serviceId }}'] = !openIds['{{ $serviceId }}']"
                                                class="p-0.5 rounded hover:bg-gray-100 dark:hover:bg-gray-700 inline-block"
                                                :aria-expanded="!!openIds['{{ $serviceId }}']"
                                            >
                                                <span
                                                    class="inline-block transition-transform duration-200"
                                                    :class="openIds['{{ $serviceId }}'] ? 'rotate-0' : '-rotate-90'"
                                                >
                                                    @icon('chevron-down', 'w-4 h-4 text-gray-800 dark:text-white')
                                                </span>
                                            </button>
                                        @endif
                                    </div>
                                    <div class="flex flex-col">
                                        <span>{{ $service['name'] ?? '' }}</span>
                                        @if (!empty($service['id']))
                                            <span class="text-xs text-gray-500">
                                                id {{ $service['id'] }}
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td class="index-table-td">
                                @if (!empty($service['allowed_for_en']))
                                    <span class="text-lg font-semibold">+</span>
                                @endif
                            </td>
                            <td class="index-table-td font-semibold">
                                {{ $service['code'] ?? '-' }}
                            </td>
                            <td class="index-table-td">
                                @php
                                    $status = $service['status'] ?? 'active';
                                @endphp
                                @if ($status === 'active')
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
                        @if ($hasChildren)
                            @foreach ($service['children'] as $child)
                                <tr
                                    class="index-table-tr bg-gray-50/50 dark:bg-gray-800/50"
                                    x-show="openIds['{{ $serviceId }}']"
                                    x-cloak
                                    x-transition:enter="transition ease-out duration-150"
                                    x-transition:enter-start="opacity-0"
                                    x-transition:enter-end="opacity-100"
                                >
                                    <td class="index-table-td-primary">
                                        <div class="flex items-start gap-3">
                                            <div class="mt-1 flex-shrink-0 w-6"></div>
                                            <div class="flex flex-col">
                                                <span>{{ $child['name'] ?? '' }}</span>
                                                @if (!empty($child['id'] ?? null))
                                                    <span class="text-xs text-gray-500">
                                                        id {{ $child['id'] }}
                                                    </span>
                                                @endif
                                            </div>
                                        </div>
                                    </td>
                                    <td class="index-table-td"></td>
                                    <td class="index-table-td font-semibold">
                                        {{ $child['code'] ?? '-' }}
                                    </td>
                                    <td class="index-table-td">
                                        @if (($child['status'] ?? 'active') === 'active')
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
        {{--{{ $dictionary->links() }}--}}
    </div>

    <x-forms.loading />
    <livewire:components.x-message :key="time()" />
</div>
