@php
    $diagnosticReportErrorPath = $diagnosticReportErrorPath
        ?? (($context ?? null) === 'diagnostic-report'
            ? 'form.diagnosticReport'
            : 'form.diagnosticReports.*');
    $isEncounterContext = $isEncounterContext ?? false;
    $diagnosticReportEmployeeOptions = $isEncounterContext ? $diagnosticReportEmployees : $employees;
@endphp
<fieldset class="fieldset">
    <legend class="legend">{{ __('forms.additional_info') }}</legend>

    @if ($isEncounterContext ?? false)
        {{-- Information source (doctor or patient) --}}
        <div class="mb-8 flex gap-20">
            <h2 class="default-p font-bold">{{ __('medical-events.information_source') }}</h2>
            {{-- Doctor --}}
            <div class="flex items-center">
                <input
                    x-model.boolean="modalDiagnosticReport.primarySource"
                    id="performer"
                    type="radio"
                    value="true"
                    name="primarySource"
                    class="default-radio"
                    :checked="modalDiagnosticReport.primarySource === true"
                />
                <label for="performer" class="ms-2 text-sm font-medium text-gray-900 dark:text-gray-300">
                    {{ __('medical-events.performer') }}
                </label>
            </div>

            {{-- Patient --}}
            <div class="flex items-center">
                <input
                    x-model.boolean="modalDiagnosticReport.primarySource"
                    id="patient"
                    type="radio"
                    value="false"
                    name="primarySource"
                    class="default-radio"
                    :checked="modalDiagnosticReport.primarySource === false"
                />
                <label for="patient" class="ms-2 text-sm font-medium text-gray-900 dark:text-gray-300">
                    {{ __('forms.patient') }}
                </label>
            </div>
        </div>

        {{-- When patient selected --}}
        <div x-show="modalDiagnosticReport.primarySource === false" x-transition>
            <div class="form-row-3">
                <div>
                    <label for="reportOrigin" class="label-modal"> {{ __('medical-events.source_link') }} </label>
                    <select
                        x-model="modalDiagnosticReport.reportOriginCode"
                        class="input-select peer"
                        id="reportOrigin"
                        type="text"
                        required
                    >
                        <option value="" selected>{{ __('forms.select') }}</option>
                        @foreach ($this->dictionaries['eHealth/report_origins'] as $key => $reportOrigin)
                            <option value="{{ $key }}">{{ $reportOrigin }}</option>
                        @endforeach
                    </select>

                    <p
                        class="text-error text-xs"
                        x-show="
                            ! Object.keys($wire.dictionaries['eHealth/report_origins']).includes(
                                modalDiagnosticReport.reportOriginCode,
                            )
                        "
                    >
                        {{ __('forms.field_empty') }}
                    </p>
                </div>
            </div>
        </div>
    @endif

    @if ($context === 'diagnostic-report')
        <div class="form-row-2">
            <div class="form-group group">
                <select
                    x-model="modalDiagnosticReport.divisionId"
                    @change="modalDiagnosticReport.usedReferences = []"
                    @if ($isEncounterContext ?? false)
                        x-effect="
                            const encounterDivisionId = $wire.form.encounter.divisionId || '';

                            if (modalDiagnosticReport.divisionId !== encounterDivisionId) {
                                modalDiagnosticReport.divisionId = encounterDivisionId;

                                modalDiagnosticReport.usedReferences = [];
                            }
                        "
                        disabled
                    @elseif (count($divisions) === 1)
                        {{-- Set division by default if only one exists --}}
                        x-init="
                            modalDiagnosticReport.divisionId =
                                '{{ $divisions[0]['uuid'] }}';
                        "
                    @endif
                    id="divisionNames"
                    class="input-select peer"
                    type="text"
                >
                    <option value="" selected>
                        {{ __('forms.select') }} {{ mb_strtolower(__('forms.division_name')) }}
                    </option>
                    @foreach ($divisions as $key => $division)
                        <option value="{{ $division['uuid'] }}">{{ $division['name'] }}</option>
                    @endforeach
                </select>

                @error($diagnosticReportErrorPath . '.divisionId')
                    <p class="text-error">{{ $message }}</p>
                @enderror

                @if ($isEncounterContext ?? false)
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('diagnostic-reports.division_filled_from_encounter') }}
                    </p>
                @endif
            </div>
        </div>
    @endif

    {{-- Performer --}}
    <div class="form-row-2" x-show="modalDiagnosticReport.primarySource === true" x-cloak>
        <div class="form-group group">
            <label for="resultsInterpreter" class="mb-2 block text-sm font-medium text-gray-500 dark:text-gray-400">
                {{ __('diagnostic-reports.interpreting_doctor') }}
            </label>

            <select
                x-model="modalDiagnosticReport.resultsInterpreterEmployeeId"
                @change="
                    modalDiagnosticReport.performerEmployeeIds = modalDiagnosticReport.performerEmployeeIds.filter(
                        (employeeId) =>
                            String(employeeId) !== String(modalDiagnosticReport.resultsInterpreterEmployeeId),
                    )
                "
                id="resultsInterpreter"
                class="input-select peer"
                :required="['diagnostic_procedure', 'imaging'].includes(modalDiagnosticReport.categoryCode)"
            >
                <option value="">{{ __('forms.select') }}</option>

                @foreach ($diagnosticReportEmployeeOptions as $employee)
                    @if (in_array($employee['employeeType'], ['DOCTOR', 'SPECIALIST'], true))
                        <option value="{{ $employee['uuid'] }}">
                            {{ $employee['name'] }} — {{ $this->dictionaries['POSITION'][$employee['position']] ?? $employee['position'] }}
                        </option>
                    @endif
                @endforeach
            </select>

            @error($diagnosticReportErrorPath . '.resultsInterpreterEmployeeId')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    {{-- Performers --}}
    <div class="form-row-2" x-show="modalDiagnosticReport.primarySource === true" x-cloak>
        <div class="form-group group">
            <div x-show="String(modalDiagnosticReport.resultsInterpreterEmployeeId ?? '').trim()" class="mb-5">
                <label class="mb-2 block text-sm font-medium text-gray-500 dark:text-gray-400">
                    {{ ucfirst(__('diagnostic-reports.performer')) }}
                </label>

                <select
                    x-model="modalDiagnosticReport.resultsInterpreterEmployeeId"
                    class="input-select peer !cursor-not-allowed !text-gray-500 dark:!text-gray-400"
                    disabled
                >
                    @foreach ($diagnosticReportEmployeeOptions as $employee)
                        @if (in_array($employee['employeeType'], ['DOCTOR', 'SPECIALIST'], true))
                            <option value="{{ $employee['uuid'] }}">
                                {{ $employee['name'] }} — {{ $this->dictionaries['POSITION'][$employee['position']] ?? $employee['position'] }}
                            </option>
                        @endif
                    @endforeach
                </select>
            </div>

            <template x-for="(performerEmployeeId, index) in modalDiagnosticReport.performerEmployeeIds" :key="index">
                <div class="mb-5">
                    <label class="mb-2 block text-sm font-medium text-gray-500 dark:text-gray-400">
                        {{ ucfirst(__('diagnostic-reports.performer')) }}
                    </label>

                    <div class="flex items-center gap-4">
                        <select
                            x-model="modalDiagnosticReport.performerEmployeeIds[index]"
                            class="input-select peer min-w-0 flex-1"
                        >
                            <option value="">{{ __('forms.select') }}</option>

                            @foreach ($diagnosticReportEmployeeOptions as $employee)
                                <option
                                    value="{{ $employee['uuid'] }}"
                                    :disabled="
                                        String('{{ $employee['uuid'] }}')
                                            === String(modalDiagnosticReport.resultsInterpreterEmployeeId)
                                        || modalDiagnosticReport.performerEmployeeIds.some(
                                            (employeeId, performerIndex) =>
                                                performerIndex !== index
                                                && String(employeeId) === String('{{ $employee['uuid'] }}')
                                        )
                                    "
                                >
                                    {{ $employee['name'] }} — {{ $this->dictionaries['POSITION'][$employee['position']] ?? $employee['position'] }}
                                </option>
                            @endforeach
                        </select>

                        <button
                            type="button"
                            @click.prevent="modalDiagnosticReport.performerEmployeeIds.splice(index, 1)"
                            class="shrink-0"
                        >
                            @icon('delete', 'w-5 h-5')
                        </button>
                    </div>
                </div>
            </template>

            @error($diagnosticReportErrorPath . '.performerEmployeeIds')
                <p class="text-error">{{ $message }}</p>
            @enderror

            @error($diagnosticReportErrorPath . '.performerEmployeeIds.*')
                <p class="text-error">{{ $message }}</p>
            @enderror

            <button
                type="button"
                @click.prevent="modalDiagnosticReport.performerEmployeeIds.push('')"
                class="item-add mt-3"
            >
                {{ __('diagnostic-reports.add_performer') }}
            </button>
        </div>
    </div>

    {{-- Issued datetime --}}
    <div class="form-row-3">
        <div class="form-group group">
            <div class="datepicker-wrapper">
                <input
                    x-model="modalDiagnosticReport.issuedDate"
                    @if ($isEncounterContext)
                        @input="validateIssuedDateTime()"
                    @endif
                    datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                    type="text"
                    name="issuedDate"
                    id="issuedDate"
                    class="datepicker-input with-leading-icon input peer"
                    placeholder=" "
                    required
                    autocomplete="off"
                />
                <label for="issuedDate" class="wrapped-label"> {{ __('patients.date_time_entered') }} </label>

                @error($diagnosticReportErrorPath . '.issuedDate')
                    <p class="text-error">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <div class="form-group group !w-1/2" onclick="document.getElementById('issuedTime').showPicker()">
            <div class="relative flex items-center">
                @icon('mingcute-time-fill', 'svg-input left-2.5')
                <input
                    x-model="modalDiagnosticReport.issuedTime"
                    @if ($isEncounterContext)
                        @input="
                            $event.target.blur();
                            validateIssuedDateTime();
                        "
                        :min="encounterPeriodStart"
                        :max="encounterPeriodEnd"
                    @endif
                    datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                    type="time"
                    name="issuedTime"
                    id="issuedTime"
                    class="input peer !pl-10"
                    autocomplete="off"
                    required
                />
            </div>

            @error($diagnosticReportErrorPath . '.issuedTime')
                <p class="text-error">{{ $message }}</p>
            @enderror

            @if ($isEncounterContext)
                <p x-show="issuedDateTimeInvalid" x-cloak class="text-error">
                    {{ __('diagnostic-reports.issued_outside_encounter_period') }}
                </p>
            @endif
        </div>
    </div>

    {{-- Effective type --}}
    <div class="form-row-2">
        <div class="form-group group">
            <select
                x-model="modalDiagnosticReport.effectiveType"
                id="diagnosticReportEffectiveType"
                class="input-select peer"
                @change="setEffectiveType($event.target.value)"
            >
                <option value="">{{ __('diagnostic-reports.do_not_specify') }}</option>

                <option value="date_time">{{ __('diagnostic-reports.effective_date_time') }}</option>

                <option value="period">{{ __('diagnostic-reports.effective_period') }}</option>
            </select>

            @error($diagnosticReportErrorPath . '.effectiveType')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    {{-- Effective date and time --}}
    <div class="form-row-3" x-show="modalDiagnosticReport.effectiveType === 'date_time'" x-cloak>
        <div class="form-group group">
            <div class="datepicker-wrapper">
                <input
                    x-model="modalDiagnosticReport.effectiveDate"
                    datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                    type="text"
                    name="effectiveDate"
                    id="diagnosticReportEffectiveDate"
                    class="datepicker-input with-leading-icon input peer"
                    placeholder=" "
                    autocomplete="off"
                    :required="modalDiagnosticReport.effectiveType === 'date_time'"
                />

                <label for="diagnosticReportEffectiveDate" class="wrapped-label">
                    {{ __('diagnostic-reports.effective_date_time') }}
                </label>

                @error($diagnosticReportErrorPath . '.effectiveDate')
                    <p class="text-error">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <div
            class="form-group group !w-1/2"
            onclick="document.getElementById('diagnosticReportEffectiveTime').showPicker()"
        >
            <div class="relative flex items-center">
                @icon(
                    'mingcute-time-fill',
                    'svg-input left-2.5'
)

                <input
                    x-model="modalDiagnosticReport.effectiveTime"
                    @input="$event.target.blur()"
                    type="time"
                    name="effectiveTime"
                    id="diagnosticReportEffectiveTime"
                    class="input peer !pl-10"
                    autocomplete="off"
                    :required="modalDiagnosticReport.effectiveType === 'date_time'"
                />
            </div>

            @error($diagnosticReportErrorPath . '.effectiveTime')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    @unless ($isEncounterContext ?? false)
        {{-- Effective period --}}
        <div x-show="modalDiagnosticReport.effectiveType === 'period'" x-cloak>
            <div class="form-row-3">
                <div class="form-group group">
                    <div class="datepicker-wrapper">
                        <input
                            x-model="modalDiagnosticReport.effectivePeriodStartDate"
                            datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                            type="text"
                            name="effectivePeriodStartDate"
                            id="effectivePeriodStartDate"
                            class="datepicker-input with-leading-icon input peer"
                            placeholder=" "
                            autocomplete="off"
                            :required="modalDiagnosticReport.effectiveType === 'period'"
                        />

                        <label for="effectivePeriodStartDate" class="wrapped-label">
                            {{ __('diagnostic-reports.effective_period_start') }}
                        </label>

                        @error($diagnosticReportErrorPath . '.effectivePeriodStartDate')
                            <p class="text-error">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div
                    class="form-group group !w-1/2"
                    onclick="document.getElementById('effectivePeriodStartTime').showPicker()"
                >
                    <div class="relative flex items-center">
                        @icon('mingcute-time-fill', 'svg-input left-2.5')

                        <input
                            x-model="modalDiagnosticReport.effectivePeriodStartTime"
                            @input="$event.target.blur()"
                            type="time"
                            name="effectivePeriodStartTime"
                            id="effectivePeriodStartTime"
                            class="input peer !pl-10"
                            autocomplete="off"
                            :required="modalDiagnosticReport.effectiveType === 'period'"
                        />
                    </div>

                    @error($diagnosticReportErrorPath . '.effectivePeriodStartTime')
                        <p class="text-error">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="form-row-3">
                <div class="form-group group">
                    <div class="datepicker-wrapper">
                        <input
                            x-model="modalDiagnosticReport.effectivePeriodEndDate"
                            datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                            type="text"
                            name="effectivePeriodEndDate"
                            id="effectivePeriodEndDate"
                            class="datepicker-input with-leading-icon input peer"
                            placeholder=" "
                            autocomplete="off"
                            :required="Boolean(modalDiagnosticReport.effectivePeriodEndTime)"
                        />

                        <label for="effectivePeriodEndDate" class="wrapped-label">
                            {{ __('diagnostic-reports.effective_period_end') }}
                        </label>

                        @error($diagnosticReportErrorPath . '.effectivePeriodEndDate')
                            <p class="text-error">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div
                    class="form-group group !w-1/2"
                    onclick="document.getElementById('effectivePeriodEndTime').showPicker()"
                >
                    <div class="relative flex items-center">
                        @icon('mingcute-time-fill', 'svg-input left-2.5')

                        <input
                            x-model="modalDiagnosticReport.effectivePeriodEndTime"
                            @input="$event.target.blur()"
                            type="time"
                            name="effectivePeriodEndTime"
                            id="effectivePeriodEndTime"
                            class="input peer !pl-10"
                            autocomplete="off"
                            :required="Boolean(modalDiagnosticReport.effectivePeriodEndDate)"
                        />
                    </div>

                    @error($diagnosticReportErrorPath . '.effectivePeriodEndTime')
                        <p class="text-error">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </div>
    @endunless

    {{-- Used references / Equipment --}}
    @if ($context === 'diagnostic-report')
        <div class="form-row-2">
            <div class="w-full max-w-107.5">
                <p class="label-modal mb-2 block text-sm">{{ __('equipments.label') }}</p>

                <div class="space-y-4">
                    <template x-for="(usedReference, index) in modalDiagnosticReport.usedReferences" :key="index">
                        <div class="flex items-end gap-3">
                            <div class="flex-1">
                                <template x-if="! modalDiagnosticReport.divisionId">
                                    <div class="form-group group">
                                        <input type="text" class="input peer" placeholder=" " disabled />

                                        <label class="label"> {{ __('equipments.search') }} </label>
                                    </div>
                                </template>

                                @foreach ($equipmentOptionsByDivision as $divisionUuid => $options)
                                    <div x-show="modalDiagnosticReport.divisionId === @js($divisionUuid)" x-cloak>
                                        <x-forms.combobox
                                            class="w-full"
                                            model="usedReference"
                                            modelKey="id"
                                            :options="$options"
                                            bindValue="uuid"
                                            bindParam="name"
                                            :label="__('equipments.search')"
                                        />
                                    </div>
                                @endforeach

                                <template
                                    x-if="
                                        modalDiagnosticReport.divisionId
                                        && ! Object.keys(
                                            @js($equipmentOptionsByDivision)
                                        ).includes(
                                            modalDiagnosticReport.divisionId
                                        )
                                    "
                                >
                                    <p class="mt-1 text-xs text-gray-500">
                                        Немає доступного обладнання для обраного місця надання послуг
                                    </p>
                                </template>
                            </div>

                            <button
                                type="button"
                                @click.prevent="removeUsedReference(index)"
                                class="text-error shrink-0 hover:opacity-80"
                            >
                                @icon('delete', 'w-5 h-5')
                            </button>
                        </div>
                    </template>
                </div>

                @error($diagnosticReportErrorPath . '.usedReferences.*.id')
                    <p class="text-error mt-2">{{ $message }}</p>
                @enderror

                <button type="button" @click.prevent="addUsedReference()" class="item-add mt-4">
                    {{ __('equipments.add') }}
                </button>
            </div>
        </div>
    @endif
</fieldset>
