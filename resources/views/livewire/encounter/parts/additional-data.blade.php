<div class="p-4 sm:p-8" id="additional-data-section">
    <h2 class="text-xl font-bold mb-6 text-gray-900 dark:text-white">
        {{ __('patients.additional_data') }}
    </h2>

    <div class="form-row-3">
        <div class="form-group group">
            <div class="datepicker-wrapper">
                <input wire:model="form.encounter.period.date"
                       datepicker-max-date="{{ now()->format('Y-m-d') }}"
                       datepicker-autoselect-today
                       type="text"
                       name="date"
                       id="date"
                       class="datepicker-input with-leading-icon input peer @error('form.encounter.period.date') input-error @enderror"
                       placeholder=" "
                       required
                       autocomplete="off"
                >
                <label for="date" class="wrapped-label">
                    {{ __('forms.date') }}
                </label>
            </div>

            @error('form.encounter.period.date')
            <p class="text-error">
                {{ $message }}
            </p>
            @enderror
        </div>

        <div class="form-row-modal">
            <div class="form-group group" onclick="document.getElementById('periodStart').showPicker()">
                @icon('mingcute-time-fill', 'svg-input left-2.5')
                <input wire:model="form.encounter.period.start"
                       @input="$event.target.blur()"
                       type="time"
                       name="periodStart"
                       id="periodStart"
                       class="input peer !pl-10 @error('form.encounter.period.start') input-error @enderror"
                       placeholder=" "
                       required
                />
                <label for="periodStart" class="label">
                    {{ __('patients.period_start') }}
                </label>

                @error('form.encounter.period.start')
                <p class="text-error">
                    {{ $message }}
                </p>
                @enderror
            </div>

            <div class="form-group group" onclick="document.getElementById('periodEnd').showPicker()">
                @icon('mingcute-time-fill', 'svg-input left-2.5')
                <input wire:model="form.encounter.period.end"
                       @input="$event.target.blur()"
                       type="time"
                       name="periodEnd"
                       id="periodEnd"
                       class="input peer !pl-10 @error('form.encounter.period.end') input-error @enderror"
                       placeholder=" "
                       required
                />
                <label for="periodStart" class="label">
                    {{ __('patients.period_end') }}
                </label>

                @error('form.encounter.period.end')
                <p class="text-error">
                    {{ $message }}
                </p>
                @enderror
            </div>
        </div>
    </div>

    <div class="form-row-3">
        <div class="form-group group">
            <select wire:model="form.encounter.division.identifier.value"
                    id="divisionNames"
                    class="input-select peer @error('form.encounter.division.identifier.value') input-error @enderror"
            >
                <option selected value="">
                    {{ __('forms.select') }} {{ mb_strtolower(__('forms.division_name')) }}
                </option>
                @foreach($divisions as $key => $division)
                    <option value="{{ $division['uuid'] }}">{{ $division['name'] }}</option>
                @endforeach
            </select>

            @error('form.encounter.division.identifier.value')
            <p class="text-error">
                {{ $message }}
            </p>
            @enderror
        </div>
    </div>

    <div class="form-row-3">
        <div class="form-group group">
            <select wire:model="form.encounter.priority.coding.0.code"
                    id="priority"
                    class="input-select peer @error('form.encounter.priority.coding.code') input-error @enderror"
                    required
            >
                <option selected>{{ __('forms.select') }} {{ mb_strtolower(__('patients.priority')) }}</option>
                @foreach($this->dictionaries['eHealth/encounter_priority'] as $key => $encounterPriority)
                    <option value="{{ $key }}">{{ $encounterPriority }}</option>
                @endforeach
            </select>

            @error('form.encounter.priority.coding.code')
            <p class="text-error">
                {{ $message }}
            </p>
            @enderror
        </div>
    </div>
</div>
