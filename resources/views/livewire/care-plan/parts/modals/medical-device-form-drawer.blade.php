{{-- Medical Device Form Drawer Overlay (below header z-60) --}}
<div x-show="showMedicalDeviceFormDrawer"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     x-cloak
     @click="showMedicalDeviceFormDrawer = false"
     class="fixed top-0 right-0 h-screen pt-20 bg-gray-900/50"
     style="z-index: 46; width: calc(80% - 30px);"
></div>

{{-- Medical Device Form Drawer (60px gap on the LEFT — third drawer) --}}
<div id="medical-device-form-drawer-right"
     x-show="showMedicalDeviceFormDrawer"
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
        @if(isset($activityForm['id']) && $activityForm['id'])
            {{ __('care-plan.edit_medical_device_prescription') }}
        @else
            {{ __('care-plan.new_medical_device_prescription') }}
        @endif
    </h3>

    {{-- Content --}}
    <form wire:submit.prevent="saveActivity">
        {{-- Main Data Section --}}
        <fieldset class="fieldset">
            <legend class="legend">
                {{ __('care-plan.main_data') }}
            </legend>

            {{-- Program and Medical Device --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
                <div class="form-group group">
                    <label class="label">
                        {{ __('care-plan.program') }}
                    </label>
                    <input type="text" 
                           class="input bg-gray-50 dark:bg-gray-700 cursor-not-allowed" 
                           value="{{ !empty($activityForm['program']) ? ($dictionaries['medical_programs'][$activityForm['program']] ?? $activityForm['program']) : __('care-plan.medical_guarantees_program') }}" 
                           disabled
                    />
                </div>
                <div class="form-group group">
                    <label class="label">
                        {{ __('care-plan.medical_device') }}*
                    </label>
                    <input type="text" 
                           class="input bg-gray-50 dark:bg-gray-700 cursor-not-allowed font-medium text-gray-900 dark:text-white" 
                           value="{{ !empty($selectedProduct) ? ($selectedProduct['name'] ?? '') : '' }}" 
                           disabled
                    />
                    <input type="hidden" wire:model="activityForm.product_reference" />
                </div>
            </div>

            {{-- Quantity, Start Date, Start Time --}}
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-4">
                <div class="form-group group">
                    <label for="device_quantity" class="label">
                        {{ __('care-plan.quantity') }}
                    </label>
                    <div class="flex gap-2">
                        <input type="number"
                               id="device_quantity"
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
                    <label for="device_quantity_per_time" class="label">
                        {{ __('care-plan.quantity_per_time') }}
                    </label>
                    <div class="flex gap-2">
                        <input type="number"
                               id="device_quantity_per_time"
                               name="device_quantity_per_time"
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
                    <label for="device_number_of_times" class="label">
                        {{ __('care-plan.number_of_times') }}
                    </label>
                    <div class="flex gap-2">
                        <input type="number"
                               id="device_number_of_times"
                               name="device_number_of_times"
                               class="input peer w-full"
                               value="1"
                        >
                        <select class="input-select peer w-28">
                            <option selected value="per_day">{{ __('care-plan.per_day') }}</option>
                        </select>
                    </div>
                </div>
                <div class="form-group group">
                    <label for="device_duration" class="label">
                        {{ __('care-plan.duration') }}
                    </label>
                    <input type="number"
                           id="device_duration"
                           name="device_duration"
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
        </fieldset>        {{-- Grounds for Prescription Section --}}
        <fieldset class="fieldset" x-data="{ selectedGround: '' }">
            <legend class="legend">
                {{ __('care-plan.grounds_for_prescription') }}
            </legend>

            <div class="flex gap-4 items-end mb-6">
                <div class="flex-1">
                    <label class="label">Оберіть клінічний запис пацієнта</label>
                    <select x-model="selectedGround" class="input-select peer w-full">
                        <option value="">-- Оберіть запис --</option>
                        @if(!empty($availableConditions))
                            <optgroup label="Діагнози (Стани)">
                                @foreach($availableConditions as $cond)
                                    <option value="Condition|{{ $cond['uuid'] }}">{{ $cond['name'] }} (від {{ $cond['date'] }})</option>
                                @endforeach
                            </optgroup>
                        @endif
                        @if(!empty($availableReports))
                            <optgroup label="Діагностичні звіти">
                                @foreach($availableReports as $report)
                                    <option value="DiagnosticReport|{{ $report['uuid'] }}">{{ $report['name'] }} (від {{ $report['date'] }})</option>
                                @endforeach
                            </optgroup>
                        @endif
                        @if(!empty($availableObservations))
                            <optgroup label="Спостереження">
                                @foreach($availableObservations as $obs)
                                    <option value="Observation|{{ $obs['uuid'] }}">{{ $obs['name'] }} (від {{ $obs['date'] }})</option>
                                @endforeach
                            </optgroup>
                        @endif
                    </select>
                </div>
                <button type="button" @click="if(selectedGround) { 
                    let parts = selectedGround.split('|');
                    $wire.addLinkedGround(parts[0], parts[1]);
                    selectedGround = '';
                }" class="button-primary whitespace-nowrap">
                    Додати обґрунтування
                </button>
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
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @forelse($linkedGrounds as $ground)
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                    <td class="px-4 py-3 text-gray-500 dark:text-gray-400 whitespace-nowrap">
                                        {{ $ground['date'] }}
                                    </td>
                                    <td class="px-4 py-3 text-gray-900 dark:text-white">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200 mr-2">
                                            {{ $ground['type'] === 'Condition' ? 'Діагноз' : ($ground['type'] === 'DiagnosticReport' ? 'Діагн. звіт' : 'Спостереження') }}
                                        </span>
                                        {{ $ground['name'] }}
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <button type="button" wire:click="removeLinkedGround('{{ $ground['uuid'] }}')" class="text-red-500 hover:text-red-700 transition-colors">
                                            @icon('delete', 'w-5 h-5')
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="px-4 py-8 text-center text-gray-400 italic">
                                        Немає доданих обґрунтувань
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </fieldset>

        {{-- Additional Information Section --}}
        <fieldset class="fieldset">
            <legend class="legend">
                {{ __('care-plan.additional_info') }}
            </legend>

            <div class="form-row-3">
                <div class="form-group group">
                    <label for="device_expected_result" class="label">
                        {{ __('care-plan.expected_result') }}
                    </label>
                    <select id="device_expected_result"
                            name="device_expected_result"
                            class="input-select peer w-full"
                    >
                        <option selected value="">{{ __('care-plan.select_result') }}</option>
                    </select>
                </div>
            </div>

            <div class="form-group group mt-4">
                <label for="device_description" class="label mb-2">
                    {{ __('care-plan.extended_description') }}
                </label>
                <textarea id="device_description"
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
                    @click="showMedicalDeviceFormDrawer = false"
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
