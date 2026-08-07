<fieldset class="fieldset !mb-6 !max-w-full !rounded-xl !border-gray-100 bg-white !p-6 !shadow-none dark:!border-gray-700 dark:bg-gray-800">
    <legend class="legend">{{ __('care-plan.care_plan_data') }}</legend>

    <div class="form-row-2">
        <div class="form-group group">
            <select id="context" name="context" class="input-select peer" wire:model="form.context">
                <option value="">{{ __('forms.select') }} ...</option>
                @php
                    $encounterClasses = $dictionaries['encounter_classes'] ?? $dictionaries['eHealth/encounter_classes'] ?? [];
                @endphp
                @foreach ($encounterClasses as $key => $encounterClass)
                    <option value="{{ $key }}">{{ $encounterClass }}</option>
                @endforeach
            </select>
            <label for="context" class="label"> {{ __('care-plan.context') }} </label>
            @error('form.context')
                <p class="text-error" id="error-form-context">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="form-row-2 mt-5">
        <div class="form-group group">
            <select id="category" name="category" class="input-select peer" wire:model="form.category" required>
                <option value="">{{ __('forms.select') }} ...</option>
                @foreach ($categories as $categoryCode => $categoryName)
                    <option value="{{ $categoryCode }}">{{ $categoryName }}</option>
                @endforeach
            </select>
            <label for="category" class="label"> {{ __('care-plan.category') }} </label>
            @error('form.category')
                <p class="text-error" id="error-form-category">{{ $message }}</p>
            @enderror
        </div>

        <div class="form-group group">
            <input
                type="text"
                name="title"
                id="title"
                class="input peer"
                placeholder=" "
                autocomplete="off"
                wire:model="form.title"
                required
            />
            <label for="title" class="label"> {{ __('care-plan.name_care_plan') }} </label>
            @error('form.title')
                <p class="text-error" id="error-form-title">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="form-row-2 mt-5">
        <div class="form-group group">
            <select id="intent" name="intent" class="input-select peer" wire:model="form.intent" required>
                <option value="order">{{ __('care-plan.assignment') }}</option>
                <option value="proposal">{{ __('care-plan.proposal') }}</option>
                <option value="plan">{{ __('care-plan.plan') }}</option>
            </select>
            <label for="intent" class="label"> {{ __('care-plan.intention') }} </label>
            @error('form.intent')
                <p class="text-error" id="error-form-intent">{{ $message }}</p>
            @enderror
        </div>

        <div class="form-group group">
            <select
                id="termsOfService"
                name="termsOfService"
                class="input-select peer"
                wire:model.live="form.termsOfService"
                required
            >
                <option value="">{{ __('forms.select') }} ...</option>
                @php
                    $providingConditions = $dictionaries['PROVIDING_CONDITION'] ?? [];
                @endphp
                @foreach ($providingConditions as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
            <label for="termsOfService" class="label">
                {{ __('forms.providing_condition') ?? __('care-plan.terms_of_service') }}
            </label>
            @error('form.termsOfService')
                <p class="text-error" id="error-form-termsOfService">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="form-row-2 mt-5">
        <div class="form-group group">
            <div class="datepicker-wrapper">
                <input
                    wire:model.lazy="form.periodStart"
                    type="text"
                    name="period_start"
                    id="period_start"
                    class="datepicker-input with-leading-icon input peer dark:text-white @error('form.periodStart') input-error @enderror"
                    placeholder=" "
                    required
                    autocomplete="off"
                    datepicker-autohide
                    datepicker-format="{{ frontendDateFormat() }}"
                />
                <label for="period_start" class="wrapped-label"> {{ __('care-plan.date_and_time_start') }} </label>
            </div>
            @error('form.periodStart')
                <p class="text-error mt-1 text-xs" id="error-form-period-start">{{ $message }}</p>
            @enderror
        </div>

        <div class="form-group group">
            <div class="datepicker-wrapper">
                <input
                    wire:model.lazy="form.periodEnd"
                    type="text"
                    name="period_end"
                    id="period_end"
                    class="datepicker-input with-leading-icon input peer dark:text-white @error('form.periodEnd') input-error @enderror"
                    placeholder=" "
                    autocomplete="off"
                    datepicker-autohide
                    datepicker-format="{{ frontendDateFormat() }}"
                />
                <label for="period_end" class="wrapped-label"> {{ __('care-plan.date_and_time_end') }} </label>
            </div>
            @error('form.periodEnd')
                <p class="text-error mt-1 text-xs" id="error-form-period-end">{{ $message }}</p>
            @enderror
        </div>
    </div>

    {{-- Warning message (purely frontend) --}}
    <div x-data="{ show: true }" x-show="show" class="relative mt-4 rounded-lg bg-red-50 p-4 dark:bg-red-900/20">
        <div class="flex items-center gap-3 pr-8">
            <div class="flex-shrink-0">
                @icon('alert-circle', 'w-5 h-5 text-red-700 dark:text-red-400')
            </div>
            <div>
                <p class="font-bold text-red-700 dark:text-red-400">{{ __('care-plan.attention') }}</p>
                <p class="mt-1 text-sm text-red-700 dark:text-red-400">
                    {{ __('care-plan.you_specify_the_end_date') }}
                </p>
            </div>
        </div>
        <button
            type="button"
            @click="show = false"
            class="absolute top-4 right-4 text-red-700 transition-opacity hover:opacity-75 dark:text-red-400"
        >
            @icon('close', 'w-4 h-4')
        </button>
    </div>
</fieldset>
