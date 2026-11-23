@php
    $diagnosticReportErrorPath = $diagnosticReportErrorPath
        ?? (($context ?? null) === 'diagnostic-report'
            ? 'form.diagnosticReport'
            : 'diagnosticReportForm.diagnosticReports.*');
    $isEncounterContext = $isEncounterContext ?? false;
    $diagnosticReportEmployeeOptions = $isEncounterContext ? $diagnosticReportEmployees : $employees;
@endphp
<fieldset class="fieldset">
    <legend class="legend">{{ __('forms.additional_info') }}</legend>

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
                                        <input
                                            type="text"
                                            :id="`usedReferencePlaceholder${index}`"
                                            class="input peer"
                                            placeholder=" "
                                            disabled
                                        />

                                        <label :for="`usedReferencePlaceholder${index}`" class="label">
                                            {{ __('medical-events.equipment_search') }}
                                        </label>
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
                                            :label="__('medical-events.equipment_search')"
                                        />
                                    </div>
                                @endforeach
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

                <div class="mt-4">
                    <template
                        x-if="
                            modalDiagnosticReport.divisionId
                            && ! (@js($equipmentOptionsByDivision)[modalDiagnosticReport.divisionId]?.length)
                        "
                    >
                        <p class="mb-1 text-xs text-gray-400 dark:text-gray-500">
                            {{ __('medical-events.validation.no_equipment_in_division') }}
                        </p>
                    </template>

                    <button
                        type="button"
                        @click.prevent="addUsedReference()"
                        :disabled="! modalDiagnosticReport.divisionId || ! (@js($equipmentOptionsByDivision)[modalDiagnosticReport.divisionId]?.length)"
                        class="item-add disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {{ __('medical-events.equipment_add') }}
                    </button>
                </div>
            </div>

            <div class="w-full max-w-107.5">
                <p class="label-modal mb-2 block text-sm">{{ __('diagnostic-reports.specimens') }}</p>

                <div class="space-y-4">
                    <template
                        x-for="(specimenId, specimenIndex) in modalDiagnosticReport.specimenIds"
                        :key="specimenIndex"
                    >
                        <div class="flex items-end gap-3">
                            <div class="form-group group flex-1">
                                <select
                                    x-model="modalDiagnosticReport.specimenIds[specimenIndex]"
                                    :id="`diagnosticReportSpecimen${specimenIndex}`"
                                    class="input-select peer"
                                >
                                    <option value="">{{ __('forms.select') }}</option>

                                    <template x-for="specimen in specimenOptions()" :key="specimen.uuid">
                                        <option
                                            :value="specimen.uuid"
                                            :selected="String(specimen.uuid) === String(specimenId)"
                                            x-text="specimen.name"
                                        ></option>
                                    </template>
                                </select>
                                <label :for="`diagnosticReportSpecimen${specimenIndex}`" class="label">
                                    {{ __('diagnostic-reports.select_specimen') }}
                                </label>
                            </div>

                            <button
                                type="button"
                                @click.prevent="modalDiagnosticReport.specimenIds.splice(specimenIndex, 1)"
                                class="text-error shrink-0 hover:opacity-80"
                            >
                                @icon('delete', 'w-5 h-5')
                            </button>
                        </div>
                    </template>
                </div>

                @error($diagnosticReportErrorPath . '.specimenIds.*')
                    <p class="text-error mt-2">{{ $message }}</p>
                @enderror

                <button type="button" @click.prevent="modalDiagnosticReport.specimenIds.push('')" class="item-add mt-4">
                    {{ __('diagnostic-reports.add_specimen') }}
                </button>
            </div>
        </div>
    @endif

    @if ($isEncounterContext ?? false)
        {{-- Information source (doctor or patient) --}}
        <div class="mb-8 flex gap-20">
            <h2 class="default-p font-bold">{{ __('medical-events.information_source') }}</h2>
            {{-- Doctor --}}
            <div class="flex items-center">
                <input
                    x-model.boolean="modalDiagnosticReport.primarySource"
                    id="diagnosticReportSourcePerformer"
                    type="radio"
                    value="true"
                    name="primarySource"
                    class="default-radio"
                    :checked="modalDiagnosticReport.primarySource === true"
                />
                <label
                    for="diagnosticReportSourcePerformer"
                    class="ms-2 text-sm font-medium text-gray-900 dark:text-gray-300"
                >
                    {{ __('medical-events.performer') }}
                </label>
            </div>

            {{-- Patient --}}
            @unless (auth()->user()->isAssistantOnly())
                <div class="flex items-center">
                    <input
                        x-model.boolean="modalDiagnosticReport.primarySource"
                        id="diagnosticReportSourcePatient"
                        type="radio"
                        value="false"
                        name="primarySource"
                        class="default-radio"
                        :checked="modalDiagnosticReport.primarySource === false"
                    />
                    <label
                        for="diagnosticReportSourcePatient"
                        class="ms-2 text-sm font-medium text-gray-900 dark:text-gray-300"
                    >
                        {{ __('forms.patient') }}
                    </label>
                </div>
            @endunless
        </div>

        {{-- When patient selected --}}
        <div x-show="modalDiagnosticReport.primarySource === false" x-transition>
            <div class="form-row-3">
                <div>
                    <label for="diagnosticReportOrigin" class="label-modal">
                        {{ __('medical-events.source_link') }}
                    </label>
                    <select
                        x-model="modalDiagnosticReport.reportOriginCode"
                        class="input-select peer"
                        id="diagnosticReportOrigin"
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
                <label for="diagnosticReportDivision" class="sr-only">{{ __('diagnostic-reports.division') }}</label>
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
                    id="diagnosticReportDivision"
                    class="input-select peer"
                    type="text"
                >
                    <option value="" selected>
                        {{ __('forms.select') }} {{ mb_strtolower(__('diagnostic-reports.division')) }}
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
                <span x-show="['diagnostic_procedure', 'imaging'].includes(modalDiagnosticReport.categoryCode)" x-cloak
                    >*</span>
            </label>

            <select
                x-model="modalDiagnosticReport.resultsInterpreterEmployeeId"
                @change="
                    modalDiagnosticReport.performerEmployeeIds = modalDiagnosticReport.performerEmployeeIds.filter(
                        (employeeId) =>
                            String(employeeId) === String(defaultDiagnosticReportPerformerEmployeeId) ||
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
                <label
                    for="resultsInterpreterPerformer"
                    class="mb-2 block text-sm font-medium text-gray-500 dark:text-gray-400"
                >
                    {{ ucfirst(__('diagnostic-reports.performer')) }}
                </label>

                <select
                    x-model="modalDiagnosticReport.resultsInterpreterEmployeeId"
                    id="resultsInterpreterPerformer"
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
                    <label
                        :for="`diagnosticReportPerformer${index}`"
                        class="mb-2 block text-sm font-medium text-gray-500 dark:text-gray-400"
                    >
                        {{ ucfirst(__('diagnostic-reports.performer')) }}
                    </label>

                    <div class="flex items-center gap-4">
                        <select
                            x-model="modalDiagnosticReport.performerEmployeeIds[index]"
                            :id="`diagnosticReportPerformer${index}`"
                            :disabled="String(performerEmployeeId) ===
                            String(defaultDiagnosticReportPerformerEmployeeId)"
                            :class="String(performerEmployeeId) === String(defaultDiagnosticReportPerformerEmployeeId)
                                ? '!cursor-not-allowed !text-gray-500 dark:!text-gray-400'
                                : ''"
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
                            x-show="String(performerEmployeeId) !== String(defaultDiagnosticReportPerformerEmployeeId)"
                            x-cloak
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
            <x-forms.date
                x-model="modalDiagnosticReport.issuedDate"
                :x-on:input="$isEncounterContext ? 'validateIssuedDateTime()' : null"
                datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                name="issuedDate"
                id="issuedDate"
                required
                :label="__('diagnostic-reports.issued')"
                :error="$diagnosticReportErrorPath . '.issuedDate'"
            />
        </div>

        <div class="form-group group w-1/2!">
            <x-forms.time
                x-model="modalDiagnosticReport.issuedTime"
                :x-on:input="$isEncounterContext ? 'validateIssuedDateTime()' : null"
                name="issuedTime"
                id="issuedTime"
                required
                :label="__('forms.time')"
                :error="$diagnosticReportErrorPath . '.issuedTime'"
            />

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
            <label for="diagnosticReportEffectiveType" class="sr-only">
                {{ __('diagnostic-reports.effective_type') }}
            </label>
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
            <x-forms.date
                x-model="modalDiagnosticReport.effectiveDate"
                datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                name="effectiveDate"
                id="diagnosticReportEffectiveDate"
                x-bind:required="modalDiagnosticReport.effectiveType === 'date_time'"
                :label="__('diagnostic-reports.effective_date_time')"
                :error="$diagnosticReportErrorPath . '.effectiveDate'"
            />
        </div>

        <div class="form-group group w-1/2!">
            <x-forms.time
                x-model="modalDiagnosticReport.effectiveTime"
                name="effectiveTime"
                id="diagnosticReportEffectiveTime"
                x-bind:required="modalDiagnosticReport.effectiveType === 'date_time'"
                :label="__('forms.time')"
                :error="$diagnosticReportErrorPath . '.effectiveTime'"
            />
        </div>
    </div>

    @unless ($isEncounterContext ?? false)
        {{-- Effective period --}}
        <div x-show="modalDiagnosticReport.effectiveType === 'period'" x-cloak>
            <div class="form-row-3">
                <div class="form-group group">
                    <x-forms.date
                        x-model="modalDiagnosticReport.effectivePeriodStartDate"
                        datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                        name="effectivePeriodStartDate"
                        id="effectivePeriodStartDate"
                        x-bind:required="modalDiagnosticReport.effectiveType === 'period'"
                        :label="__('diagnostic-reports.effective_period_start')"
                        :error="$diagnosticReportErrorPath . '.effectivePeriodStartDate'"
                    />
                </div>

                <div class="form-group group w-1/2!">
                    <x-forms.time
                        x-model="modalDiagnosticReport.effectivePeriodStartTime"
                        name="effectivePeriodStartTime"
                        id="effectivePeriodStartTime"
                        x-bind:required="modalDiagnosticReport.effectiveType === 'period'"
                        :label="__('forms.time')"
                        :error="$diagnosticReportErrorPath . '.effectivePeriodStartTime'"
                    />
                </div>
            </div>

            <div class="form-row-3">
                <div class="form-group group">
                    <x-forms.date
                        x-model="modalDiagnosticReport.effectivePeriodEndDate"
                        datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                        name="effectivePeriodEndDate"
                        id="effectivePeriodEndDate"
                        x-bind:required="Boolean(modalDiagnosticReport.effectivePeriodEndTime)"
                        :label="__('diagnostic-reports.effective_period_end')"
                        :error="$diagnosticReportErrorPath . '.effectivePeriodEndDate'"
                    />
                </div>

                <div class="form-group group w-1/2!">
                    <x-forms.time
                        x-model="modalDiagnosticReport.effectivePeriodEndTime"
                        name="effectivePeriodEndTime"
                        id="effectivePeriodEndTime"
                        x-bind:required="Boolean(modalDiagnosticReport.effectivePeriodEndDate)"
                        :label="__('forms.time')"
                        :error="$diagnosticReportErrorPath . '.effectivePeriodEndTime'"
                    />
                </div>
            </div>
        </div>
    @endunless
</fieldset>
