<div class="form-row-2">
    <div class="form-group group">
        <label for="clinical_protocol" class="label">
            {{ __('care-plan.clinical_protocol') }}
        </label>
        <input type="text"
               name="clinical_protocol"
               id="clinical_protocol"
               class="input peer"
               wire:model="form.clinicalProtocol"
        >
        @error('form.clinicalProtocol')
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
            @isset($dictionaries['eHealth/encounter_classes'])
                @foreach($dictionaries['eHealth/encounter_classes'] as $code => $description)
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

    <div class="form-group group">
        <label for="terms_of_service" class="label">
            {{ __('care-plan.terms_of_service') }}
        </label>
        <select id="terms_of_service"
                name="terms_of_service"
                class="input-select peer"
                wire:model="form.termsOfService"
        >
            <option value="">{{ __('forms.select') }}</option>
            @isset($dictionaries['PROVIDING_CONDITION'])
                @foreach($dictionaries['PROVIDING_CONDITION'] as $code => $description)
                    <option value="{{ $code }}">{{ $description }}</option>
                @endforeach
            @endisset
        </select>
        @error('form.termsOfService')
        <p class="text-error">{{ $message }}</p>
        @enderror
    </div>
</div>

<div class="form-row-2 mt-5">
    <div class="form-group group">
        <div class="relative">
            <input type="text"
                   name="period_start"
                   id="period_start"
                   class="peer input pl-10 appearance-none datepicker-input dark:text-white"
                   placeholder=" "
                   required
                   datepicker-autohide
                   datepicker-format="{{ frontendDateFormat() }}"
                   datepicker-button="false"
                   wire:model.lazy="form.periodStart"
            />
            <label for="period_start" class="label">
                {{ __('care-plan.date_and_time_start') }}
            </label>
            <div class="absolute inset-y-0 start-0 flex items-center ps-3 pointer-events-none">
                @icon('calendar', 'w-4 h-4 text-gray-400')
            </div>
        </div>
        @error('form.periodStart')
        <p class="text-error">{{ $message }}</p>
        @enderror
    </div>

    <div class="form-group group">
        <div class="relative">
            <input type="text"
                   name="period_end"
                   id="period_end"
                   class="peer input pl-10 appearance-none datepicker-input dark:text-white"
                   placeholder=" "
                   datepicker-autohide
                   datepicker-format="{{ frontendDateFormat() }}"
                   datepicker-button="false"
                   wire:model.lazy="form.periodEnd"
            />
            <label for="period_end" class="label">
                {{ __('care-plan.date_and_time_end') }}
            </label>
            <div class="absolute inset-y-0 start-0 flex items-center ps-3 pointer-events-none">
                @icon('calendar', 'w-4 h-4 text-gray-400')
            </div>
        </div>
        @error('form.periodEnd')
        <p class="text-error">{{ $message }}</p>
        @enderror
    </div>
</div>

{{-- Warning shown always when period_end has a value (per TZ 3.10.1.2.4) --}}
@if(!empty($form['periodEnd']))
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
