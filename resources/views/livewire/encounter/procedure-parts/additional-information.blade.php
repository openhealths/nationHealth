@php
    $procedureErrorPath = $context === 'encounter' ? 'form.procedures.*' : 'form.procedure';
@endphp

<fieldset class="fieldset">
    <legend class="legend">
        {{ __('forms.additional_info') }}
    </legend>

    {{-- Procedure information source --}}
    <div class="flex gap-20 md:mb-5 mb-4">
        <h2 class="default-p font-bold">{{ __('patients.information_source') }}</h2>

        <div class="flex items-center">
            <input @change="
                        modalProcedure.primarySource = true;
                        modalProcedure.reportOriginCode = '';
                        modalProcedure.reportOriginText = '';
                "
                x-model.boolean="modalProcedure.primarySource"
                id="performer"
                type="radio"
                value="true"
                name="primarySource"
                class="default-radio"
                :checked="modalProcedure.primarySource === true"
            >
            <label for="performer" class="ms-2 text-sm font-medium text-gray-900 dark:text-gray-300">
                {{ __('patients.performer') }}
            </label>
        </div>

        <div class="flex items-center">
            <input 
                @change="
                    modalProcedure.primarySource = false;
                    modalProcedure.performerEmployeeId = '';
                "
                x-model.boolean="modalProcedure.primarySource"
                id="patient"
                type="radio"
                value="false"
                name="primarySource"
                class="default-radio"
                :checked="modalProcedure.primarySource === false"
            >
            <label for="patient" class="ms-2 text-sm font-medium text-gray-900 dark:text-gray-300">
                {{ __('patients.other_source') }}
            </label>
        </div>
    </div>

    {{-- When the performer is chosen --}}
    <div x-show="modalProcedure.primarySource === true" class="form-row-2" x-cloak>
        <div class="form-group group">
            <select
                x-model="modalProcedure.performerEmployeeId"
                id="procedurePerformer"
                class="input-select peer"
                :required="modalProcedure.primarySource === true"
            >
                <option value="">
                    {{ __('forms.select') }}
                    {{ mb_strtolower(__('patients.doctor_who_performed')) }}
                    *
                </option>

                <template
                    x-for="
                        employee in procedureEmployees.filter(
                            employee =>
                                !modalProcedure.divisionId
                                || employee.divisionUuid === modalProcedure.divisionId
                        )
                    "
                    :key="employee.uuid"
                >
                    <option
                        :value="employee.uuid"
                        :selected="
                            String(modalProcedure.performerEmployeeId)
                                === String(employee.uuid)
                        "
                        x-text="
                            `${employee.name} — ${
                                $wire.dictionaries['POSITION'][employee.position]
                                ?? employee.position
                            }`
                        "
                    ></option>
                </template>
            </select>
            <label for="procedurePerformer" class="label">
                {{ __('patients.doctor_who_performed') }}
            </label>
            @error($procedureErrorPath . '.performerEmployeeId')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    {{-- When the other source is choosen  --}}
    <div x-show="modalProcedure.primarySource === false">
        <div class="form-row-modal">
            <div>
                <select class="input-select peer"
                        x-model="modalProcedure.reportOriginCode"
                        id="reportOrigin"
                        type="text"
                        required
                >
                    <option value="" selected>{{ __('forms.select') }} {{ mb_strtolower(__('patients.source_link')) }} *</option>
                    @foreach($this->dictionaries['eHealth/report_origins'] as $key => $reportOrigin)
                        <option value="{{ $key }}">{{ $reportOrigin }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    {{-- Performed type --}}
    <div
        class="form-row-2"
        x-show="modalProcedure.status === 'completed'"
        x-cloak
    >
        <div class="form-group group">
            <select
                x-model="modalProcedure.performedType"
                @change="setPerformedType($event.target.value)"
                id="procedurePerformedType"
                class="input-select peer"
            >
                <option value="date_time">
                    {{ __('patients.procedure_performed_date_time') }}
                </option>

                <option value="period">
                    {{ __('patients.procedure_performed_period') }}
                </option>
            </select>

            @error($procedureErrorPath . '.performedType')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    {{-- Performed date and time --}}
    <div
        class="form-row-3"
        x-show="
            modalProcedure.status === 'completed'
            && modalProcedure.performedType === 'date_time'
        "
        x-cloak
    >
        <div class="form-group group">
            <div class="datepicker-wrapper">
                <input
                    x-model="modalProcedure.performedDate"
                    datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                    type="text"
                    name="performedDate"
                    id="procedurePerformedDate"
                    class="datepicker-input with-leading-icon input peer"
                    placeholder=" "
                    autocomplete="off"
                    :required="
                        modalProcedure.status === 'completed'
                        && modalProcedure.performedType === 'date_time'
                    "
                >

                <label for="procedurePerformedDate" class="wrapped-label">
                    {{ __('patients.procedure_performed_date_time') }}
                </label>

                @error($procedureErrorPath . '.performedDate')
                    <p class="text-error">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <div
            class="form-group group !w-1/2"
            onclick="document.getElementById('procedurePerformedTime').showPicker()"
        >
            <div class="relative flex items-center">
                @icon('mingcute-time-fill', 'svg-input left-2.5')

                <input
                    x-model="modalProcedure.performedTime"
                    @input="$event.target.blur()"
                    type="time"
                    name="performedTime"
                    id="procedurePerformedTime"
                    class="input peer !pl-10"
                    autocomplete="off"
                    :required="
                        modalProcedure.status === 'completed'
                        && modalProcedure.performedType === 'date_time'
                    "
                >
            </div>

            @error($procedureErrorPath . '.performedTime')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    {{-- Start effective period datetime --}}
    <div x-show="modalProcedure.status === 'completed' && modalProcedure.performedType === 'period'" x-cloak>
        <div class="form-row-3">
            <div class="form-group group">
                <div class="datepicker-wrapper">
                    <input x-model="modalProcedure.performedPeriodStartDate"
                        datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                        type="text"
                        name="performedPeriodStartDate"
                        id="performedPeriodStartDate"
                        class="datepicker-input with-leading-icon input peer"
                        placeholder=" "
                        :required="
                            modalProcedure.status === 'completed'
                            && modalProcedure.performedType === 'period'
                        "
                        autocomplete="off"
                    >
                    <label for="performedPeriodStartDate" class="wrapped-label">
                        {{ __('patients.procedure_start_date_and_time') }}
                    </label>

                    @error($procedureErrorPath . '.performedPeriodStartDate')
                        <p class="text-error">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="form-group group !w-1/2" onclick="document.getElementById('performedPeriodStartTime').showPicker()">
                <div class="relative flex items-center">
                    @icon('mingcute-time-fill', 'svg-input left-2.5')
                    <input x-model="modalProcedure.performedPeriodStartTime"
                        @input="$event.target.blur()"
                        datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                        type="time"
                        name="performedPeriodStartTime"
                        id="performedPeriodStartTime"
                        class="input peer !pl-10"
                        autocomplete="off"
                        :required="
                            modalProcedure.status === 'completed'
                            && modalProcedure.performedType === 'period'
                        "
                    >
                </div>

                @error($procedureErrorPath . '.performedPeriodStartTime')
                    <p class="text-error">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </div>

    {{-- End effective period datetime --}}
    <div x-show="modalProcedure.status === 'completed' && modalProcedure.performedType === 'period'" x-cloak>
        <div class="form-row-3">
            <div class="form-group group">
                <div class="datepicker-wrapper">
                    <input x-model="modalProcedure.performedPeriodEndDate"
                        datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                        type="text"
                        name="performedPeriodEndDate"
                        id="performedPeriodEndDate"
                        class="datepicker-input with-leading-icon input peer"
                        placeholder=" "
                        :required="
                            modalProcedure.status === 'completed'
                            && modalProcedure.performedType === 'period'
                        "
                        autocomplete="off"
                    >
                    <label for="performedPeriodEndDate" class="wrapped-label">
                        {{ __('patients.procedure_end_date_and_time') }}
                    </label>

                    @error($procedureErrorPath . '.performedPeriodEndDate')
                        <p class="text-error">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="form-group group !w-1/2" onclick="document.getElementById('performedPeriodEndTime').showPicker()">
                <div class="relative flex items-center">
                    @icon('mingcute-time-fill', 'svg-input left-2.5')
                    <input x-model="modalProcedure.performedPeriodEndTime"
                        @input="$event.target.blur()"
                        datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                        type="time"
                        name="performedPeriodEndTime"
                        id="performedPeriodEndTime"
                        class="input peer !pl-10"
                        autocomplete="off"
                        :required="
                            modalProcedure.status === 'completed'
                            && modalProcedure.performedType === 'period'
                        "
                    >
                </div>

                @error($procedureErrorPath . '.performedPeriodEndTime')
                    <p class="text-error">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </div>

    {{-- Note --}}
    <div class="form-row">
        <div>
            <label for="note" class="label-modal">
                {{ __('patients.notes') }}
            </label>
            <div>
                <textarea rows="4"
                          x-model="modalProcedure.note"
                          id="note"
                          name="note"
                          class="textarea"
                          placeholder="{{ __('forms.write_comment_here') }}"
                ></textarea>
            </div>
        </div>
    </div>

    <div class="form-row-2">
        <div class="w-full max-w-107.5">
            <p class="label-modal mb-2 block text-sm">
                {{ __('equipments.label') }}
            </p>

            <div class="space-y-4">
                <template x-for="(usedReference, index) in modalProcedure.usedReferences" :key="index">
                    <div class="flex items-end gap-3">
                        <div class="flex-1">
                            @foreach($equipmentOptionsByDivision as $divisionUuid => $options)
                                <div x-show="modalProcedure.divisionId === @js($divisionUuid)" x-cloak>
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

                            <template x-if="modalProcedure.divisionId && !Object.keys(@js($equipmentOptionsByDivision)).includes(modalProcedure.divisionId)">
                                <p class="text-xs text-gray-500 mt-1">
                                    Немає доступного обладнання для обраного місця надання послуг
                                </p>
                            </template>
                        </div>
                        @error($procedureErrorPath . '.usedReferences.*.id')
                            <p class="text-error mt-2">{{ $message }}</p>
                        @enderror
                        <template
                            x-if="
                                modalProcedure.divisionId
                                && !(
                                    @js($equipmentOptionsByDivision)[
                                        modalProcedure.divisionId
                                    ]?.length
                                )
                            "
                        >
                            <p class="text-error mt-2">
                                {{ __('equipments.validation.no_equipment_in_division') }}
                            </p>
                        </template>
                        <button
                            type="button"
                            @click.prevent="removeUsedReference(index)"
                            class="shrink-0 text-error hover:opacity-80"
                        >
                            @icon('delete', 'w-5 h-5')
                        </button>
                    </div>
                </template>
            </div>

            @error($procedureErrorPath . '.usedReferences.*.id')
                <p class="text-error mt-2">{{ $message }}</p>
            @enderror

            <button
                type="button"
                @click.prevent="addUsedReference()"
                :disabled="
                    modalProcedure.divisionId
                    && !(
                        @js($equipmentOptionsByDivision)[
                            modalProcedure.divisionId
                        ]?.length
                    )
                "
                class="
                    item-add mt-4
                    disabled:cursor-not-allowed
                    disabled:opacity-50
                "
            >
                {{ __('equipments.add') }}
            </button>
        </div>
    </div>
</fieldset>
