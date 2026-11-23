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

    {{-- Effective type: a moment or a period --}}
    <div class="form-row-3">
        <div class="form-group group">
            <select
                x-model="modalClinicalImpression.effectiveType"
                @change="setEffectiveType($event.target.value)"
                id="clinicalImpressionEffectiveType"
                class="input-select peer"
                required
            >
                <option value="" selected>{{ __('forms.select') }}</option>
                <option value="period">{{ __('clinical-impressions.effective_period') }}</option>
                <option value="date_time">{{ __('clinical-impressions.effective_date_time') }}</option>
            </select>

            <label for="clinicalImpressionEffectiveType" class="label">
                {{ __('clinical-impressions.effective_type') }}
            </label>
        </div>
    </div>

    {{-- Effective date and time --}}
    <div class="form-row-3" x-show="modalClinicalImpression.effectiveType === 'date_time'" x-cloak>
        <div class="form-group group">
            <x-forms.date
                x-model="modalClinicalImpression.effectiveDate"
                datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                name="effectiveDate"
                id="clinicalImpressionEffectiveDate"
                x-bind:required="modalClinicalImpression.effectiveType === 'date_time'"
                :label="__('clinical-impressions.effective_date_time')"
            />
        </div>

        <div class="form-group group w-1/2!">
            <x-forms.time
                x-model="modalClinicalImpression.effectiveTime"
                name="effectiveTime"
                id="clinicalImpressionEffectiveTime"
                x-bind:required="modalClinicalImpression.effectiveType === 'date_time'"
                :label="__('forms.time')"
            />
        </div>
    </div>

    <div x-show="modalClinicalImpression.effectiveType === 'period'" x-cloak>
        {{-- Start effective period datetime --}}
        <div class="form-row-3">
            <div class="form-group group">
                <x-forms.date
                    x-model="modalClinicalImpression.effectivePeriodStartDate"
                    datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                    name="effectivePeriodStartDate"
                    id="effectivePeriodStartDate"
                    x-bind:required="modalClinicalImpression.effectiveType === 'period'"
                    :label="__('clinical-impressions.effective_period_start')"
                />
            </div>

            <div class="form-group group w-1/2!">
                <x-forms.time
                    x-model="modalClinicalImpression.effectivePeriodStartTime"
                    name="effectivePeriodStartTime"
                    id="effectivePeriodStartTime"
                    x-bind:required="modalClinicalImpression.effectiveType === 'period'"
                    :label="__('forms.time')"
                />
            </div>
        </div>

        {{-- End effective period datetime, left empty while the impression is ongoing --}}
        <div class="form-row-3">
            <div class="form-group group">
                <x-forms.date
                    x-model="modalClinicalImpression.effectivePeriodEndDate"
                    datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                    name="effectivePeriodEndDate"
                    id="effectivePeriodEndDate"
                    :label="__('clinical-impressions.effective_period_end')"
                />
            </div>

            <div class="form-group group w-1/2!">
                <x-forms.time
                    x-model="modalClinicalImpression.effectivePeriodEndTime"
                    name="effectivePeriodEndTime"
                    id="effectivePeriodEndTime"
                    :label="__('forms.time')"
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
