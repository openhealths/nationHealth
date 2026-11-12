<x-dialog-drawer
    id="services-drawer-right"
    x-model="showServiceDrawer"
    maxWidth="4/5"
    zIndex="40"
    overlayWidth="100%"
    onCloseClick="showServiceDrawer = false"
>
    <x-slot name="title">
        @if (isset($activityForm->id) && $activityForm->id)
            {{ __('care-plan.edit_service_prescription') }}
        @else
            {{ __('care-plan.new_service_prescription') }}
        @endif
    </x-slot>

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
                    <span class="font-bold">{{ __('Увага!') }}</span>
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
                    <span class="font-bold">{{ __('Будь ласка, виправте помилки:') }}</span>
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
            <div class="form-row-2">
                <div class="form-group group">
                    <label class="label">{{ __('care-plan.service') }}*</label>
                    <button
                        type="button"
                        class="input-select peer w-full text-left"
                        wire:click="$set('showServiceSearchDrawer', true)"
                    >
                        {{ !empty($selectedProduct) ? trim(($selectedProduct['code'] ?? '') . ' - ' . ($selectedProduct['name'] ?? '')) : __('care-plan.select_service') }}
                    </button>
                    @error('activityForm.product_reference')
                        <p class="text-error">{{ $message }}</p>
                    @enderror
                </div>
                <div class="form-group group">
                    <label for="serviceProgram" class="label">{{ __('care-plan.program') }}</label>
                    <select id="serviceProgram" class="input-select peer" wire:model.live="selectedProgram">
                        <option value="">{{ __('forms.select') }}</option>
                        @foreach ($dictionaries['medical_programs_service'] ?? [] as $programId => $programName)
                            <option value="{{ $programId }}">{{ $programName }}</option>
                        @endforeach
                    </select>
                    @error('activityForm.program')
                        <p class="text-error">{{ $message }}</p>
                    @enderror
                </div>
            </div>
            <div class="form-row-3">
                <div class="form-group group">
                    <label for="serviceQuantity" class="label">{{ __('care-plan.quantity') }}</label>
                    <div class="flex gap-2">
                        <input
                            type="number"
                            id="serviceQuantity"
                            min="1"
                            step="1"
                            class="input peer w-full"
                            wire:model="activityForm.quantity"
                        />
                        <select
                            aria-label="{{ __('care-plan.quantity') }}"
                            class="input-select peer w-24"
                            wire:model="activityForm.quantity_code"
                        >
                            <option value="PIECE">{{ __('care-plan.units') }}</option>
                            @if ($this->serviceMinutesAllowed)
                                <option value="MINUTE">{{ __('care-plan.minutes') }}</option>
                            @endif
                        </select>
                    </div>
                    @error('activityForm.quantity')
                        <p class="text-error">{{ $message }}</p>
                    @enderror
                    @error('activityForm.quantity_code')
                        <p class="text-error">{{ $message }}</p>
                    @enderror
                    @if ($this->serviceCreationCapacity !== null)
                        <p class="text-sm">
                            {{ __('care-plan.service_activity_capacity', $this->serviceCreationCapacity) }}
                        </p>
                    @endif
                </div>
                <div class="form-group group">
                    <label class="label">{{ __('care-plan.start_date') }}</label>
                    <input
                        type="text"
                        class="input peer datepicker-input"
                        datepicker-autohide
                        datepicker-button="false"
                        wire:model.live="activityForm.scheduled_period_start"
                    />
                    @error('activityForm.scheduled_period_start')
                        <p class="text-error">{{ $message }}</p>
                    @enderror
                </div>
                <div class="form-group group">
                    <label class="label">{{ __('care-plan.end_date') }}</label>
                    <input
                        type="text"
                        class="input peer datepicker-input"
                        datepicker-autohide
                        datepicker-button="false"
                        wire:model.live="activityForm.scheduled_period_end"
                    />
                    @error('activityForm.scheduled_period_end')
                        <p class="text-error">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </fieldset>

        {{-- Grounds for Prescription Section --}}
        <fieldset class="fieldset" x-data="{ selectedGround: '' }">
            <legend class="legend">{{ __('care-plan.grounds_for_prescription') }}</legend>

            <div class="mb-6 flex items-end gap-4">
                <div class="flex-1">
                    <label class="label">{{ __('Оберіть клінічний запис пацієнта') }}</label>
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
                        <option value="">{{ __('-- Оберіть запис --') }}</option>
                        @if (!empty($availableConditions))
                            <optgroup label="{{ __('Діагнози (Стани)') }}">
                                @foreach ($availableConditions as $cond)
                                    <option value="Condition|{{ $cond['uuid'] }}">
                                        {{ __(':name (від :date)', ['name' => $cond['name'], 'date' => $cond['date']]) }}
                                    </option>
                                @endforeach
                            </optgroup>
                        @endif
                        @if (!empty($availableReports))
                            <optgroup label="{{ __('Діагностичні звіти') }}">
                                @foreach ($availableReports as $report)
                                    <option value="DiagnosticReport|{{ $report['uuid'] }}">
                                        {{ __(':name (від :date)', ['name' => $report['name'], 'date' => $report['date']]) }}
                                    </option>
                                @endforeach
                            </optgroup>
                        @endif
                        @if (!empty($availableObservations))
                            <optgroup label="{{ __('Спостереження') }}">
                                @foreach ($availableObservations as $obs)
                                    <option value="Observation|{{ $obs['uuid'] }}">
                                        {{ __(':name (від :date)', ['name' => $obs['name'], 'date' => $obs['date']]) }}
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
                                <th scope="col" class="px-4 py-3 text-right font-medium">{{ __('Дія') }}</th>
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
                                            {{ $ground['type'] === 'Condition' ? __('Діагноз') : ($ground['type'] === 'DiagnosticReport' ? __('Діагн. звіт') : __('Спостереження')) }}
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
                                        {{ __('Немає доданих обґрунтувань') }}
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
                <label for="expected_result" class="label"> {{ __('care-plan.expected_result') }} </label>
                <select id="expected_result" class="input-select peer w-full" wire:model="activityForm.goal">
                    <option value="">{{ __('care-plan.select_result') }}</option>
                    @foreach ($this->dictionaries['care_plan_activity_goals'] ?? [] as $code => $label)
                        <option value="{{ $code }}">{{ $label }}</option>
                    @endforeach
                </select>
                @if (empty($this->dictionaries['care_plan_activity_goals']))
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('care-plan.expected_result_dictionary_empty') }}
                    </p>
                @endif
            </div>

            <div class="form-row">
                <label for="description" class="label"> {{ __('care-plan.extended_description') }} </label>
                <textarea
                    id="description"
                    class="input peer w-full"
                    rows="4"
                    placeholder="{{ __('care-plan.description') }}"
                    wire:model="activityForm.description"
                ></textarea>
            </div>
        </fieldset>

        <div class="mt-6 flex justify-start gap-3">
            <button
                type="button"
                class="button-minor"
                aria-controls="services-drawer-right"
                @click="showServiceDrawer = false"
            >
                {{ __('forms.cancel') }}
            </button>

            <button type="submit" class="button-primary-outline">{{ __('forms.save') }}</button>

            <button type="button" class="button-primary" wire:click="saveActivityAndSign">
                {{ __('forms.save_and_sign') }}
            </button>
        </div>
    </form>
</x-dialog-drawer>
