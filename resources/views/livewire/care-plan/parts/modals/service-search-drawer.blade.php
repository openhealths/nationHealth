{{-- Service Search Drawer Overlay --}}
<div x-show="showServiceSearchDrawer"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     x-cloak
     @click="showServiceSearchDrawer = false"
     aria-controls="service-search-drawer-right"
     class="fixed top-0 right-0 h-screen pt-20 w-4/5 bg-gray-900/50"
     style="z-index: 44;"
></div>

{{-- Service Search Drawer --}}
<div id="service-search-drawer-right"
     x-show="showServiceSearchDrawer"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="translate-x-full"
     x-transition:enter-end="translate-x-0"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="translate-x-0"
     x-transition:leave-end="translate-x-full"
     x-cloak
     class="fixed top-0 right-0 h-screen pt-20 p-4 overflow-y-auto bg-white dark:bg-gray-800 shadow-2xl"
     style="z-index: 45; width: calc(80% - 30px);"
     tabindex="-1"
     aria-labelledby="service-search-drawer-label"
     x-data="{ showFilter: false }"
>
    <h3 class="modal-header" id="service-search-drawer-label">
        {{ __('care-plan.search_service') }}
    </h3>

    {{-- Search Input --}}
    <div class="mb-4">
        <div class="relative">
            <div class="absolute inset-y-0 start-0 flex items-center ps-3 pointer-events-none">
                @icon('search-outline', 'w-5 h-5 text-gray-500')
            </div>
            <input type="text"
                   class="input peer ps-10 w-full"
                   placeholder="Киснева терапія"
            />
        </div>
    </div>

    {{-- Action Buttons --}}
    <div class="flex flex-wrap gap-2 mb-6">
        <button type="button" class="button-primary flex items-center gap-2">
            @icon('search', 'w-4 h-4')
            <span>{{ __('forms.search') }}</span>
        </button>
        <button type="button" class="button-primary-outline-red">
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
    <div class="overflow-x-auto mb-6">
        <table class="w-full text-sm text-left">
            <thead class="thead-input">
                <tr>
                    <th scope="col" class="px-4 py-3 font-medium">{{ __('care-plan.name') }}</th>
                    <th scope="col" class="px-4 py-3 font-medium">{{ __('care-plan.allowed_in_em_short') }}</th>
                    <th scope="col" class="px-4 py-3 font-medium">{{ __('care-plan.code') }}</th>
                    <th scope="col" class="px-4 py-3 font-medium">{{ __('care-plan.status') }}</th>
                    <th scope="col" class="px-4 py-3 font-medium">{{ __('care-plan.action') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="px-4 py-3">
                        <div>
                            <p class="font-medium text-gray-500 dark:text-white">Направлення до спеціаліста</p>
                            <p class="text-xs text-gray-500">e1230-0f3</p>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-gray-500">+</td>
                    <td class="px-4 py-3"></td>
                    <td class="px-4 py-3">
                        <span class="px-2 py-1 text-xs font-medium rounded-full bg-green-100 text-green-800">{{ __('care-plan.active') }}</span>
                    </td>
                    <td class="px-4 py-3"></td>
                </tr>
                <tr>
                    <td class="px-4 py-3">
                        <span class="font-medium text-gray-500 dark:text-white">Лікувально-діагностичні процедури</span>
                    </td>
                    <td class="px-4 py-3 text-gray-500">+</td>
                    <td class="px-4 py-3"></td>
                    <td class="px-4 py-3">
                        <span class="px-2 py-1 text-xs font-medium rounded-full bg-gray-100 text-gray-800">{{ __('care-plan.inactive') }}</span>
                    </td>
                    <td class="px-4 py-3"></td>
                </tr>
                <tr>
                    <td class="px-4 py-3">
                        <span class="font-medium text-gray-500 dark:text-white">Діагностичні процедури</span>
                    </td>
                    <td class="px-4 py-3 text-gray-500">+</td>
                    <td class="px-4 py-3"></td>
                    <td class="px-4 py-3">
                        <span class="px-2 py-1 text-xs font-medium rounded-full bg-green-100 text-green-800">{{ __('care-plan.active') }}</span>
                    </td>
                    <td class="px-4 py-3"></td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="mt-6">
        <button type="button"
                class="button-minor"
                aria-controls="service-search-drawer-right"
                @click="showServiceSearchDrawer = false"
        >
            {{ __('forms.cancel') }}
        </button>
    </div>
</div>
