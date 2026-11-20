<x-dialog-drawer
    id="services-drawer-right"
    x-model="showServiceDrawer"
    maxWidth="4/5"
    topClass="top-[57px]"
    zIndex="40"
    overlayWidth="100%"
    onCloseClick="showServiceDrawer = false"
>
    <x-slot name="title">
        <span class="block">
            <span class="care-plan-service-context block">{{ $carePlan->person?->full_name }} — {{ __('care-plan.care_plan') }} №{{ $carePlan->requisition ?? $carePlan->id }}</span>
            {{ !empty($activityForm['id']) ? __('care-plan.edit_service_prescription') : __('care-plan.new_service_prescription') }}
        </span>
    </x-slot>

    <form wire:submit="saveActivity" class="care-plan-service-form">
        {{-- CarePlanShow displays session errors through its consuming XMessage toast; field errors stay here. --}}
        @if ($errors->any())
            <div role="alert" class="mb-6 rounded-lg bg-red-50 p-4 text-sm text-red-700 dark:bg-gray-700 dark:text-red-300">
                @foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach
            </div>
        @endif

        <fieldset>
            <legend>{{ __('care-plan.main_data') }}</legend>
            <div class="care-plan-service-fields">
                <div class="form-group group">
                    <select id="serviceProgram" class="input-select peer" wire:model.live="selectedProgram">
                        <option value="">{{ __('forms.select') }}</option>

                        @foreach ($dictionaries['medical_programs_service'] ?? [] as $programId => $programName)
                            <option value="{{ $programId }}">{{ $programName }}</option>
                        @endforeach
                    </select>
                    <label for="serviceProgram" class="label">{{ __('care-plan.program') }}</label>
                    @error('activityForm.program')<p class="text-error">{{ $message }}</p>@enderror
                </div>

                <div class="care-plan-service-product">
                    <div class="form-group group">
                        <button type="button" id="serviceProduct" class="input-select peer w-full text-left" wire:click="$set('showServiceSearchDrawer', true)">
                            {{ $selectedProduct ? trim(($selectedProduct['code'] ?? '').' - '.($selectedProduct['name'] ?? '')) : __('care-plan.select_service_or_group') }}
                        </button>
                        <label for="serviceProduct" class="label">{{ __('care-plan.service') }}*</label>
                        @error('activityForm.product_reference')<p class="text-error">{{ $message }}</p>@enderror
                    </div>
                    <button type="button" class="care-plan-add" wire:click="$set('showServiceSearchDrawer', true)">
                        @icon('book-open', 'w-5 h-5') {{ __('care-plan.choose_from_catalog') }}
                    </button>
                </div>

                <div class="form-group group">
                    <div class="care-plan-service-quantity">
                        <input type="number" id="serviceQuantity" min="0.01" step="any" class="input peer" wire:model="activityForm.quantity" />
                        <select aria-label="{{ __('care-plan.quantity_unit') }}" class="input-select peer" wire:model="activityForm.quantity_code">
                            <option value="PIECE">{{ __('care-plan.units') }}</option>
                            <option value="MINUTE">{{ __('care-plan.minutes') }}</option>
                        </select>
                    </div>
                    <label for="serviceQuantity" class="label">{{ __('care-plan.quantity') }}</label>
                    @error('activityForm.quantity')<p class="text-error">{{ $message }}</p>@enderror
                    @error('activityForm.quantity_code')<p class="text-error">{{ $message }}</p>@enderror
                </div>

                @foreach (['start' => 'start_date', 'end' => 'end_date'] as $boundary => $dateLabel)
                    <div class="form-group group">
                        <div class="care-plan-service-period">
                            @icon('calendar', 'w-4 h-4 text-gray-500')
                            <input type="text" id="service-{{ $boundary }}-date" class="input peer datepicker-input" datepicker-autohide datepicker-format="{{ frontendDateFormat() }}" placeholder="дд.мм.рррр" wire:model="activityForm.scheduled_period_{{ $boundary }}" autocomplete="off" />
                            @icon('clock', 'w-4 h-4 text-gray-500')
                            <input type="text" inputmode="numeric" data-time maxlength="5" placeholder="гг:хх" aria-label="{{ __('care-plan.'.$dateLabel) }} — {{ __('care-plan.time') }}" class="input peer" disabled />
                        </div>
                        <label for="service-{{ $boundary }}-date" class="label">{{ __('care-plan.'.$dateLabel) }}</label>
                        @error('activityForm.scheduled_period_'.$boundary)<p class="text-error">{{ $message }}</p>@enderror
                    </div>
                @endforeach
            </div>
        </fieldset>

        @include('livewire.care-plan.parts.activity.grounds-fields', ['inputPrefix' => 'service'])

        <fieldset>
            <legend>{{ __('care-plan.additional_info') }}</legend>
            <div class="form-group group mb-10 max-w-sm">
                <select id="service-expected-result" class="input-select peer" wire:model="activityForm.goal">
                    <option value="">{{ __('forms.select') }}</option>

                    @foreach ($dictionaries['care_plan_activity_goals'] ?? [] as $code => $label)
                        <option value="{{ $code }}">{{ $label }}</option>
                    @endforeach
                </select>
                <label for="service-expected-result" class="label">{{ __('care-plan.expected_result') }}</label>
            </div>
            <label for="service-description" class="mb-3 block text-sm">{{ __('care-plan.extended_description') }}</label>
            <textarea id="service-description" class="care-plan-service-description" rows="5" placeholder="{{ __('care-plan.description') }}" wire:model="activityForm.description"></textarea>
        </fieldset>

        <div class="flex flex-wrap gap-4 pb-8">
            <button type="button" class="button-minor" @click="showServiceDrawer = false">{{ __('forms.cancel') }}</button>
            <button type="submit" class="button-primary" wire:loading.attr="disabled" wire:target="saveActivity">{{ __('care-plan.add_prescription') }}</button>
        </div>
    </form>
</x-dialog-drawer>
