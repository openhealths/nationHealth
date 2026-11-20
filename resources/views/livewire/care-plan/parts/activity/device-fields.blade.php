@php
    $programOptions = ['' => __('care-plan.without_medical_program')] + ($dictionaries['medical_programs_device'] ?? []);
    $byType = filled($activityForm['product_codeable_concept'] ?? null);
    $productLabel = $byType ? ($dictionaries['device_definition_classification_type'][$activityForm['product_codeable_concept']] ?? $activityForm['product_codeable_concept'])
        : ($selectedProduct['display_name'] ?? $selectedProduct['name'] ?? $activityForm['product_reference'] ?? '');
@endphp
<form wire:submit="saveActivity" class="care-plan-service-form" x-data="{ showDeviceProgramDetails: false }" @device-activity-opened.window="showDeviceProgramDetails = false">
    {{-- Session errors are consumed by the parent XMessage toast; validation errors are shown here. --}}
    @if ($errors->any())
        <div role="alert" class="mb-6 rounded-lg bg-red-50 p-4 text-sm text-red-700 dark:bg-gray-700 dark:text-red-300">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
    @endif
    @foreach (array_filter([$deviceParticipationWarning ?? '', $deviceSelectionWarning ?? '']) as $warning)<p class="mb-6 text-sm text-gray-500">{{ $warning }}</p>@endforeach
    <fieldset>
        <legend>{{ __('care-plan.main_data') }}</legend>
        <div class="care-plan-service-fields">
            <div class="flex items-end gap-4 max-w-sm">
                <div class="form-group group flex-1">
                    <select id="{{ $inputPrefix }}-program" class="input-select peer" wire:model.live="selectedProgram" @change="showDeviceProgramDetails = false">@foreach ($programOptions as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                    <label for="{{ $inputPrefix }}-program" class="label">{{ __('care-plan.program') }}</label>
                </div>
                @if ($selectedProgram !== '')<button type="button" class="care-plan-program-question mb-2" @click="showDeviceProgramDetails = !showDeviceProgramDetails" :aria-expanded="showDeviceProgramDetails" aria-label="{{ __('care-plan.show_program_details') }}">@icon('question-mark-circle', 'w-4 h-4')</button>@endif
            </div>
            <fieldset class="care-plan-program-details relative" x-show="showDeviceProgramDetails" x-cloak>
                <legend>{{ $programOptions[$selectedProgram] ?? '' }} — {{ __('care-plan.program_details') }}</legend>
                <button type="button" class="record-inner-action-btn absolute right-4 top-3 p-1" @click="showDeviceProgramDetails = false" aria-label="{{ __('forms.close') }}">@icon('close', 'w-5 h-5')</button>
                <dl class="pr-4">@forelse (($deviceProgramDetails[$selectedProgram] ?? []) as $field => $value)<div class="mb-1"><dt class="inline font-semibold">{{ __('dictionaries.medical_device.'.$field) }}:</dt> <dd class="inline">{{ $value }}</dd></div>@empty <dd>{{ __('forms.no_data') }}</dd> @endforelse</dl>
            </fieldset>
            <div class="flex flex-wrap gap-x-8 gap-y-3">
                @foreach (['CLASSIFICATION_TYPE' => __('device-requests.choose_type'), 'DEVICE_DEFINITION' => __('device-requests.choose_model')] as $mode => $label)<label class="flex items-center gap-2 text-sm" for="{{ $inputPrefix }}-{{ $mode }}"><input id="{{ $inputPrefix }}-{{ $mode }}" type="radio" class="default-radio" value="{{ $mode }}" disabled @checked($mode === 'CLASSIFICATION_TYPE' ? $byType : filled($activityForm['product_reference'] ?? null)) />{{ $label }}</label>@endforeach
            </div>
            <div class="care-plan-service-product">
                <div class="form-group group">
                    <button type="button" id="{{ $inputPrefix }}-product" class="input-select peer w-full text-left" wire:click="openMedicalDeviceSearch">{{ $productLabel ?: __('care-plan.select_medical_device') }}</button>
                    <label for="{{ $inputPrefix }}-product" class="label">{{ __('care-plan.medical_device') }}*</label>
                    @if (filled($activityForm['product_reference'] ?? null))<p class="mt-1 break-all text-xs text-gray-500">{{ __('care-plan.uuid') }}: {{ $activityForm['product_reference'] }}</p>@endif
                    @error('activityForm.product_reference')<p class="text-error">{{ $message }}</p>@enderror
                    @error('activityForm.product_codeable_concept')<p class="text-error">{{ $message }}</p>@enderror
                </div>
                <button type="button" class="care-plan-add" wire:click="openMedicalDeviceSearch">@icon('book-open', 'w-5 h-5') {{ __('care-plan.choose_from_catalog') }}</button>
            </div>
            <div class="form-group group">
                <div class="care-plan-service-quantity">
                    <input type="number" id="{{ $inputPrefix }}-quantity" min="1" step="1" class="input peer" wire:model="activityForm.quantity" />
                    <select aria-label="{{ __('care-plan.quantity_unit') }}" class="input-select peer" wire:model="activityForm.quantity_code">@if (filled($activityForm['quantity_code'] ?? null) && !isset($dictionaries['device_unit'][$activityForm['quantity_code']]))<option value="{{ $activityForm['quantity_code'] }}">{{ $activityForm['quantity_code'] }}</option>@endif @foreach ($dictionaries['device_unit'] ?? [] as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach</select>
                </div>
                <label for="{{ $inputPrefix }}-quantity" class="label">{{ __('care-plan.quantity') }}</label>
                @error('activityForm.quantity')<p class="text-error">{{ $message }}</p>@enderror @error('activityForm.quantity_code')<p class="text-error">{{ $message }}</p>@enderror
            </div>
            @foreach (['start' => 'start_date', 'end' => 'end_date'] as $boundary => $dateLabel)
                <div class="form-group group">
                    <div class="care-plan-service-period">
                        @icon('calendar', 'w-4 h-4 text-gray-500')
                        <input type="text" id="{{ $inputPrefix }}-{{ $boundary }}-date" class="input peer datepicker-input" datepicker-autohide datepicker-format="{{ frontendDateFormat() }}" placeholder="дд.мм.рррр" wire:model="activityForm.scheduled_period_{{ $boundary }}" autocomplete="off" />
                        @icon('clock', 'w-4 h-4 text-gray-500')
                        <input type="text" inputmode="numeric" data-time maxlength="5" placeholder="гг:хх" aria-label="{{ __('care-plan.'.$dateLabel) }} — {{ __('care-plan.time') }}" class="input peer" disabled />
                    </div>
                    <label for="{{ $inputPrefix }}-{{ $boundary }}-date" class="label">{{ __('care-plan.'.$dateLabel) }}</label>
                    @error('activityForm.scheduled_period_'.$boundary)<p class="text-error">{{ $message }}</p>@enderror
                </div>
            @endforeach
        </div>
    </fieldset>
    @include('livewire.care-plan.parts.activity.grounds-fields', ['inputPrefix' => $inputPrefix])
    <fieldset>
        <legend>{{ __('care-plan.additional_info') }}</legend>
        <div class="form-group group mb-10 max-w-sm">
            <select id="{{ $inputPrefix }}-goal" class="input-select peer" wire:model="activityForm.goal"><option value="">{{ __('care-plan.select_result') }}</option>@foreach ($dictionaries['care_plan_activity_goals'] ?? [] as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach</select>
            <label for="{{ $inputPrefix }}-goal" class="label">{{ __('care-plan.expected_result') }}</label>
        </div>
        <label for="{{ $inputPrefix }}-description" class="mb-3 block text-sm">{{ __('care-plan.extended_description') }}</label>
        <textarea id="{{ $inputPrefix }}-description" class="care-plan-service-description" rows="5" placeholder="{{ __('care-plan.description') }}" wire:model="activityForm.description"></textarea>
    </fieldset>
    <div class="flex flex-wrap gap-4 pb-8">
        <button type="button" class="button-minor" @click="{{ $drawerState }} = false">{{ __('forms.cancel') }}</button>
        <button type="submit" class="button-primary" wire:loading.attr="disabled" wire:target="saveActivity">{{ !empty($activityForm['id']) ? __('forms.save') : __('care-plan.add_prescription') }}</button>
        <button type="button" class="button-primary" wire:click="saveActivityAndSign" wire:loading.attr="disabled">{{ __('forms.save_and_sign') }}</button>
    </div>
</form>
