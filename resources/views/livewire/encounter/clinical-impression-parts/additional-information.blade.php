<fieldset class="fieldset">
    <legend class="legend">
        {{ __('forms.additional_info') }}
    </legend>

    {{-- Recorded by --}}
    <div class="form-row-2">
        <div class="form-group group">
            <input type="text"
                   name="assessor"
                   id="assessor"
                   class="input-select peer"
                   placeholder=" "
                   autocomplete="off"
                   disabled
                   value="{{ $employeeFullName }}"
            >

            <label for="assessor" class="label">
                {{ __('patients.employee_who_created') }}
            </label>
        </div>
    </div>

    {{-- Start effective period datetime --}}
    <div class="form-row-3">
        <div class="form-group group">
            <div class="datepicker-wrapper">
                <input x-model="modalClinicalImpression.effectivePeriodStartDate"
                       datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                       type="text"
                       name="effectivePeriodStartDate"
                       id="effectivePeriodStartDate"
                       class="datepicker-input with-leading-icon input peer"
                       placeholder=" "
                       required
                       autocomplete="off"
                >
                <label for="effectivePeriodStartDate" class="wrapped-label">
                    {{ __('patients.reception_start_date_and_time') }}
                </label>
            </div>
        </div>

        <div class="form-group group !w-1/2" onclick="document.getElementById('effectivePeriodStartTime').showPicker()">
            <div class="relative flex items-center">
                @icon('mingcute-time-fill', 'svg-input left-2.5')
                <input x-model="modalClinicalImpression.effectivePeriodStartTime"
                       @input="$event.target.blur()"
                       datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                       type="time"
                       name="effectivePeriodStartTime"
                       id="effectivePeriodStartTime"
                       class="input peer !pl-10"
                       autocomplete="off"
                       required
                >
            </div>
        </div>
    </div>

    {{-- End effective period datetime --}}
    <div class="form-row-3">
        <div class="form-group group">
            <div class="datepicker-wrapper">
                <input x-model="modalClinicalImpression.effectivePeriodEndDate"
                       datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                       type="text"
                       name="effectivePeriodEndDate"
                       id="effectivePeriodEndDate"
                       class="datepicker-input with-leading-icon input peer"
                       placeholder=" "
                       required
                       autocomplete="off"
                >
                <label for="effectivePeriodEndDate" class="wrapped-label">
                    {{ __('patients.reception_end_date_and_time') }}
                </label>
            </div>
        </div>

        <div class="form-group group !w-1/2" onclick="document.getElementById('effectivePeriodEndTime').showPicker()">
            <div class="relative flex items-center">
                @icon('mingcute-time-fill', 'svg-input left-2.5')
                <input x-model="modalClinicalImpression.effectivePeriodEndTime"
                       @input="$event.target.blur()"
                       datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                       type="time"
                       name="effectivePeriodEndTime"
                       id="effectivePeriodEndTime"
                       class="input peer !pl-10"
                       autocomplete="off"
                       required
                >
            </div>
        </div>
    </div>

    <div class="form-row">
        <div>
            <label for="note" class="label-modal">
                {{ __('forms.description') }}
            </label>
            <div>
                <textarea rows="4"
                          x-model="modalClinicalImpression.note"
                          id="note"
                          name="note"
                          class="textarea"
                          placeholder="{{ __('forms.write_comment_here') }}"
                ></textarea>
            </div>
        </div>
    </div>
</fieldset>
