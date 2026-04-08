<fieldset class="fieldset">
    <legend class="legend">
        {{ __('care-plan.care_plan_data') }}
    </legend>

    <div class="form-row-2">
        <div class="form-group group">
            <label for="clinical_protocol" class="label">
                {{ __('care-plan.clinical_protocol') }}
            </label>
            <input type="text"
                   name="clinical_protocol"
                   id="clinical_protocol"
                   class="input peer"
                   placeholder="R53.0"
                   wire:model="form.clinical_protocol"
            >
            @error('form.clinical_protocol')
            <p class="text-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="form-group group">
            <label for="context" class="label">
                {{ __('care-plan.context') }}
            </label>
            <select id="context"
                    name="context"
                    class="input-select peer"
                    wire:model="form.context"
            >
                <option value="">{{ __('forms.select') }}</option>
                @isset($dictionaries['encounter_classes'])
                    @foreach($dictionaries['encounter_classes'] as $code => $description)
                        <option value="{{ $code }}">{{ $description }}</option>
                    @endforeach
                @endisset
            </select>
            @error('form.context')
            <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="form-row-2 mt-5">
        <div class="form-group group">
            <label for="category" class="label">
                {{ __('care-plan.category') }}
            </label>

            <select id="category"
                    name="category"
                    class="input-select peer"
                    wire:model="form.category"
            >
                <option value="">{{ __('forms.select') }}</option>
                @foreach($categories as $code => $description)
                    <option value="{{ $code }}">{{ $description }}</option>
                @endforeach
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
                {{ __('care-plan.name_care_plan') }}
            </label>
            @error('form.title')
            <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="form-row-2">
        <div class="form-group group">
            <label for="intent" class="label">
                {{ __('care-plan.intention') }}
            </label>

            <select id="intent"
                    name="intent"
                    class="input-select peer"
                    wire:model="form.intent"
            >
                <option value="order">{{ __('care-plan.order') ?? 'Призначення' }}</option>
                <option value="proposal">{{ __('care-plan.proposal') ?? 'Пропозиція' }}</option>
                <option value="plan">{{ __('care-plan.plan') ?? 'План' }}</option>
            </select>

            @error('form.intent')
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
                {{ __('care-plan.date_and_time_start') }}
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
                {{ __('care-plan.date_and_time_end') }}
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
                <p class="font-semibold text-red-700">{{ __('care-plan.attention') }}</p>
            </div>
            <p class="text-sm text-red-700">{{ __('care-plan.you_specify_the_end_date') }}</p>
        </div>
    </div>
    @endif
</fieldset>
