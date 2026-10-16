<fieldset class="fieldset">
    <legend class="legend">{{ __('forms.additional_info') }}</legend>

    {{-- Recorded by --}}
    <div class="form-row-2">
        <div class="form-group group">
            <input
                type="text"
                name="assessor"
                id="assessor"
                class="input-select peer"
                placeholder=" "
                autocomplete="off"
                disabled
                value="{{ $employeeFullName }}"
            />

            <label for="assessor" class="label"> {{ __('clinical-impressions.assessor') }} </label>
        </div>
    </div>

    {{-- Start effective period datetime --}}
    <div class="form-row-3">
        <div class="form-group group">
            <div class="datepicker-wrapper">
                <input
                    x-model="modalClinicalImpression.effectivePeriodStartDate"
                    datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                    type="text"
                    name="effectivePeriodStartDate"
                    id="effectivePeriodStartDate"
                    class="datepicker-input with-leading-icon input peer"
                    placeholder=" "
                    required
                    autocomplete="off"
                />
                <label for="effectivePeriodStartDate" class="wrapped-label">
                    {{ __('clinical-impressions.effective_period_start') }}
                </label>
            </div>
        </div>

        <div class="form-group group !w-1/2" onclick="document.getElementById('effectivePeriodStartTime').showPicker()">
            <label for="effectivePeriodStartTime" class="sr-only">{{ __('forms.time') }}</label>
            <div class="relative flex items-center">
                @icon('mingcute-time-fill', 'svg-input left-2.5')
                <input
                    x-model="modalClinicalImpression.effectivePeriodStartTime"
                    @input="$event.target.blur()"
                    datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                    type="time"
                    name="effectivePeriodStartTime"
                    id="effectivePeriodStartTime"
                    class="input peer !pl-10"
                    autocomplete="off"
                    required
                />
            </div>
        </div>
    </div>

    {{-- End effective period datetime --}}
    <div class="form-row-3">
        <div class="form-group group">
            <div class="datepicker-wrapper">
                <input
                    x-model="modalClinicalImpression.effectivePeriodEndDate"
                    datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                    type="text"
                    name="effectivePeriodEndDate"
                    id="effectivePeriodEndDate"
                    class="datepicker-input with-leading-icon input peer"
                    placeholder=" "
                    required
                    autocomplete="off"
                />
                <label for="effectivePeriodEndDate" class="wrapped-label">
                    {{ __('clinical-impressions.effective_period_end') }}
                </label>
            </div>
        </div>

        <div class="form-group group !w-1/2" onclick="document.getElementById('effectivePeriodEndTime').showPicker()">
            <label for="effectivePeriodEndTime" class="sr-only">{{ __('forms.time') }}</label>
            <div class="relative flex items-center">
                @icon('mingcute-time-fill', 'svg-input left-2.5')
                <input
                    x-model="modalClinicalImpression.effectivePeriodEndTime"
                    @input="$event.target.blur()"
                    datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                    type="time"
                    name="effectivePeriodEndTime"
                    id="effectivePeriodEndTime"
                    class="input peer !pl-10"
                    autocomplete="off"
                    required
                />
            </div>
        </div>
    </div>

    <div class="form-row">
        <div>
            <label for="note" class="label-modal"> {{ __('clinical-impressions.note') }} </label>
            <div>
                <textarea
                    rows="4"
                    x-model="modalClinicalImpression.note"
                    id="note"
                    name="note"
                    class="textarea"
                    placeholder="{{ __('forms.write_comment_here') }}"
                ></textarea>
            </div>
        </div>
    </div>

    <div class="form-row">
        <div>
            <label for="clinicalImpressionSummary" class="label-modal">
                {{ __('clinical-impressions.summary') }}
            </label>
            <div>
                <textarea
                    rows="4"
                    x-model="modalClinicalImpression.summary"
                    id="clinicalImpressionSummary"
                    name="summary"
                    class="textarea"
                    placeholder="{{ __('forms.write_comment_here') }}"
                ></textarea>
            </div>
        </div>
    </div>
</fieldset>
