<div class="p-5 space-y-6">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="form-group group">
            <div class="relative">
                <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none ps-3">
                    @icon('calendar-week', 'w-5 h-5 text-gray-400')
                </div>
                <input wire:model="form.encounter.periodDate"
                       datepicker-max-date="{{ now()->format('Y-m-d') }}"
                       datepicker-autoselect-today
                       type="text"
                       name="date"
                       id="date"
                       class="datepicker-input with-leading-icon input peer @error('form.encounter.periodDate') input-error @enderror"
                       placeholder=" "
                       required
                       autocomplete="off"
                >
                <label for="date" class="wrapped-label required">
                    {{ __('forms.date') }}
                </label>
            </div>
            @error('form.encounter.periodDate')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="form-group group">
            <div class="relative">
                <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none ps-3">
                    @icon('mingcute-time-fill', 'w-5 h-5 text-gray-400')
                </div>
                <input wire:model="form.encounter.periodStart"
                       type="text"
                       name="periodStart"
                       id="periodStart"
                       class="timepicker-uk with-leading-icon input peer @error('form.encounter.periodStart') input-error @enderror"
                       placeholder=" "
                       required
                       autocomplete="off"
                />
                <label for="periodStart" class="wrapped-label required">
                    {{ __('patients.period_start') }}
                </label>
            </div>
            @error('form.encounter.periodStart')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="form-group group">
            <div class="relative">
                <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none ps-3">
                    @icon('mingcute-time-fill', 'w-5 h-5 text-gray-400')
                </div>
                <input wire:model="form.encounter.periodEnd"
                       type="text"
                       name="periodEnd"
                       id="periodEnd"
                       class="timepicker-uk with-leading-icon input peer @error('form.encounter.periodEnd') input-error @enderror"
                       placeholder=" "
                       required
                       autocomplete="off"
                />
                <label for="periodEnd" class="wrapped-label required">
                    {{ __('patients.period_end') }}
                </label>
            </div>
            @error('form.encounter.periodEnd')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="form-group group">
        <select wire:model="form.encounter.divisionId"
                id="divisionNames"
                class="input-select peer @error('form.encounter.divisionId') input-error @enderror"
        >
            <option value="" selected>
                {{ __('forms.select') }} {{ mb_strtolower(__('forms.division_name')) }}
            </option>
            @foreach($divisions as $key => $division)
                <option value="{{ $division['uuid'] }}">{{ $division['name'] }}</option>
            @endforeach
        </select>
        <label for="divisionNames" class="label">
            {{ __('forms.division_name') }}
        </label>
        @error('form.encounter.divisionId')
            <p class="text-error">{{ $message }}</p>
        @enderror
    </div>

    <div class="form-group group">
        <select wire:model="form.encounter.priorityCode"
                id="priority"
                class="input-select peer @error('form.encounter.priorityCode') input-error @enderror"
                required
        >
            <option value="" selected>{{ __('forms.select') }} {{ mb_strtolower(__('patients.priority')) }}</option>
            @foreach($this->dictionaries['eHealth/encounter_priority'] as $key => $encounterPriority)
                <option value="{{ $key }}">{{ $encounterPriority }}</option>
            @endforeach
        </select>
        <label for="priority" class="label required">
            {{ __('patients.priority') }}
        </label>
        @error('form.encounter.priorityCode')
            <p class="text-error">{{ $message }}</p>
        @enderror
    </div>

    <div class="space-y-2">
        <label class="text-[13px] font-medium text-gray-500 dark:text-gray-400 ml-1">
            {{ __('patients.assignments') }}
        </label>
        <textarea
            class="w-full min-h-[120px] p-4 text-[15px] text-gray-900 dark:text-white bg-gray-50/50 dark:bg-gray-800/50 border border-gray-200 dark:border-gray-700 rounded-xl focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all outline-none resize-none"
            placeholder="Напишіть призначення тут"
        ></textarea>
    </div>

    <div class="space-y-3">
        <div class="form-group group">
            <select class="input-select peer">
                <option value="" selected>Пошук послуг</option>
            </select>
            <label class="label">Послуги</label>
        </div>
        <button type="button" class="text-blue-600 dark:text-blue-400 hover:text-blue-700 font-medium text-sm transition-colors ml-1">
            + Додати послугу
        </button>
    </div>

    <div class="space-y-3">
        <div class="form-group group">
            <select class="input-select peer">
                <option value="" selected>Знайти лікаря</option>
            </select>
            <label class="label">Співавтор</label>
        </div>
        <button type="button" class="text-blue-600 dark:text-blue-400 hover:text-blue-700 font-medium text-sm transition-colors ml-1">
            + Додати співавтора
        </button>
    </div>

    <div class="pt-4 border-t border-gray-100 dark:border-gray-700 space-y-4">
        <h3 class="text-[15px] font-bold text-gray-900 dark:text-white">
            Медичні записи, на які посилається взаємодія
        </h3>
        <button type="button" class="text-blue-600 dark:text-blue-400 hover:text-blue-700 font-medium text-sm transition-colors">
            + Додати медичні спостереження, діагностичні звіти або стани
        </button>
    </div>
</div>
