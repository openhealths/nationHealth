<fieldset class="fieldset">
    <legend class="legend">
        {{ __('forms.additional_info') }}
    </legend>

    @if($context === 'encounter')
        {{-- Information source (doctor or patient) --}}
        <div class="flex gap-20 mb-8">
            <h2 class="default-p font-bold">{{ __('patients.information_source') }}</h2>
            {{-- Doctor --}}
            <div class="flex items-center">
                <input x-model.boolean="modalDiagnosticReport.primarySource"
                       id="performer"
                       type="radio"
                       value="true"
                       name="primarySource"
                       class="default-radio"
                       :checked="modalDiagnosticReport.primarySource === true"
                >
                <label for="performer" class="ms-2 text-sm font-medium text-gray-900 dark:text-gray-300">
                    {{ __('patients.performer') }}
                </label>
            </div>

            {{-- Patient --}}
            <div class="flex items-center">
                <input x-model.boolean="modalDiagnosticReport.primarySource"
                       id="patient"
                       type="radio"
                       value="false"
                       name="primarySource"
                       class="default-radio"
                       :checked="modalDiagnosticReport.primarySource === false"
                >
                <label for="patient" class="ms-2 text-sm font-medium text-gray-900 dark:text-gray-300">
                    {{ __('forms.patient') }}
                </label>
            </div>
        </div>

        {{-- When patient selected --}}
        <div x-show="modalDiagnosticReport.primarySource === false" x-transition>
            <div class="form-row-3">
                <div>
                    <label for="reportOrigin" class="label-modal">
                        {{ __('patients.source_link') }}
                    </label>
                    <select x-model="modalDiagnosticReport.reportOrigin.coding[0].code"
                            class="input-select peer"
                            id="reportOrigin"
                            type="text"
                            required
                    >
                        <option value="" selected>{{ __('forms.select') }}</option>
                        @foreach($this->dictionaries['eHealth/report_origins'] as $key => $reportOrigin)
                            <option value="{{ $key }}">{{ $reportOrigin }}</option>
                        @endforeach
                    </select>

                    <p class="text-error text-xs"
                       x-show="!Object.keys($wire.dictionaries['eHealth/report_origins']).includes(modalDiagnosticReport.reportOrigin.coding[0].code)"
                    >
                        {{ __('forms.field_empty') }}
                    </p>
                </div>
            </div>
        </div>
    @endif

    @if($context === 'diagnostic-report')
        <div class="form-row-2">
            <div class="form-group group">
                <select x-model="modalDiagnosticReport.division.identifier.value"
                        @if(count($divisions) === 1)
                            {{-- Set division by default if only one exist --}}
                            x-init="modalDiagnosticReport.division.identifier.value = '{{ $divisions[0]['uuid'] }}';"
                        @endif
                        id="divisionNames"
                        class="input-select peer"
                        type="text"
                >
                    <option value="" selected>
                        {{ __('forms.select') }} {{ mb_strtolower(__('forms.division_name')) }}
                    </option>
                    @foreach($divisions as $key => $division)
                        <option value="{{ $division['uuid'] }}">{{ $division['name'] }}</option>
                    @endforeach
                </select>

                @error('form.diagnosticReport.division.identifier.value')
                <p class="text-error">{{ $message }}</p>
                @enderror
            </div>
        </div>
    @endif

    {{-- Result interpreter --}}
    <div class="form-row-2">
        <div class="form-group group">
            <select x-model="modalDiagnosticReport.resultsInterpreter.reference.identifier.value"
                    id="resultsInterpreter"
                    class="input-select peer"
                    type="text"
            >
                <option value="" selected>
                    {{ __('forms.select') }} {{ mb_strtolower(__('patients.the_doctor_who_interpreted_the_results')) }}
                </option>
                @foreach($employees as $key => $employee)
                    <option value="{{ $employee['uuid'] }}">
                        {{ $employee['name'] }} - {{ $dictionaries['POSITION'][$employee['position']] }}
                    </option>
                @endforeach
            </select>

            @error('form.diagnosticReport.resultsInterpreter.reference.identifier.value')
            <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    {{-- Recorded by --}}
    <div class="form-row-2">
        <div class="form-group group">
            <input type="text"
                   name="recordedBy"
                   id="recordedBy"
                   class="input-select peer"
                   placeholder=" "
                   autocomplete="off"
                   disabled
                   value="{{ $employeeFullName }}"
            >

            <label for="recordedBy" class="label">
                {{ __('patients.doctor_submitting_a_report_to_the_system') }}
            </label>
        </div>
    </div>

    {{-- Issued datetime --}}
    <div class="form-row-3">
        <div class="form-group group">
            <div class="datepicker-wrapper">
                <input x-model="modalDiagnosticReport.issuedDate"
                       datepicker-max-date="{{ now()->format('Y-m-d') }}"
                       type="text"
                       name="issuedDate"
                       id="issuedDate"
                       class="datepicker-input with-leading-icon input peer"
                       placeholder=" "
                       required
                       autocomplete="off"
                >
                <label for="issuedDate" class="wrapped-label">
                    {{ __('patients.date_time_entered') }}
                </label>

                @error('form.diagnosticReport.issuedDate')
                <p class="text-error">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <div class="form-group group !w-1/2" onclick="document.getElementById('issuedTime').showPicker()">
            <div class="relative flex items-center">
                @icon('mingcute-time-fill', 'svg-input left-2.5')
                <input x-model="modalDiagnosticReport.issuedTime"
                       @input="$event.target.blur()"
                       datepicker-max-date="{{ now()->format('Y-m-d') }}"
                       type="time"
                       name="issuedTime"
                       id="issuedTime"
                       class="input peer !pl-10"
                       autocomplete="off"
                       required
                >
            </div>

            @error('form.diagnosticReport.issuedTime')
            <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    {{-- Start effective period datetime --}}
    <div class="form-row-3">
        <div class="form-group group">
            <div class="datepicker-wrapper">
                <input x-model="modalDiagnosticReport.effectivePeriodStartDate"
                       datepicker-max-date="{{ now()->format('Y-m-d') }}"
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

                @error('form.diagnosticReport.effectivePeriodStartDate')
                <p class="text-error">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <div class="form-group group !w-1/2" onclick="document.getElementById('effectivePeriodStartTime').showPicker()">
            <div class="relative flex items-center">
                @icon('mingcute-time-fill', 'svg-input left-2.5')
                <input x-model="modalDiagnosticReport.effectivePeriodStartTime"
                       @input="$event.target.blur()"
                       datepicker-max-date="{{ now()->format('Y-m-d') }}"
                       type="time"
                       name="effectivePeriodStartTime"
                       id="effectivePeriodStartTime"
                       class="input peer !pl-10"
                       autocomplete="off"
                       required
                >
            </div>

            @error('form.diagnosticReport.effectivePeriodStartTime')
            <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    {{-- End effective period datetime --}}
    <div class="form-row-3">
        <div class="form-group group">
            <div class="datepicker-wrapper">
                <input x-model="modalDiagnosticReport.effectivePeriodEndDate"
                       datepicker-max-date="{{ now()->format('Y-m-d') }}"
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

                @error('form.diagnosticReport.effectivePeriodEndDate')
                <p class="text-error">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <div class="form-group group !w-1/2" onclick="document.getElementById('effectivePeriodEndTime').showPicker()">
            <div class="relative flex items-center">
                @icon('mingcute-time-fill', 'svg-input left-2.5')
                <input x-model="modalDiagnosticReport.effectivePeriodEndTime"
                       @input="$event.target.blur()"
                       datepicker-max-date="{{ now()->format('Y-m-d') }}"
                       type="time"
                       name="effectivePeriodEndTime"
                       id="effectivePeriodEndTime"
                       class="input peer !pl-10"
                       autocomplete="off"
                       required
                >
            </div>

            @error('form.diagnosticReport.effectivePeriodEndTime')
            <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>
</fieldset>
