@php
    $programOptions = [\App\Services\Dictionary\MedicationProgramPicker::DEFAULT_ID => __('care-plan.prescription_medication')] + ($dictionaries['medical_programs_medication'] ?? []);
    $unitOptions = $dictionaries['MEDICATION_UNIT'] ?? [];
    $currentUnit = $activityForm['quantity_code'] ?? '';
    $dailyUnit = $activityForm['daily_amount_code'] ?? '';
    if ($currentUnit !== '') $unitOptions[$currentUnit] ??= $currentUnit;
@endphp
<x-dialog-drawer id="medication-form-drawer-right" x-model="showMedicationFormDrawer" :stopClickPropagation="true" maxWidth="4/5" topClass="top-[57px]" zIndex="40" overlayWidth="100%" onCloseClick="showMedicationFormDrawer = false">
    <x-slot name="title">
        <span class="block"><span class="care-plan-service-context block">{{ $carePlan->person?->full_name }} — {{ __('care-plan.care_plan') }} №{{ $carePlan->requisition ?? $carePlan->id }}</span>{{ __(!empty($activityForm['id']) ? 'care-plan.edit_medication_prescription' : 'care-plan.new_medication_form') }}</span>
    </x-slot>
    <form wire:submit="saveActivity" class="care-plan-service-form care-plan-medication-form">
        @if ($errors->any())<div role="alert" class="mb-6 rounded-lg bg-red-50 p-4 text-sm text-red-700 dark:bg-gray-700 dark:text-red-300">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        <fieldset>
            <legend>{{ __('care-plan.main_data') }}</legend>
            <div class="care-plan-medication-grid">
                <div class="form-group group">
                    @if (!empty($activityForm['id']))
                        <select id="medication-form-program" class="input-select peer" wire:model.live="selectedProgram">@foreach ($programOptions as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                    @else
                        <input id="medication-form-program" type="text" class="input peer" value="{{ $programOptions[$selectedProgram] ?? '-' }}" readonly />
                    @endif
                    <label for="medication-form-program" class="label">{{ __('care-plan.program') }}</label>
                </div>
                <div class="form-group group">
                    <input id="medication-form-product" type="text" class="input peer" value="{{ $selectedProduct['name'] ?? '-' }}" readonly />
                    <label for="medication-form-product" class="label">{{ __('care-plan.medication') }}</label>
                    @if (!empty($activityForm['id']))<button type="button" class="care-plan-add" wire:click="$set('showMedicationSearchDrawer', true)">{{ __('care-plan.change_product') }}</button>@endif
                </div>
                <div class="form-group group">
                    <div class="care-plan-medication-amount">
                        <input id="med_quantity" type="number" min="0.01" step="any" class="input peer" wire:model="activityForm.quantity" />
                        <select class="input-select peer" aria-label="{{ __('care-plan.quantity_unit') }}" wire:model="activityForm.quantity_code">@foreach ($unitOptions as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach</select>
                    </div>
                    <label for="med_quantity" class="label">{{ __('care-plan.quantity') }}</label>
                </div>
                <div class="form-group group">
                    <div class="care-plan-service-period">
                        @icon('calendar', 'w-4 h-4 text-gray-500')
                        <input id="medication-start-date" type="text" class="input peer datepicker-input" datepicker-autohide datepicker-format="{{ frontendDateFormat() }}" wire:model="activityForm.scheduled_period_start" placeholder="дд.мм.рррр" />
                        @icon('clock', 'w-4 h-4 text-gray-500')
                        <input data-time type="text" class="input peer" inputmode="numeric" maxlength="5" disabled aria-label="{{ __('care-plan.start_date') }} — {{ __('care-plan.time') }}" />
                    </div>
                    <label for="medication-start-date" class="label">{{ __('care-plan.start_date') }}</label>
                </div>
                <div class="form-group group">
                    <div class="care-plan-medication-amount">
                        <input id="med_daily_amount" type="number" min="0.01" step="any" class="input peer" wire:model="activityForm.daily_amount" />
                        <select class="input-select peer" aria-label="{{ __('care-plan.daily_amount') }} — {{ __('care-plan.quantity_unit') }}" disabled><option>{{ $unitOptions[$dailyUnit] ?? $dailyUnit }}</option></select>
                    </div>
                    <label for="med_daily_amount" class="label">{{ __('care-plan.daily_amount') }}</label>
                </div>
                <div class="form-group group">
                    <div class="care-plan-service-period">
                        @icon('calendar', 'w-4 h-4 text-gray-500')
                        <input id="medication-end-date" type="text" class="input peer datepicker-input" datepicker-autohide datepicker-format="{{ frontendDateFormat() }}" wire:model="activityForm.scheduled_period_end" placeholder="дд.мм.рррр" />
                        @icon('clock', 'w-4 h-4 text-gray-500')
                        <input data-time type="text" class="input peer" inputmode="numeric" maxlength="5" disabled aria-label="{{ __('care-plan.end_date') }} — {{ __('care-plan.time') }}" />
                    </div>
                    <label for="medication-end-date" class="label">{{ __('care-plan.end_date') }}</label>
                </div>
                <div class="form-group group">
                    <div class="care-plan-medication-amount"><input id="med_number_of_times" type="number" min="1" step="1" class="input peer" disabled /><span class="input text-gray-500">{{ __('care-plan.per_day') }}</span></div>
                    <label for="med_number_of_times" class="label">{{ __('care-plan.number_of_times') }}</label>
                </div>
                <div class="form-group group">
                    <div class="care-plan-medication-amount"><input id="med_duration" type="number" min="1" step="1" class="input peer" disabled /><span class="input text-gray-500">{{ __('care-plan.days') }}</span></div>
                    <label for="med_duration" class="label">{{ __('care-plan.duration') }}</label>
                </div>
            </div>
        </fieldset>

        @include('livewire.care-plan.parts.activity.grounds-fields', ['inputPrefix' => 'medication'])

        <fieldset>
            <legend>{{ __('care-plan.additional_info') }}</legend>
            <div class="form-group group mb-10 max-w-sm">
                <select id="med_expected_result" class="input-select peer" wire:model="activityForm.goal">
                    <option value="">{{ __('forms.select') }}</option>

                    @foreach ($dictionaries['care_plan_activity_goals'] ?? [] as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach
                </select>
                <label for="med_expected_result" class="label">{{ __('care-plan.expected_result') }}</label>
            </div>
            <label for="med_description" class="mb-3 block text-sm">{{ __('care-plan.extended_description') }}</label>
            <textarea id="med_description" class="care-plan-service-description" rows="5" placeholder="{{ __('care-plan.description') }}" wire:model="activityForm.description"></textarea>
        </fieldset>
        <div class="flex gap-4 pb-8">
            <button type="button" class="button-minor" @click="showMedicationFormDrawer = false">{{ __('forms.cancel') }}</button>
            <button type="submit" class="button-primary" wire:loading.attr="disabled" wire:target="saveActivity">{{ __(!empty($activityForm['id']) ? 'forms.save' : 'care-plan.add_medication') }}</button>
        </div>
    </form>
</x-dialog-drawer>
