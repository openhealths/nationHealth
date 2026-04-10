<fieldset class="fieldset">
    <legend class="legend">
        {{ __('forms.additional_info') }}
    </legend>

    <div class="form-row-modal">
        <div>
            <label for="observationMethod" class="label-modal">
                {{ __('patients.observation_method') }}
            </label>
            <select x-model="modalObservation.method.coding[0].code"
                    id="observationMethod"
                    class="input-modal"
                    type="text"
                    required
            >
                <option selected>{{ __('forms.select') }}</option>
                @foreach($this->dictionaries['eHealth/observation_methods'] as $key => $observationMethod)
                    <option value="{{ $key }}">{{ $observationMethod }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="observationInterpretation" class="label-modal">
                {{ __('patients.interpretation_of_observation') }}
            </label>
            <select x-model="modalObservation.interpretation.coding[0].code"
                    id="observationInterpretation"
                    class="input-modal"
                    type="text"
                    required
            >
                <option selected>{{ __('forms.select') }}</option>
                @foreach($this->dictionaries['eHealth/observation_interpretations'] as $key => $observationInterpretation)
                    <option value="{{ $key }}">{{ $observationInterpretation }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="form-row-modal">
        <div>
            <label for="bodySite" class="label-modal">
                {{ __('patients.body_part') }}
            </label>
            <select x-model="modalObservation.bodySite.coding[0].code"
                    id="bodySite"
                    class="input-modal"
                    type="text"
                    required
            >
                <option selected>{{ __('forms.select') }}</option>
                @foreach($this->dictionaries['eHealth/body_sites'] as $key => $bodySite)
                    <option value="{{ $key }}">{{ $bodySite }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="form-row-4">
        <div>
            <label for="effectiveDate" class="label-modal">
                {{ __('patients.date_and_time_of_receiving_the_indicators') }}
            </label>
            <div class="relative flex items-center">
                @icon('calendar-week', 'w-5 h-5 svg-input absolute left-2.5 pointer-events-none')
                <input x-model="modalObservation.effectiveDate"
                       datepicker-max-date="{{ now()->format('Y-m-d') }}"
                       type="text"
                       name="effectiveDate"
                       id="effectiveDate"
                       class="datepicker-input input-modal !pl-10"
                       autocomplete="off"
                       required
                >
            </div>
        </div>

        <div class="w-3/5" onclick="document.getElementById('effectiveTime').showPicker()">
            <label for="effectiveTime" class="hidden">
                {{ __('patients.time') }}
            </label>

            <div class="relative flex items-center mt-7">
                @icon('mingcute-time-fill', 'svg-input left-2.5')
                <input x-model="modalObservation.effectiveTime"
                       @input="$event.target.blur()"
                       datepicker-max-date="{{ now()->format('Y-m-d') }}"
                       type="time"
                       name="effectiveTime"
                       id="effectiveTime"
                       class="input-modal !pl-10"
                       autocomplete="off"
                       required
                >
            </div>
        </div>

        <div>
            <label for="issuedDate" class="label-modal">
                {{ __('patients.date_time_entered') }}
            </label>
            <div class="relative flex items-center">
                @icon('calendar-week', 'w-5 h-5 svg-input absolute left-2.5 pointer-events-none')
                <input x-model="modalObservation.issuedDate"
                       datepicker-max-date="{{ now()->format('Y-m-d') }}"
                       type="text"
                       name="issuedDate"
                       id="issuedDate"
                       class="datepicker-input input-modal !pl-10"
                       autocomplete="off"
                       required
                >
            </div>

            <p class="text-error text-xs" x-show="modalObservation.issuedDate.trim() === ''">
                {{ __('forms.field_empty') }}
            </p>
        </div>

        <div class="w-3/5" onclick="document.getElementById('issuedTime').showPicker()">
            <label for="issuedTime" class="hidden">
                {{ __('patients.time') }}
            </label>

            <div class="relative flex items-center mt-7">
                @icon('mingcute-time-fill', 'svg-input left-2.5')
                <input x-model="modalObservation.issuedTime"
                       @input="$event.target.blur()"
                       datepicker-max-date="{{ now()->format('Y-m-d') }}"
                       type="time"
                       name="issuedTime"
                       id="issuedTime"
                       class="input-modal !pl-10"
                       autocomplete="off"
                       required
                >
            </div>

            <p class="text-error text-xs" x-show="modalObservation.issuedTime.trim() === ''">
                {{ __('forms.field_empty') }}
            </p>
        </div>
    </div>

    <div class="form-row">
        <div>
            <label for="observationComment" class="label-modal">
                {{ __('forms.comment') }}
            </label>

            <textarea rows="4"
                      x-model="modalObservation.comment"
                      id="observationComment"
                      name="observationComment"
                      class="textarea"
                      placeholder="{{ __('forms.write_comment_here') }}"
            ></textarea>
        </div>
    </div>
</fieldset>
