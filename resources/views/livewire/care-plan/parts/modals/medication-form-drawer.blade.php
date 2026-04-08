{{-- Medication Form Drawer Overlay (below header z-60) --}}
<div x-show="showMedicationFormDrawer"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     x-cloak
     @click="showMedicationFormDrawer = false"
     class="fixed top-0 right-0 h-screen pt-20 bg-gray-900/50"
     style="z-index: 46; width: calc(80% - 30px);"
></div>

{{-- Medication Form Drawer (60px gap on the LEFT — third drawer) --}}
<div id="medication-form-drawer-right"
     x-show="showMedicationFormDrawer"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="translate-x-full"
     x-transition:enter-end="translate-x-0"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="translate-x-0"
     x-transition:leave-end="translate-x-full"
     x-cloak
     class="fixed top-0 right-0 h-screen pt-20 p-4 overflow-y-auto bg-white dark:bg-gray-800 shadow-2xl"
     style="z-index: 47; width: calc(80% - 60px);"
     tabindex="-1"
>
    <h3 class="modal-header">
        {{ __('care-plan.new_medication_prescription') }}
    </h3>

    {{-- Content --}}
    <form wire:submit.prevent="saveActivity">
        {{-- Main Data Section --}}
        <fieldset class="fieldset">
            <legend class="legend">
                {{ __('care-plan.main_data') }}
            </legend>

            {{-- Program and Medication --}}
            <div class="form-row-3">
                <div class="form-group group">
                    <label for="med_program" class="label">
                        {{ __('care-plan.program') }}*
                    </label>
                    <select id="med_program"
                            name="med_program"
                            class="input-select peer"
                    >
                        <option selected value="">{{ __('care-plan.prescription_medication') }}</option>
                    </select>
                </div>
                <div class="form-group group">
                    <label for="med_medication" class="label">
                        {{ __('care-plan.medication') }}*
                    </label>
                    <select id="med_medication"
                            name="med_medication"
                            class="input-select peer"
                    >
                        <option selected value="">{{ __('care-plan.example_medication_name') }}</option>
                    </select>
                </div>
            </div>

            {{-- Quantity, Start Date, Start Time --}}
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-4">
                <div class="form-group group">
                    <label for="med_quantity" class="label">
                        {{ __('care-plan.quantity') }}
                    </label>
                    <div class="flex gap-2">
                        <input type="number"
                               id="med_quantity"
                               class="input peer w-full"
                               wire:model="activityForm.quantity"
                        >
                        <select class="input-select peer w-20" wire:model="activityForm.quantity_system">
                            <option value="ml">{{ __('care-plan.ml') }}</option>
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
                    <label for="med_quantity_per_time" class="label">
                        {{ __('care-plan.quantity_per_time') }}
                    </label>
                    <div class="flex gap-2">
                        <input type="number"
                               id="med_quantity_per_time"
                               name="med_quantity_per_time"
                               class="input peer w-full"
                               value="1"
                        >
                        <select class="input-select peer w-20">
                            <option selected value="ml">{{ __('care-plan.ml') }}</option>
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
                    <label for="med_number_of_times" class="label">
                        {{ __('care-plan.number_of_times') }}
                    </label>
                    <div class="flex gap-2">
                        <input type="number"
                               id="med_number_of_times"
                               name="med_number_of_times"
                               class="input peer w-full"
                               value="1"
                        >
                        <select class="input-select peer w-28">
                            <option selected value="per_day">{{ __('care-plan.per_day') }}</option>
                        </select>
                    </div>
                </div>
                <div class="form-group group">
                    <label for="med_duration" class="label">
                        {{ __('care-plan.duration') }}
                    </label>
                    <input type="number"
                           id="med_duration"
                           name="med_duration"
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
                            <tr>
                                <td class="px-4 py-3 text-gray-900 dark:text-white whitespace-nowrap">
                                    02.05.2025
                                </td>
                                <td class="px-4 py-3 text-gray-900 dark:text-white">
                                    {{ __('care-plan.example_diagnostic_report') }}
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
                <div class="form-group group">
                    <label for="med_expected_result" class="label">
                        {{ __('care-plan.expected_result') }}
                    </label>
                    <select id="med_expected_result"
                            name="med_expected_result"
                            class="input-select peer w-full"
                    >
                        <option selected value="">{{ __('care-plan.select_service') }}</option>
                    </select>
                </div>
            </div>

            <div class="form-group group mt-4">
                <label for="med_description" class="label mb-2">
                    {{ __('care-plan.extended_description') }}
                </label>
                <textarea id="med_description"
                          class="block w-full p-4 text-sm text-gray-900 bg-white border border-gray-200 rounded-2xl focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                          rows="5"
                          placeholder="{{ __('care-plan.description') }}"
                          wire:model="activityForm.description"
                ></textarea>
            </div>
        </fieldset>

        <div class="mt-6 flex justify-start gap-3">
            <button type="button"
                    class="button-minor"
                    @click="showMedicationFormDrawer = false"
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
