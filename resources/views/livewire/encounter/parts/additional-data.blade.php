<fieldset class="fieldset" id="additional-data-section">
    <legend class="legend">
        {{ __('patients.additional_data') }}
    </legend>

    <div class="form-row-3">
        <div class="form-group group">
            <div class="datepicker-wrapper">
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
                <label for="date" class="wrapped-label">
                    {{ __('forms.date') }}
                </label>
            </div>

            @error('form.encounter.periodDate')
            <p class="text-error">
                {{ $message }}
            </p>
            @enderror
        </div>

        <div class="form-row-modal">
            <div class="form-group group" onclick="document.getElementById('periodStart').showPicker()">
                @icon('mingcute-time-fill', 'svg-input left-2.5')
                <input wire:model="form.encounter.periodStart"
                       @input="$event.target.blur()"
                       type="time"
                       name="periodStart"
                       id="periodStart"
                       class="input peer !pl-10 @error('form.encounter.periodStart') input-error @enderror"
                       placeholder=" "
                       required
                />
                <label for="periodStart" class="label">
                    {{ __('patients.period_start') }}
                </label>

                @error('form.encounter.periodStart')
                <p class="text-error">
                    {{ $message }}
                </p>
                @enderror
            </div>

            <div class="form-group group" onclick="document.getElementById('periodEnd').showPicker()">
                @icon('mingcute-time-fill', 'svg-input left-2.5')
                <input wire:model="form.encounter.periodEnd"
                       @input="$event.target.blur()"
                       type="time"
                       name="periodEnd"
                       id="periodEnd"
                       class="input peer !pl-10 @error('form.encounter.periodEnd') input-error @enderror"
                       placeholder=" "
                       required
                />
                <label for="periodEnd" class="label">
                    {{ __('patients.period_end') }}
                </label>

                @error('form.encounter.periodEnd')
                <p class="text-error">
                    {{ $message }}
                </p>
                @enderror
            </div>
        </div>
    </div>

    <div class="form-row-3">
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

            @error('form.encounter.divisionId')
            <p class="text-error">
                {{ $message }}
            </p>
            @enderror
        </div>
    </div>

    <div class="form-row-3">
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

            @error('form.encounter.priorityCode')
            <p class="text-error">
                {{ $message }}
            </p>
            @enderror
        </div>
    </div>
</fieldset>
