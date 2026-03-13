<fieldset class="fieldset">
    <legend class="legend">
        {{ __('treatment-plan.treatment_plan_data') }}
    </legend>

    <div class="form-row-2">
        <div class="form-group group">
            <label for="category" class="label">
                {{ __('treatment-plan.category') }}
            </label>

            <select id="category"
                    name="category"
                    class="input-select peer"
                    wire:model="form.category"
            >
                <option value="">{{ __('treatment-plan.category') }}</option>
                {{-- Options populated from eHealth dictionary --}}
            </select>

            @error('form.category')
            <p class="text-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="form-group group">
            <input type="text"
                   name="title"
                   id="title"
                   class="input-select peer"
                   placeholder=" "
                   autocomplete="off"
                   wire:model="form.title"
                   required
            >
            <label for="title" class="label">
                {{ __('treatment-plan.name_treatment_plan') }}
            </label>
            @error('form.title')
            <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="form-row-2">
        <div class="form-group group">
            <label for="intent" class="label">
                {{ __('treatment-plan.intention') }}
            </label>

            <select id="intent"
                    name="intent"
                    class="input-select peer"
                    wire:model="form.intent"
            >
                <option value="order">{{ __('forms.select') }}</option>
            </select>

            @error('form.intent')
            <p class="text-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="form-group group">
            <label for="terms_of_service" class="label">
                {{ __('treatment-plan.terms_service') }}
            </label>

            <select id="terms_of_service"
                    name="terms_of_service"
                    class="input-select peer"
                    wire:model="form.terms_of_service"
            >
                <option value="">{{ __('forms.select') }}</option>
                {{-- Values from eHealth dictionary TERMS_OF_SERVICE_TYPES --}}
            </select>

            @error('form.terms_of_service')
            <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="form-row-2 mt-5">
        <div class="form-group group">
            <input type="text"
                   name="period_start"
                   id="period_start"
                   class="peer input pl-10 appearance-none datepicker-input dark:text-white"
                   placeholder=" "
                   required
                   datepicker-autohide
                   datepicker-format="{{ frontendDateFormat() }}"
                   datepicker-button="false"
                   wire:model.lazy="form.period_start"
            />
            <label for="period_start" class="wrapped-label">
                {{ __('treatment-plan.date_and_time_start') }}
            </label>
            @error('form.period_start')
            <p class="text-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="form-group group">
            <input type="text"
                   name="period_end"
                   id="period_end"
                   class="peer input pl-10 appearance-none datepicker-input dark:text-white"
                   placeholder=" "
                   datepicker-autohide
                   datepicker-format="{{ frontendDateFormat() }}"
                   datepicker-button="false"
                   wire:model.lazy="form.period_end"
            />
            <label for="period_end" class="wrapped-label">
                {{ __('treatment-plan.date_and_time_end') }}
            </label>
            @error('form.period_end')
            <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    {{-- Warning shown always when period_end has a value (per TZ 3.10.1.2.4) --}}
    @if(!empty($form['period_end']))
    <div class="bg-red-100 rounded-lg mt-4">
        <div class="p-4">
            <div class="flex items-center gap-2 mb-2">
                @icon('alert-circle', 'w-5 h-5 text-red-700')
                <p class="font-semibold text-red-700">{{ __('treatment-plan.attention') }}</p>
            </div>
            <p class="text-sm text-red-700">{{ __('treatment-plan.you_specify_the_end_date') }}</p>
        </div>
    </div>
    @endif
</fieldset>
