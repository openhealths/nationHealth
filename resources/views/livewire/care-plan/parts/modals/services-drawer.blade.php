{{-- Services Drawer
     Alpine x-teleport only moves template.content.firstElementChild — one root required.
     Backdrop + panel must live in one wrapper or the panel never reaches body and the
     backdrop covers the page alone. --}}
<template x-teleport="body">
    <div x-show="showServiceDrawer"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         x-cloak
         class="fixed inset-0"
         style="z-index: 39;"
         role="dialog"
         aria-modal="true"
         aria-labelledby="services-drawer-label"
    >
        {{-- Full-viewport scrim: dims main app; drawer panel stacks above (z-10) --}}
        <div class="absolute inset-0 bg-gray-900/50"
             aria-hidden="true"
             @click="showServiceDrawer = false"
        ></div>

        <div id="services-drawer-right"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="translate-x-full"
             x-transition:enter-end="translate-x-0"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="translate-x-0"
             x-transition:leave-end="translate-x-full"
             class="absolute top-0 right-0 z-10 h-screen pt-20 p-4 overflow-y-auto bg-white w-4/5 dark:bg-gray-800 shadow-2xl"
             tabindex="-1"
        >
        <h3 class="modal-header" id="services-drawer-label">
            @if(isset($activityForm['id']) && $activityForm['id'])
                {{ __('care-plan.edit_service_prescription') }}
            @else
                {{ __('care-plan.new_service_prescription') }}
            @endif
        </h3>

        {{-- Content --}}
        <form wire:submit.prevent="saveActivity">
            {{-- Main Data Section --}}
            <fieldset class="fieldset">
                <legend class="legend">
                    {{ __('care-plan.main_data') }}
                </legend>

                {{-- Service and Program --}}
                <div class="form-row-3">
                    <div class="form-group group">
                        <label for="service" class="label">
                            {{ __('care-plan.service') }}*
                        </label>
                        <div class="relative">
                            <button type="button"
                                    class="input-select peer pr-12 w-full text-left text-gray-500"
                                    aria-controls="service-search-drawer-right"
                                    @click="showServiceSearchDrawer = true"
                            >
                                {{ __('care-plan.select_service') }}
                            </button>
                            <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                                <svg class="w-4 h-4" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <path stroke="currentColor" stroke-linejoin="round" stroke-width="2" d="M9 8v3a1 1 0 0 1-1 1H5m11 4h2a1 1 0 0 0 1-1V5a1 1 0 0 0-1-1h-7a1 1 0 0 0-1 1v1m4 3v10a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1v-7.13a1 1 0 0 1 .24-.65L7.7 8.35A1 1 0 0 1 8.46 8H13a1 1 0 0 1 1 1Z"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                    <div class="form-group group">
                        <label for="program" class="label">
                            {{ __('care-plan.program') }}
                        </label>
                        <select id="program"
                                name="program"
                                class="input-select peer"
                        >
                            <option selected value="">{{ __('care-plan.state_financial_guarantees') }}</option>
                        </select>
                    </div>
                </div>

                {{-- Quantity, Start Date, Start Time --}}
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-4">
                    <div class="form-group group">
                        <label for="quantity" class="label">
                            {{ __('care-plan.quantity') }}
                        </label>
                        <div class="flex gap-2">
                            <input type="number"
                                   id="quantity"
                                   class="input peer w-full"
                                   wire:model="activityForm.quantity"
                            >
                            <select class="input-select peer w-20" wire:model="activityForm.quantity_system">
                                <option value="units">{{ __('care-plan.units') }}</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group group">
                        <label class="label">
                            {{ __('care-plan.start_date') }}:
                        </label>
                        <div class="relative">
                            <div class="absolute inset-y-0 start-0 flex items-center ps-3 pointer-events-none">
                                @icon('calendar-month', 'w-4 h-4 text-gray-500')
                            </div>
                            <input type="text"
                                   class="input peer ps-10"
                                   placeholder="02.04.2025"
                                   datepicker-autohide
                                   datepicker-format="dd.mm.yyyy"
                                   datepicker-button="false"
                                   wire:model.live="activityForm.scheduled_period_start"
                            />
                        </div>
                    </div>
                    <div class="form-group group">
                        <label class="label">&nbsp;</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 start-0 flex items-center ps-3 pointer-events-none">
                                <svg class="w-4 h-4 text-gray-500 dark:text-gray-400" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                                </svg>
                            </div>
                            <input type="text"
                                   class="input timepicker-uk ps-10"
                                   placeholder="02:30 PM"
                            />
                        </div>
                    </div>
                </div>

                {{-- Quantity per time, End Date, End Time --}}
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-4">
                    <div class="form-group group">
                        <label for="quantity_per_time" class="label">
                            {{ __('care-plan.quantity_per_time') }}
                        </label>
                        <div class="flex gap-2">
                            <input type="number"
                                   id="quantity_per_time"
                                   name="quantity_per_time"
                                   class="input peer w-full"
                                   value="1"
                            >
                            <select class="input-select peer w-20">
                                <option selected value="units">{{ __('care-plan.units') }}</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group group">
                        <label class="label">
                            {{ __('care-plan.end_date') }}:
                        </label>
                        <div class="relative">
                            <div class="absolute inset-y-0 start-0 flex items-center ps-3 pointer-events-none">
                                @icon('calendar-month', 'w-4 h-4 text-gray-500')
                            </div>
                            <input type="text"
                                   class="input peer ps-10"
                                   placeholder="02.08.2025"
                                   datepicker-autohide
                                   datepicker-format="dd.mm.yyyy"
                                   datepicker-button="false"
                                   wire:model.live="activityForm.scheduled_period_end"
                            />
                        </div>
                    </div>
                    <div class="form-group group">
                        <label class="label">&nbsp;</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 start-0 flex items-center ps-3 pointer-events-none">
                                <svg class="w-4 h-4 text-gray-500 dark:text-gray-400" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                                </svg>
                            </div>
                            <input type="text"
                                   class="input timepicker-uk ps-10"
                                   placeholder="02:30 PM"
                            />
                        </div>
                    </div>
                </div>

                {{-- Number of times, Duration --}}
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="form-group group">
                        <label for="number_of_times" class="label">
                            {{ __('care-plan.number_of_times') }}
                        </label>
                        <div class="flex gap-2">
                            <input type="number"
                                   id="number_of_times"
                                   name="number_of_times"
                                   class="input peer w-full"
                                   value="1"
                            >
                            <select class="input-select peer w-28">
                                <option selected value="per_day">{{ __('care-plan.per_day') }}</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group group">
                        <label for="duration" class="label">
                            {{ __('care-plan.duration') }}
                        </label>
                        <input type="number"
                               id="duration"
                               name="duration"
                               class="input peer w-full"
                               value="10"
                        >
                    </div>
                    <div class="form-group group">
                        <label class="label">&nbsp;</label>
                        <select class="input-select peer w-full">
                            <option selected value="days">{{ __('care-plan.days') }}</option>
                        </select>
                    </div>
                </div>
            </fieldset>

            {{-- Grounds for Prescription Section --}}
            <fieldset class="fieldset">
                <legend class="legend">
                    {{ __('care-plan.grounds_for_prescription') }}
                </legend>

                <div class="form-row-3">
                    <select class="input-select peer w-full">
                        <option selected value="">{{ __('care-plan.select_icd10_code') }}</option>
                    </select>
                </div>

                <div class="mb-4">
                    <h4 class="text-base font-semibold text-gray-900 dark:text-white mb-4">
                        {{ __('care-plan.justification_of_grounds') }}
                    </h4>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left">
                            <thead class="thead-input">
                                <tr>
                                    <th scope="col" class="px-4 py-3 font-medium">{{ __('care-plan.date') }}</th>
                                    <th scope="col" class="px-4 py-3 font-medium">{{ __('care-plan.name') }}</th>
                                    <th scope="col" class="px-4 py-3 font-medium text-right">{{ __('care-plan.action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="bg-white border-b dark:bg-gray-800 dark:border-gray-700">
                                    <td class="px-4 py-3 text-gray-900 dark:text-white whitespace-nowrap">
                                        02.05.2025
                                    </td>
                                    <td class="px-4 py-3 text-gray-900 dark:text-white">
                                        Діагностичний звіт A35002 Загальний аналіз сечі (лабораторна діагностика), Лейкоцити 10,0
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <button type="button" class="text-gray-500 hover:text-red-600 dark:text-gray-400 dark:hover:text-red-500">
                                            @icon('delete', 'w-5 h-5')
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <button type="button" class="item-add">
                    {{ __('care-plan.add_medical_record') }}
                </button>
            </fieldset>

            {{-- Additional Information Section --}}
            <fieldset class="fieldset">
                <legend class="legend">
                    {{ __('care-plan.additional_info') }}
                </legend>

                <div class="form-row-3">
                    <label for="expected_result" class="label">
                        {{ __('care-plan.expected_result') }}
                    </label>
                    <select id="expected_result"
                            name="expected_result"
                            class="input-select peer w-full"
                    >
                        <option selected value="">{{ __('care-plan.select_result') }}</option>
                    </select>
                </div>

                <div class="form-row">
                    <label for="description" class="label">
                        {{ __('care-plan.extended_description') }}
                    </label>
                    <textarea id="description"
                              class="input peer w-full"
                              rows="4"
                              placeholder="{{ __('care-plan.description') }}"
                              wire:model="activityForm.description"
                    ></textarea>
                </div>
            </fieldset>

            <div class="mt-6 flex justify-start gap-3">
                <button type="button"
                        class="button-minor"
                        aria-controls="services-drawer-right"
                        @click="showServiceDrawer = false"
                >
                    {{ __('forms.cancel') }}
                </button>

                <button type="submit"
                        class="button-primary"
                >
                    {{ __('forms.save') }}
                </button>
            </div>
        </form>
        </div>
    </div>
    </div>
</template>
