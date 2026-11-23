@php
    $procedureErrorPath = $context === 'encounter' ? 'procedureForm.procedures.*' : 'form.procedure';
@endphp

<fieldset class="fieldset">
    <legend class="legend">{{ __('forms.additional_info') }}</legend>

    {{-- Procedure information source --}}
    <div class="mb-4 flex gap-20 md:mb-5">
        <h2 class="default-p font-bold">{{ __('medical-events.information_source') }}</h2>

        <div class="flex items-center">
            <input
                @change="
                    modalProcedure.primarySource = true;
                    modalProcedure.reportOriginCode = '';
                    modalProcedure.reportOriginText = '';
                "
                x-model.boolean="modalProcedure.primarySource"
                id="procedureSourcePerformer"
                type="radio"
                value="true"
                name="primarySource"
                class="default-radio"
                :checked="modalProcedure.primarySource === true"
            />
            <label for="procedureSourcePerformer" class="ms-2 text-sm font-medium text-gray-900 dark:text-gray-300">
                {{ __('medical-events.performer') }}
            </label>
        </div>

        <div class="flex items-center">
            @unless (auth()->user()->isAssistantOnly())
                <input
                    @change="
                        modalProcedure.primarySource = false;
                        modalProcedure.performerEmployeeId = '';
                    "
                    x-model.boolean="modalProcedure.primarySource"
                    id="procedureSourcePatient"
                    type="radio"
                    value="false"
                    name="primarySource"
                    class="default-radio"
                    :checked="modalProcedure.primarySource === false"
                />
                <label for="procedureSourcePatient" class="ms-2 text-sm font-medium text-gray-900 dark:text-gray-300">
                    {{ __('medical-events.other_source') }}
                </label>
            @endunless
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
                    {{ __('forms.select') }} {{ mb_strtolower(__('procedures.doctor_who_performed')) }} *
                </option>

                <template x-for="employee in procedureEmployees" :key="employee.uuid">
                    <option
                        :value="employee.uuid"
                        :selected="String(modalProcedure.performerEmployeeId) === String(employee.uuid)"
                        x-text="
                            `${employee.name} — ${
                                $wire.dictionaries['POSITION'][employee.position] ?? employee.position
                            }`
                        "
                    ></option>
                </template>
            </select>
            <label for="procedurePerformer" class="label"> {{ __('procedures.doctor_who_performed') }} </label>
            @error($procedureErrorPath . '.performerEmployeeId')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    {{-- When the other source is choosen  --}}
    <div x-show="modalProcedure.primarySource === false">
        <div class="form-row-modal">
            <div>
                <label for="procedureReportOrigin" class="sr-only">{{ __('medical-events.source_link') }}</label>
                <select
                    class="input-select peer"
                    x-model="modalProcedure.reportOriginCode"
                    id="procedureReportOrigin"
                    type="text"
                    required
                >
                    <option value="" selected>
                        {{ __('forms.select') }} {{ mb_strtolower(__('medical-events.source_link')) }} *
                    </option>
                    @foreach ($this->dictionaries['eHealth/report_origins'] as $key => $reportOrigin)
                        <option value="{{ $key }}">{{ $reportOrigin }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    {{-- Performed type --}}
    <div class="form-row-2" x-show="modalProcedure.status === 'completed'" x-cloak>
        <div class="form-group group">
            <label for="procedurePerformedType" class="sr-only">{{ __('procedures.performed_type') }}</label>
            <select
                x-model="modalProcedure.performedType"
                @change="setPerformedType($event.target.value)"
                id="procedurePerformedType"
                class="input-select peer"
            >
                <option value="date_time">{{ __('procedures.performed_date_time') }}</option>

                <option value="period">{{ __('procedures.performed_period') }}</option>
            </select>

            @error($procedureErrorPath . '.performedType')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    {{-- Performed date and time --}}
    <div
        class="form-row-3"
        x-show="modalProcedure.status === 'completed' && modalProcedure.performedType === 'date_time'"
        x-cloak
    >
        <div class="form-group group">
            <x-forms.date
                x-model="modalProcedure.performedDate"
                datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                name="performedDate"
                id="procedurePerformedDate"
                x-bind:required="modalProcedure.status === 'completed' && modalProcedure.performedType === 'date_time'"
                :label="__('procedures.performed_date_time')"
                :error="$procedureErrorPath . '.performedDate'"
            />
        </div>

        <div class="form-group group w-1/2!">
            <x-forms.time
                x-model="modalProcedure.performedTime"
                name="performedTime"
                id="procedurePerformedTime"
                x-bind:required="modalProcedure.status === 'completed' && modalProcedure.performedType === 'date_time'"
                :label="__('forms.time')"
                :error="$procedureErrorPath . '.performedTime'"
            />
        </div>
    </div>

    {{-- Start effective period datetime --}}
    <div x-show="modalProcedure.status === 'completed' && modalProcedure.performedType === 'period'" x-cloak>
        <div class="form-row-3">
            <div class="form-group group">
                <x-forms.date
                    x-model="modalProcedure.performedPeriodStartDate"
                    :x-bind:datepicker-min-date="$context === 'encounter' ? 'encounter.periodDate' : null"
                    :x-bind:datepicker-max-date="$context === 'encounter' ? 'encounter.periodDate' : null"
                    :datepicker-max-date="$context === 'encounter' ? null : now()->format(config('app.date_format'))"
                    name="performedPeriodStartDate"
                    id="performedPeriodStartDate"
                    x-bind:required="modalProcedure.status === 'completed' && modalProcedure.performedType === 'period'"
                    :label="__('procedures.start_date_and_time')"
                    :error="$procedureErrorPath . '.performedPeriodStartDate'"
                />
            </div>

            <div class="form-group group w-1/2!">
                <x-forms.time
                    x-model="modalProcedure.performedPeriodStartTime"
                    name="performedPeriodStartTime"
                    id="performedPeriodStartTime"
                    x-bind:required="modalProcedure.status === 'completed' && modalProcedure.performedType === 'period'"
                    :label="__('forms.time')"
                    :error="$procedureErrorPath . '.performedPeriodStartTime'"
                />
            </div>
        </div>
    </div>

    {{-- End effective period datetime --}}
    <div x-show="modalProcedure.status === 'completed' && modalProcedure.performedType === 'period'" x-cloak>
        <div class="form-row-3">
            <div class="form-group group">
                <x-forms.date
                    x-model="modalProcedure.performedPeriodEndDate"
                    :x-bind:datepicker-min-date="$context === 'encounter' ? 'encounter.periodDate' : null"
                    :x-bind:datepicker-max-date="$context === 'encounter' ? 'encounter.periodDate' : null"
                    :datepicker-max-date="$context === 'encounter' ? null : now()->format(config('app.date_format'))"
                    name="performedPeriodEndDate"
                    id="performedPeriodEndDate"
                    x-bind:required="modalProcedure.status === 'completed' && modalProcedure.performedType === 'period'"
                    :label="__('procedures.end_date_and_time')"
                    :error="$procedureErrorPath . '.performedPeriodEndDate'"
                />
            </div>

            <div class="form-group group w-1/2!">
                <x-forms.time
                    x-model="modalProcedure.performedPeriodEndTime"
                    name="performedPeriodEndTime"
                    id="performedPeriodEndTime"
                    x-bind:required="modalProcedure.status === 'completed' && modalProcedure.performedType === 'period'"
                    :label="__('forms.time')"
                    :error="$procedureErrorPath . '.performedPeriodEndTime'"
                />
            </div>
        </div>
    </div>

    @if ($context === 'encounter')
        <p
            x-show="modalProcedure.status === 'completed' && procedurePerformedOutsideEncounterPeriod()"
            x-cloak
            class="text-error"
        >
            {{ __('procedures.validation.performed_outside_encounter_period') }}
        </p>
    @endif

    {{-- Note --}}
    <div class="form-row">
        <div>
            <label for="procedureNote" class="label-modal"> {{ __('procedures.note') }} </label>
            <div>
                <textarea
                    rows="4"
                    x-model="modalProcedure.note"
                    id="procedureNote"
                    name="note"
                    class="textarea"
                    placeholder="{{ __('forms.write_comment_here') }}"
                ></textarea>
            </div>
        </div>
    </div>

    <div class="form-row-2">
        <div class="w-full max-w-107.5">
            <p class="label-modal mb-2 block text-sm">{{ __('equipments.label') }}</p>

            <div class="space-y-4">
                <template x-for="(usedReference, index) in modalProcedure.usedReferences" :key="index">
                    <div class="flex items-end gap-3">
                        <div class="flex-1">
                            @foreach ($equipmentOptionsByDivision as $divisionUuid => $options)
                                <div x-show="modalProcedure.divisionId === @js($divisionUuid)" x-cloak>
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

                            <template x-if="modalProcedure.divisionId && ! Object.keys(@js($equipmentOptionsByDivision)).includes(modalProcedure.divisionId)">
                                <p class="mt-1 text-xs text-gray-500">
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
                                && ! (
                                    @js($equipmentOptionsByDivision)[
                                        modalProcedure.divisionId
                                    ]?.length
                                )
                            "
                        >
                            <p class="text-error mt-2">
                                {{ __('medical-events.validation.no_equipment_in_division') }}
                            </p>
                        </template>
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

            @error($procedureErrorPath . '.usedReferences.*.id')
                <p class="text-error mt-2">{{ $message }}</p>
            @enderror

            <button
                type="button"
                @click.prevent="addUsedReference()"
                :disabled="
                    modalProcedure.divisionId
                    && ! (
                        @js($equipmentOptionsByDivision)[
                            modalProcedure.divisionId
                        ]?.length
                    )
                "
                class="item-add mt-4 disabled:cursor-not-allowed disabled:opacity-50"
            >
                {{ __('medical-events.equipment_add') }}
            </button>
        </div>
    </div>
</fieldset>
