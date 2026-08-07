{{-- Medication Form Drawer Overlay (below header z-60) --}}
<div
    x-show="showMedicationFormDrawer"
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="opacity-0"
    x-transition:enter-end="opacity-100"
    x-transition:leave="transition ease-in duration-200"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    x-cloak
    @click="showMedicationFormDrawer = false"
    class="fixed top-0 right-0 h-screen bg-gray-900/50 pt-20"
    style="z-index: 46; width: calc(80% - 30px)"
></div>

{{-- Medication Form Drawer (60px gap on the LEFT — third drawer) --}}
<div
    id="medication-form-drawer-right"
    x-show="showMedicationFormDrawer"
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="translate-x-full"
    x-transition:enter-end="translate-x-0"
    x-transition:leave="transition ease-in duration-200"
    x-transition:leave-start="translate-x-0"
    x-transition:leave-end="translate-x-full"
    x-cloak
    class="fixed top-0 right-0 h-screen overflow-y-auto bg-white p-4 pt-20 shadow-2xl dark:bg-gray-800"
    style="z-index: 47; width: calc(80% - 60px)"
    tabindex="-1"
>
    <h3 class="modal-header">
        @if (isset($activityForm['id']) && $activityForm['id'])
            {{ __('care-plan.edit_medication_prescription') }}
        @else
            {{ __('care-plan.new_medication_prescription') }}
        @endif
    </h3>

    {{-- Content --}}
    <form wire:submit.prevent="saveActivity">
        @if (session()->has('error'))
            <div
                x-init="$el.scrollIntoView({ behavior: 'smooth', block: 'center' })"
                class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-gray-800 dark:text-red-400"
                role="alert"
            >
                <div class="flex items-center gap-2">
                    @icon('alert-circle', 'w-5 h-5 text-red-500')
                    <span class="font-bold">Увага!</span>
                </div>
                <div class="mt-2">{{ session('error') }}</div>
            </div>
        @endif

        @if ($errors->any())
            <div
                x-init="$el.scrollIntoView({ behavior: 'smooth', block: 'center' })"
                class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-gray-800 dark:text-red-400"
                role="alert"
            >
                <div class="flex items-center gap-2">
                    @icon('alert-circle', 'w-5 h-5 text-red-500')
                    <span class="font-bold">Будь ласка, виправте помилки:</span>
                </div>
                <ul class="mt-2 list-inside list-disc">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Main Data Section --}}
        <fieldset class="fieldset">
            <legend class="legend">{{ __('care-plan.main_data') }}</legend>

            {{-- Program and Medication --}}
            <div class="mb-4 grid grid-cols-1 gap-6 md:grid-cols-2">
                <div class="form-group group">
                    <label class="label" for="medication_program_edit"> {{ __('care-plan.program') }} </label>
                    @if (!empty($activityForm['id']))
                        <select
                            id="medication_program_edit"
                            class="input-select peer"
                            wire:model.live="selectedProgram"
                        >
                            <option value="">{{ __('care-plan.prescription_medication') }}</option>
                            @foreach (($dictionaries['medical_programs_medication'] ?? $dictionaries['medical_programs'] ?? []) as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    @else
                        <input
                            type="text"
                            class="input cursor-not-allowed bg-gray-50 dark:bg-gray-700"
                            value="{{ !empty($activityForm['program']) ? ($dictionaries['medical_programs'][$activityForm['program']] ?? $activityForm['program']) : __('care-plan.prescription_medication') }}"
                            disabled
                        />
                    @endif
                </div>
                <div class="form-group group">
                    <label class="label"> {{ __('care-plan.medication') }}* </label>
                    <div class="relative">
                        <input
                            type="text"
                            class="input bg-gray-50 dark:bg-gray-700 {{ empty($activityForm['id']) ? 'cursor-not-allowed' : 'pr-12' }} font-medium text-gray-900 dark:text-white w-full"
                            value="{{ !empty($selectedProduct) ? ($selectedProduct['name'] ?? '') : '' }}"
                            disabled
                        />
                        @if (!empty($activityForm['id']))
                            <button
                                type="button"
                                class="absolute top-1/2 right-2 -translate-y-1/2 text-sm whitespace-nowrap text-blue-600 hover:text-blue-800"
                                aria-controls="medication-search-drawer-right"
                                @click.stop="showMedicationSearchDrawer = true"
                            >
                                {{ __('care-plan.change_product') }}
                            </button>
                        @endif
                    </div>
                    <input type="hidden" wire:model="activityForm.product_reference" />
                </div>
            </div>

            {{-- Quantity, Start Date, Start Time --}}
            <div class="mb-4 grid grid-cols-1 gap-6 md:grid-cols-3">
                <div class="form-group group">
                    <label for="med_quantity" class="label"> {{ __('care-plan.quantity') }} </label>
                    <div class="flex gap-2">
                        <input
                            type="number"
                            id="med_quantity"
                            class="input peer w-full"
                            wire:model="activityForm.quantity"
                        />
                        <select class="input-select peer w-20" wire:model="activityForm.quantity_system">
                            <option value="MEDICATION_UNIT">
                                {{ match($activityForm['quantity_code'] ?? '') { 'PIECE' => 'шт.', 'ML' => 'мл', 'MG' => 'мг', 'G' => 'г', '' => __('care-plan.ml'), default => $activityForm['quantity_code'] } }}
                            </option>
                        </select>
                    </div>
                </div>
                <div class="form-group group">
                    <label class="label"> {{ __('care-plan.start_date') }}: <span class="text-red-500">*</span> </label>
                    <div class="relative">
                        <div class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3">
                            @icon('calendar-month', 'w-4 h-4 text-gray-500')
                        </div>
                        <input
                            type="text"
                            class="input peer datepicker-input ps-10"
                            placeholder="02.04.2025"
                            datepicker-autohide
                            datepicker-button="false"
                            wire:model.live="activityForm.scheduled_period_start"
                        />
                    </div>
                </div>
                <div class="form-group group">
                    <label class="label">&nbsp;</label>
                    <div class="relative">
                        <div class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3">
                            <svg class="h-4 w-4 text-gray-500 dark:text-gray-400" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                            </svg>
                        </div>
                        <input type="text" class="input timepicker-uk ps-10" placeholder="02:30 PM" />
                    </div>
                </div>
            </div>

            {{-- Quantity per time, End Date, End Time --}}
            <div class="mb-4 grid grid-cols-1 gap-6 md:grid-cols-3">
                <div class="form-group group">
                    <label for="med_quantity_per_time" class="label"> {{ __('care-plan.quantity_per_time') }} </label>
                    <div class="flex gap-2">
                        <input
                            type="number"
                            id="med_quantity_per_time"
                            name="med_quantity_per_time"
                            class="input peer w-full"
                            wire:model="activityForm.daily_amount"
                        />
                        <select class="input-select peer w-20" disabled>
                            <option selected value="{{ $activityForm['daily_amount_code'] ?? 'PIECE' }}">
                                {{ match($activityForm['daily_amount_code'] ?? 'PIECE') { 'PIECE' => 'шт.', 'ML' => 'мл', 'MG' => 'мг', 'G' => 'г', default => $activityForm['daily_amount_code'] ?? 'шт.' } }}
                            </option>
                        </select>
                    </div>
                </div>
                <div class="form-group group">
                    <label class="label"> {{ __('care-plan.end_date') }}: <span class="text-red-500">*</span> </label>
                    <div class="relative">
                        <div class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3">
                            @icon('calendar-month', 'w-4 h-4 text-gray-500')
                        </div>
                        <input
                            type="text"
                            class="input peer datepicker-input ps-10"
                            placeholder="02.08.2025"
                            datepicker-autohide
                            datepicker-button="false"
                            wire:model.live="activityForm.scheduled_period_end"
                        />
                    </div>
                </div>
                <div class="form-group group">
                    <label class="label">&nbsp;</label>
                    <div class="relative">
                        <div class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3">
                            <svg class="h-4 w-4 text-gray-500 dark:text-gray-400" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                            </svg>
                        </div>
                        <input type="text" class="input timepicker-uk ps-10" placeholder="02:30 PM" />
                    </div>
                </div>
            </div>

            {{-- Number of times, Duration --}}
            <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
                <div class="form-group group">
                    <label for="med_number_of_times" class="label"> {{ __('care-plan.number_of_times') }} </label>
                    <div class="flex gap-2">
                        <input
                            type="number"
                            id="med_number_of_times"
                            name="med_number_of_times"
                            class="input peer w-full"
                            value="1"
                        />
                        <select class="input-select peer w-28">
                            <option selected value="per_day">{{ __('care-plan.per_day') }}</option>
                        </select>
                    </div>
                </div>
                <div class="form-group group">
                    <label for="med_duration" class="label"> {{ __('care-plan.duration') }} </label>
                    <input type="number" id="med_duration" name="med_duration" class="input peer w-full" value="10" />
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
        <fieldset class="fieldset" x-data="{ selectedGround: '' }">
            <legend class="legend">{{ __('care-plan.grounds_for_prescription') }}</legend>

            <div class="mb-6 flex items-end gap-4">
                <div class="flex-1">
                    <label class="label">Оберіть клінічний запис пацієнта</label>
                    <select
                        x-model="selectedGround"
                        @change="
                            if (selectedGround) {
                                let parts = selectedGround.split('|');
                                $wire.addLinkedGround(parts[0], parts[1]);
                                selectedGround = '';
                            }
                        "
                        class="input-select peer w-full"
                    >
                        <option value="">-- Оберіть запис --</option>
                        @if (!empty($availableConditions))
                            <optgroup label="Діагнози (Стани)">
                                @foreach ($availableConditions as $cond)
                                    <option value="Condition|{{ $cond['uuid'] }}">
                                        {{ $cond['name'] }} (від {{ $cond['date'] }})
                                    </option>
                                @endforeach
                            </optgroup>
                        @endif
                        @if (!empty($availableReports))
                            <optgroup label="Діагностичні звіти">
                                @foreach ($availableReports as $report)
                                    <option value="DiagnosticReport|{{ $report['uuid'] }}">
                                        {{ $report['name'] }} (від {{ $report['date'] }})
                                    </option>
                                @endforeach
                            </optgroup>
                        @endif
                        @if (!empty($availableObservations))
                            <optgroup label="Спостереження">
                                @foreach ($availableObservations as $obs)
                                    <option value="Observation|{{ $obs['uuid'] }}">
                                        {{ $obs['name'] }} (від {{ $obs['date'] }})
                                    </option>
                                @endforeach
                            </optgroup>
                        @endif
                    </select>
                </div>
            </div>

            <div class="mb-4">
                <h4 class="mb-4 text-base font-semibold text-gray-900 dark:text-white">
                    {{ __('care-plan.justification_of_grounds') }}
                </h4>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="thead-input">
                            <tr>
                                <th scope="col" class="px-4 py-3 font-medium">{{ __('care-plan.date') }}</th>
                                <th scope="col" class="px-4 py-3 font-medium">{{ __('care-plan.name') }}</th>
                                <th scope="col" class="px-4 py-3 text-right font-medium">Дія</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @forelse ($linkedGrounds as $ground)
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                    <td class="px-4 py-3 whitespace-nowrap text-gray-500 dark:text-gray-400">
                                        {{ $ground['date'] }}
                                    </td>
                                    <td class="px-4 py-3 text-gray-900 dark:text-white">
                                        <span class="mr-2 inline-flex items-center rounded bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                                            {{ $ground['type'] === 'Condition' ? 'Діагноз' : ($ground['type'] === 'DiagnosticReport' ? 'Діагн. звіт' : 'Спостереження') }}
                                        </span>
                                        {{ $ground['name'] }}
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <button
                                            type="button"
                                            wire:click="removeLinkedGround('{{ $ground['uuid'] }}')"
                                            class="text-red-500 transition-colors hover:text-red-700"
                                        >
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
            <legend class="legend">{{ __('care-plan.additional_info') }}</legend>

            <div class="form-row-3">
                <div class="form-group group">
                    <label for="med_expected_result" class="label"> {{ __('care-plan.expected_result') }} </label>
                    <select id="med_expected_result" name="med_expected_result" class="input-select peer w-full">
                        <option selected value="">{{ __('care-plan.select_service') }}</option>
                    </select>
                </div>
            </div>

            <div class="form-group group mt-4">
                <label for="med_description" class="label mb-2"> {{ __('care-plan.extended_description') }} </label>
                <textarea
                    id="med_description"
                    class="block w-full rounded-2xl border border-gray-200 bg-white p-4 text-sm text-gray-900 focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:placeholder-gray-400 dark:focus:border-blue-500 dark:focus:ring-blue-500"
                    rows="5"
                    placeholder="{{ __('care-plan.description') }}"
                    wire:model="activityForm.description"
                ></textarea>
            </div>
        </fieldset>

        <div class="mt-6 flex justify-start gap-3">
            <button type="button" class="button-minor" @click="showMedicationFormDrawer = false">
                {{ __('forms.cancel') }}
            </button>

            <button type="submit" class="button-primary">{{ __('forms.save') }}</button>
        </div>
    </form>
</div>
