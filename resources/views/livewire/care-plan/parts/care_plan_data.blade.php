@php 
    $categories = $categories ?? $dictionaries['care_plan_categories'] ?? []; 
    $intent = array_key_exists('intent', $carePlan->getAttributes()) ? $carePlan->intent : 'order';
    $categoryVal = array_key_exists('category', $carePlan->getAttributes()) ? $carePlan->category : ($carePlan->categoryConcept?->code ?? '');
    $contextVal = $carePlan->context ?? $carePlan->encounter?->class ?? '';
@endphp

<fieldset class="fieldset bg-white dark:bg-gray-800 !rounded-xl !shadow-none !border-gray-100 dark:!border-gray-700 !max-w-full !p-6 !mb-6">
    <legend class="legend">
        {{ __('care-plan.care_plan_data') }}
    </legend>

    @if($isReadOnly ?? false)
        <div class="form-row-2">
            <div class="form-group group">
                <select id="category" name="category" class="input-select peer" disabled>
                    <option value="">{{ __('forms.select') }}</option>
                    @foreach($categories as $code => $description)
                        <option value="{{ $code }}" @if($categoryVal === $code) selected @endif>{{ $description }}</option>
                    @endforeach
                </select>
                <label for="category" class="label">
                    {{ __('care-plan.category') }}
                </label>
            </div>

            <div class="form-group group">
                <input type="text" name="title" id="title" class="input peer" placeholder=" " value="{{ $carePlan->title }}" disabled>
                <label for="title" class="label required">
                    {{ __('care-plan.name_care_plan') }}
                </label>
            </div>
        </div>

        <div class="form-row-2 mt-5">
            <div class="form-group group">
                <select id="intent" name="intent" class="input-select peer" disabled>
                    <option value="order" @if($intent === 'order') selected @endif>{{ __('care-plan.order') }}</option>
                    <option value="proposal" @if($intent === 'proposal') selected @endif>{{ __('care-plan.proposal') }}</option>
                    <option value="plan" @if($intent === 'plan') selected @endif>{{ __('care-plan.plan') }}</option>
                </select>
                <label for="intent" class="label">
                    {{ __('care-plan.intention') }}
                </label>
            </div>

            <div class="form-group group">
                <select id="context" name="context" class="input-select peer" disabled>
                    <option value="">{{ __('forms.select') }}</option>
                    @isset($dictionaries['encounter_classes'])
                        @foreach($dictionaries['encounter_classes'] as $code => $description)
                            <option value="{{ $code }}" @if($contextVal === $code) selected @endif>{{ $description }}</option>
                        @endforeach
                    @endisset
                </select>
                <label for="context" class="label">
                    {{ __('care-plan.conditions_of_service') }}
                </label>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-5">
            <div class="form-group group">
                <div class="relative">
                    <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none ps-3">
                        @icon('calendar-week', 'w-5 h-5 text-gray-400')
                    </div>
                    <input type="text" name="period_start" id="period_start" class="datepicker-input with-leading-icon input peer" value="{{ $carePlan->period_start ? \Carbon\Carbon::parse($carePlan->period_start)->format(frontendDateFormat()) : '' }}" disabled>
                    <label for="period_start" class="wrapped-label required">
                        {{ __('care-plan.start_date') }}
                    </label>
                </div>
            </div>

            <div class="form-group group">
                <div class="relative">
                    <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none ps-3">
                        @icon('mingcute-time-fill', 'w-5 h-5 text-gray-400')
                    </div>
                    <input type="text" name="period_start_time" id="period_start_time" class="timepicker-uk with-leading-icon input peer" value="{{ $carePlan->period_start ? \Carbon\Carbon::parse($carePlan->period_start)->format('H:i') : '' }}" disabled />
                    <label for="period_start_time" class="wrapped-label required">
                        {{ __('care-plan.time') }}
                    </label>
                </div>
            </div>

            <div class="form-group group">
                <div class="relative">
                    <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none ps-3">
                        @icon('calendar-week', 'w-5 h-5 text-gray-400')
                    </div>
                    <input type="text" name="period_end" id="period_end" class="datepicker-input with-leading-icon input peer" value="{{ $carePlan->period_end ? \Carbon\Carbon::parse($carePlan->period_end)->format(frontendDateFormat()) : '' }}" disabled>
                    <label for="period_end" class="wrapped-label">
                        {{ __('care-plan.end_date') }}
                    </label>
                </div>
            </div>

            <div class="form-group group">
                <div class="relative">
                    <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none ps-3">
                        @icon('mingcute-time-fill', 'w-5 h-5 text-gray-400')
                    </div>
                    <input type="text" name="period_end_time" id="period_end_time" class="timepicker-uk with-leading-icon input peer" value="{{ $carePlan->period_end ? \Carbon\Carbon::parse($carePlan->period_end)->format('H:i') : '' }}" disabled />
                    <label for="period_end_time" class="wrapped-label">
                        {{ __('care-plan.time') }}
                    </label>
                </div>
            </div>
        </div>
    @else
        <div class="form-row-2">
            <div class="form-group group">
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
                <label for="category" class="label">
                    {{ __('care-plan.category') }}
                </label>

                @error('form.category')
                <p class="text-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="form-group group">
                <input type="text"
                       name="title"
                       id="title"
                       class="input peer"
                       placeholder=" "
                       autocomplete="off"
                       wire:model="form.title"
                       required
                >
                <label for="title" class="label required">
                    {{ __('care-plan.name_care_plan') }}
                </label>
                @error('form.title')
                <p class="text-error">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <div class="form-row-2 mt-5">
            <div class="form-group group">
                <select id="intent"
                        name="intent"
                        class="input-select peer"
                        wire:model="form.intent"
                >
                    <option value="order">{{ __('care-plan.order') }}</option>
                    <option value="proposal">{{ __('care-plan.proposal') }}</option>
                    <option value="plan">{{ __('care-plan.plan') }}</option>
                </select>
                <label for="intent" class="label">
                    {{ __('care-plan.intention') }}
                </label>

                @error('form.intent')
                <p class="text-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="form-group group">
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
                <label for="context" class="label">
                    {{ __('care-plan.conditions_of_service') }}
                </label>
                @error('form.context')
                <p class="text-error">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-5">
            {{-- Start Date and Time --}}
            <div class="form-group group">
                <div class="relative">
                    <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none ps-3">
                        @icon('calendar-week', 'w-5 h-5 text-gray-400')
                    </div>
                    <input wire:model.lazy="form.period_start"
                           type="text"
                           name="period_start"
                           id="period_start"
                           class="datepicker-input with-leading-icon input peer @error('form.period_start') input-error @enderror"
                           placeholder=" "
                           required
                           autocomplete="off"
                           datepicker-autohide
                           datepicker-format="{{ frontendDateFormat() }}"
                    >
                    <label for="period_start" class="wrapped-label required">
                        {{ __('care-plan.start_date') }}
                    </label>
                </div>
                @error('form.period_start') <p class="text-error text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="form-group group">
                <div class="relative">
                    <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none ps-3">
                        @icon('mingcute-time-fill', 'w-5 h-5 text-gray-400')
                    </div>
                    <input wire:model.lazy="form.period_start_time"
                           type="text"
                           name="period_start_time"
                           id="period_start_time"
                           class="timepicker-uk with-leading-icon input peer @error('form.period_start_time') input-error @enderror"
                           placeholder=" "
                           required
                           autocomplete="off"
                    />
                    <label for="period_start_time" class="wrapped-label required">
                        {{ __('care-plan.time') }}
                    </label>
                </div>
                @error('form.period_start_time') <p class="text-error text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            {{-- End Date and Time --}}
            <div class="form-group group">
                <div class="relative">
                    <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none ps-3">
                        @icon('calendar-week', 'w-5 h-5 text-gray-400')
                    </div>
                    <input wire:model.lazy="form.period_end"
                           type="text"
                           name="period_end"
                           id="period_end"
                           class="datepicker-input with-leading-icon input peer @error('form.period_end') input-error @enderror"
                           placeholder=" "
                           autocomplete="off"
                           datepicker-autohide
                           datepicker-format="{{ frontendDateFormat() }}"
                    >
                    <label for="period_end" class="wrapped-label">
                        {{ __('care-plan.end_date') }}
                    </label>
                </div>
                @error('form.period_end') <p class="text-error text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="form-group group">
                <div class="relative">
                    <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none ps-3">
                        @icon('mingcute-time-fill', 'w-5 h-5 text-gray-400')
                    </div>
                    <input wire:model.lazy="form.period_end_time"
                           type="text"
                           name="period_end_time"
                           id="period_end_time"
                           class="timepicker-uk with-leading-icon input peer @error('form.period_end_time') input-error @enderror"
                           placeholder=" "
                           autocomplete="off"
                    />
                    <label for="period_end_time" class="wrapped-label">
                        {{ __('care-plan.time') }}
                    </label>
                </div>
                @error('form.period_end_time') <p class="text-error text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- Warning message (purely frontend) --}}
        <div x-data="{ show: true }" x-show="show" class="mt-4 p-4 rounded-lg bg-red-50 dark:bg-red-900/20 relative">
            <div class="flex items-center gap-3 pr-8">
                <div class="flex-shrink-0">
                    @icon('alert-circle', 'w-5 h-5 text-red-700 dark:text-red-400')
                </div>
                <div>
                    <p class="font-bold text-red-700 dark:text-red-400">
                        {{ __('care-plan.attention') }}
                    </p>
                    <p class="text-sm text-red-700 dark:text-red-400 mt-1">
                        {{ __('care-plan.you_specify_the_end_date') }}
                    </p>
                </div>
            </div>
            <button type="button" @click="show = false" class="absolute top-4 right-4 text-red-700 dark:text-red-400 hover:opacity-75 transition-opacity">
                @icon('close', 'w-4 h-4')
            </button>
        </div>
    @endif
</fieldset>
