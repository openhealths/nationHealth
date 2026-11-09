@php
    // A page opens the catalog once per picker, so every field carries the id of its own component
    $componentId = $this->getId();
@endphp

<div class="-my-4 flex flex-col" x-data="{ showFilter: false }">
    <div class="mb-4 flex w-full flex-col">
        <div class="w-full lg:w-96">
            <label
                for="serviceSearch{{ $componentId }}"
                class="mb-2 block flex items-center gap-1 text-sm font-medium text-gray-900 dark:text-white"
            >
                @icon('search-outline', 'w-4.5 h-4.5')
                <span>{{ __('dictionaries.service_catalog.search_services') }}</span>
            </label>

            <div class="form-group group w-full">
                <input
                    type="text"
                    id="serviceSearch{{ $componentId }}"
                    class="input peer w-full"
                    placeholder=" "
                    wire:model="filterCodeOrName"
                    wire:keydown.enter.prevent="search"
                />

                <label for="serviceSearch{{ $componentId }}" class="label">
                    {{ __('dictionaries.service_catalog.search_placeholder') }}
                </label>
            </div>
        </div>
    </div>

    <div class="mt-6 mb-4 flex w-full flex-col gap-2 sm:flex-row">
        <button type="button" wire:click="search" class="button-primary flex items-center gap-2">
            @icon('search', 'w-4 h-4')
            <span>{{ __('forms.search') }}</span>
        </button>

        <button type="button" wire:click="resetFilters" class="button-primary-outline-red me-0">
            {{ __('forms.reset_all_filters') }}
        </button>

        <button type="button" class="button-minor flex items-center gap-2" @click="showFilter = ! showFilter">
            @icon('adjustments', 'w-4 h-4')
            <span>{{ __('forms.additional_search_parameters') }}</span>
        </button>
    </div>

    <div
        x-cloak
        x-show="showFilter"
        x-transition
        class="mt-4 mb-9 grid w-full grid-cols-1 gap-4 sm:grid-cols-2 md:mb-5 md:gap-6"
    >
        <div class="form-group group">
            <select
                wire:model="filterCategory"
                id="filterServiceCategory{{ $componentId }}"
                class="peer input-select w-full"
            >
                <option value="">{{ __('forms.select') }}</option>

                @foreach ($this->dictionaries['SERVICE_CATEGORY'] ?? [] as $key => $value)
                    @if ($allowedCategories === [] || in_array($key, $allowedCategories, true))
                        <option value="{{ $key }}">{{ $value }}</option>
                    @endif
                @endforeach
            </select>

            <label for="filterServiceCategory{{ $componentId }}" class="label">
                {{ __('dictionaries.service_catalog.service_category') }}
            </label>
        </div>

        <div class="form-group group">
            <select
                wire:model="filterGroupIsActive"
                id="filterServiceGroupActive{{ $componentId }}"
                class="peer input-select w-full"
            >
                <option value="">{{ __('forms.select') }}</option>
                <option value="1">{{ __('forms.yes') }}</option>
                <option value="0">{{ __('forms.no') }}</option>
            </select>

            <label for="filterServiceGroupActive{{ $componentId }}" class="label">
                {{ __('dictionaries.service_catalog.service_group_active') }}
            </label>
        </div>

        <div class="form-group group">
            <select
                wire:model="filterServiceIsActive"
                id="filterServiceActive{{ $componentId }}"
                class="peer input-select w-full"
            >
                <option value="">{{ __('forms.select') }}</option>
                <option value="1">{{ __('forms.yes') }}</option>
                <option value="0">{{ __('forms.no') }}</option>
            </select>

            <label for="filterServiceActive{{ $componentId }}" class="label">
                {{ __('dictionaries.service_catalog.service_active') }}
            </label>
        </div>

        {{-- Picking a service for a referral already keeps only the services allowed in it --}}
        @unless ($requestAllowedOnly)
            <div class="form-group group">
                <select
                    wire:model="filterRequestAllowed"
                    id="filterAllowedForEn{{ $componentId }}"
                    class="peer input-select w-full"
                >
                    <option value="">{{ __('forms.select') }}</option>
                    <option value="1">{{ __('forms.yes') }}</option>
                    <option value="0">{{ __('forms.no') }}</option>
                </select>

                <label for="filterAllowedForEn{{ $componentId }}" class="label">
                    {{ __('dictionaries.service_catalog.allowed_for_en') }}
                </label>
            </div>
        @endunless
    </div>
</div>
