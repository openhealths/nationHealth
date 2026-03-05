<div>
    <x-header-navigation x-data="{ showFilter: false }" class="breadcrumb-form">
        <x-slot name="title">
            {{ __('drugs-list.title') }}
        </x-slot>

        <x-slot name="navigation">
            <div class="flex flex-col gap-4" x-data="{ showFilter: false }">
                <div class="flex flex-col gap-4 max-w-sm">
                    <div class="form-group group" x-data="{ open: false, selected: '' }">
                        <label for="programDropdown" class="default-label mb-2">
                            {{ __('drugs-list.program_label_required') }}
                        </label>
                        <div class="relative">
                            <input type="text"
                                   id="programDropdown"
                               class="input w-full cursor-pointer text-gray-500 dark:text-gray-400 pr-9 py-2.5 px-0 text-sm bg-transparent border-0 border-b-2 border-gray-300 dark:border-gray-600 focus:border-blue-600 dark:focus:border-blue-500 focus:outline-none focus:ring-0"
                                   placeholder="{{ __('drugs-list.program_placeholder') }}"
                                   @click="open = !open"
                                   :value="selected ? '{{ __('drugs-list.prescription_medication') }}' : ''"
                                   readonly
                            />
                            @icon('chevron-down', 'w-3.5 h-3.5 absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 dark:text-gray-400 pointer-events-none')
                            <div x-show="open"
                                 @click.away="open = false"
                                 x-transition:enter="transition ease-out duration-100"
                                 x-transition:enter-start="transform opacity-0 scale-95"
                                 x-transition:enter-end="transform opacity-100 scale-100"
                                 x-transition:leave="transition ease-in duration-75"
                                 x-transition:leave-start="transform opacity-100 scale-100"
                                 x-transition:leave-end="transform opacity-0 scale-95"
                                 x-cloak
                                 class="relative mt-2 w-full bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg shadow-lg"
                            >
                                <ul class="py-2 px-3 space-y-1 text-sm text-gray-700 dark:text-gray-200">
                                    <li>
                                        <button type="button"
                                                @click="selected = 'prescription'; open = false"
                                                class="flex items-center gap-2 w-full text-left py-2.5 px-3 rounded-md hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors"
                                        >
                                            @icon('question-mark-circle', 'w-3.5 h-3.5 text-gray-500 dark:text-gray-400 flex-shrink-0 shrink-0')
                                            <span>{{ __('drugs-list.prescription_medication') }}</span>
                                        </button>
                                    </li>
                                    <li>
                                        <button type="button"
                                                @click="selected = 'prescription_2'; open = false"
                                                class="flex items-center gap-2 w-full text-left py-2.5 px-3 rounded-md hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors"
                                        >
                                            @icon('question-mark-circle', 'w-3.5 h-3.5 text-gray-500 dark:text-gray-400 flex-shrink-0 shrink-0')
                                            <span>{{ __('drugs-list.prescription_medication') }}</span>
                                        </button>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="form-group group">
                        <label for="drugSearch" class="default-label mb-2">
                            {{ __('drugs-list.search_title') }}
                        </label>
                        <div class="relative">
                            <div class="absolute inset-y-0 start-0 flex items-center ps-3 pointer-events-none">
                                @icon('search-outline', 'w-4 h-4 text-gray-500 dark:text-gray-400')
                            </div>
                            <input type="text"
                                   id="drugSearch"
                                   class="input w-full ps-9"
                                   placeholder="{{ __('drugs-list.search_placeholder') }}"
                                   wire:model="search"
                                   autocomplete="off"
                            />
                        </div>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button type="button"
                            wire:click="search"
                            class="button-primary flex items-center gap-2"
                    >
                        @icon('search', 'w-4 h-4')
                        <span>{{ __('forms.search') }}</span>
                    </button>
                    <button type="button"
                            wire:click="resetFilters"
                            class="button-primary-outline-red"
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

                <div x-cloak x-show="showFilter" x-transition class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-group group">
                        <input wire:model="inn"
                               type="text"
                               id="filterInn"
                               class="input peer"
                               placeholder=" "
                               autocomplete="off"
                        />
                        <label for="filterInn" class="label peer-focus:text-blue-600 peer-valid:text-blue-600">
                            {{ __('drugs-list.inn_name') }}
                        </label>
                    </div>
                    <div class="form-group group">
                        <select wire:model="atcCode"
                                id="filterAtc"
                                class="peer input-select w-full"
                        >
                            <option value="" selected>{{ __('drugs-list.atc_placeholder') }}</option>
                        </select>
                        <label for="filterAtc" class="label peer-focus:text-blue-600 peer-valid:text-blue-600">
                            {{ __('drugs-list.atc_code') }}
                        </label>
                    </div>
                    <div class="form-group group">
                        <select wire:model="dosageForm"
                                id="filterDosageForm"
                                class="peer input-select w-full"
                        >
                            <option value="" selected>{{ __('forms.select') }}</option>
                            <option value="tablets">{{ __('treatment-plan.tablets') }}</option>
                        </select>
                        <label for="filterDosageForm" class="label peer-focus:text-blue-600 peer-valid:text-blue-600">
                            {{ __('drugs-list.dosage_form') }}
                        </label>
                    </div>
                    <div class="form-group group">
                        <select wire:model="prescriptionFormType"
                                id="filterPrescriptionType"
                                class="peer input-select w-full"
                        >
                            <option value="" selected>{{ __('drugs-list.type_placeholder') }}</option>
                        </select>
                        <label for="filterPrescriptionType" class="label peer-focus:text-blue-600 peer-valid:text-blue-600">
                            {{ __('drugs-list.prescription_form_type_filter') }}
                        </label>
                    </div>
                </div>
            </div>
        </x-slot>
    </x-header-navigation>

    <section class="shift-content pl-3.5 mt-6 max-w-[1280px]">
        <fieldset class="fieldset p-6 sm:p-8">
            <legend class="legend">
                {{ __('drugs-list.details_title') }}
            </legend>

            <div class="space-y-2 text-gray-900 dark:text-gray-100">
                <p>{{ __('drugs-list.funding_source') }}</p>
                <p>{{ __('drugs-list.prescription_form_type') }}</p>
                <p>{{ __('drugs-list.treatment_plan_required') }}</p>
                <p>{{ __('drugs-list.allowed_user_types') }}</p>
                <p>{{ __('drugs-list.allowed_specialties') }}</p>
                <p>{{ __('drugs-list.same_inn_course') }}</p>
                <p>{{ __('drugs-list.max_course_duration') }}</p>
                <p>{{ __('drugs-list.no_declaration_required_patient') }}</p>
                <p>{{ __('drugs-list.no_declaration_required_facility') }}</p>
                <p>{{ __('drugs-list.partial_redemption') }}</p>
                <p>{{ __('drugs-list.patient_notifications_off') }}</p>
                <p>{{ __('drugs-list.allowed_patient_categories') }}</p>
            </div>
        </fieldset>

        <div class="mt-8 pl-3.5 pb-8 lg:pl-8 2xl:pl-5">
            {{--{{ $dictionary->links() }}--}}
        </div>
    </section>

    <x-forms.loading />
    <livewire:components.x-message :key="time()" />
</div>
