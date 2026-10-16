<fieldset class="fieldset">
    <legend class="legend">{{ __('forms.additional_info') }}</legend>

    <div class="form-row-modal">
        <div>
            <label for="observationMethod" class="label-modal"> {{ __('observations.method') }} </label>
            <select
                x-model="modalObservation.methodCode"
                id="observationMethod"
                class="input-modal"
                type="text"
                required
            >
                <option value="" selected>{{ __('forms.select') }}</option>
                @foreach ($this->dictionaries['eHealth/observation_methods'] as $key => $observationMethod)
                    <option value="{{ $key }}">{{ $observationMethod }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="observationInterpretation" class="label-modal">
                {{ __('observations.interpretation_of_observation') }}
            </label>
            <select
                x-model="modalObservation.interpretationCode"
                id="observationInterpretation"
                class="input-modal"
                type="text"
                required
            >
                <option value="" selected>{{ __('forms.select') }}</option>
                @foreach ($this->dictionaries['eHealth/observation_interpretations'] as $key => $observationInterpretation)
                    <option value="{{ $key }}">{{ $observationInterpretation }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="form-row-modal">
        <div>
            <label for="bodySite" class="label-modal"> {{ __('observations.body_site') }} </label>
            <select x-model="modalObservation.bodySiteCode" id="bodySite" class="input-modal" type="text" required>
                <option value="" selected>{{ __('forms.select') }}</option>
                @foreach ($this->dictionaries['eHealth/body_sites'] as $key => $bodySite)
                    <option value="{{ $key }}">{{ $bodySite }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="observationDevice" class="label-modal"> {{ __('equipments.label') }} </label>

            <template x-if="! divisionId">
                <select x-model="modalObservation.deviceId" id="observationDevice" class="input-modal">
                    <option value="" selected>{{ __('forms.select') }}</option>
                    @foreach ($equipmentOptions as $equipment)
                        <option value="{{ $equipment['uuid'] }}">{{ $equipment['name'] }}</option>
                    @endforeach
                </select>
            </template>

            @foreach ($divisions as $division)
                <template x-if="divisionId === @js($division['uuid'])">
                    <select x-model="modalObservation.deviceId" id="observationDevice" class="input-modal">
                        <option value="" selected>{{ __('forms.select') }}</option>
                        @foreach ($equipmentOptionsByDivision[$division['uuid']] ?? [] as $equipment)
                            <option value="{{ $equipment['uuid'] }}">{{ $equipment['name'] }}</option>
                        @endforeach
                    </select>
                </template>
            @endforeach
        </div>
    </div>

    @if ($isEncounterContext)
        <div class="form-row-modal">
            <div>
                <label for="observationSpecimen" class="label-modal">{{ __('specimens.label') }}</label>
                <select x-model="modalObservation.specimenId" id="observationSpecimen" class="input-modal">
                    <option value="" selected>{{ __('forms.select') }}</option>
                    <template x-for="specimen in specimenOptions()" :key="specimen.uuid">
                        <option :value="specimen.uuid" x-text="specimen.name"></option>
                    </template>
                </select>
            </div>
        </div>
    @endif

    <div class="form-row-4">
        <div>
            <label for="observationIssuedDate" class="label-modal"> {{ __('observations.result_received_at') }} </label>
            <div class="relative flex items-center">
                @icon('calendar-week', 'w-5 h-5 svg-input absolute left-2.5 pointer-events-none')
                <input
                    x-model="modalObservation.issuedDate"
                    datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                    type="text"
                    name="issuedDate"
                    id="observationIssuedDate"
                    class="datepicker-input input-modal pl-10!"
                    autocomplete="off"
                    required
                />
            </div>

            <p class="text-error text-xs" x-show="modalObservation.issuedDate.trim() === ''">
                {{ __('forms.field_empty') }}
            </p>
        </div>

        <div class="w-3/5" onclick="document.getElementById('observationIssuedTime').showPicker()">
            <label for="observationIssuedTime" class="hidden"> {{ __('forms.time') }} </label>

            <div class="relative mt-7 flex items-center">
                @icon('mingcute-time-fill', 'svg-input left-2.5')
                <input
                    x-model="modalObservation.issuedTime"
                    @input="$event.target.blur()"
                    datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                    type="time"
                    name="issuedTime"
                    id="observationIssuedTime"
                    class="input-modal pl-10!"
                    autocomplete="off"
                    required
                />
            </div>

            <p class="text-error text-xs" x-show="modalObservation.issuedTime.trim() === ''">
                {{ __('forms.field_empty') }}
            </p>
        </div>
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-8 md:mb-5">
        <h3 class="default-p font-bold">{{ __('observations.effective_label') }}</h3>

        <div class="flex items-center">
            <input
                x-model="modalObservation.effectiveType"
                id="effectiveTypeDateTime"
                type="radio"
                value="date_time"
                name="effectiveType"
                class="default-radio"
            />
            <label for="effectiveTypeDateTime" class="ms-2 text-sm font-medium text-gray-900 dark:text-gray-300">
                {{ __('observations.effective_date_time') }}
            </label>
        </div>

        <div class="flex items-center">
            <input
                x-model="modalObservation.effectiveType"
                id="effectiveTypePeriod"
                type="radio"
                value="period"
                name="effectiveType"
                class="default-radio"
            />
            <label for="effectiveTypePeriod" class="ms-2 text-sm font-medium text-gray-900 dark:text-gray-300">
                {{ __('observations.effective_period') }}
            </label>
        </div>
    </div>

    <div class="form-row-4" x-show="modalObservation.effectiveType === 'date_time'" x-cloak>
        <div>
            <label for="effectiveDate" class="label-modal">
                {{ __('observations.date_and_time_of_receiving_the_indicators') }}
            </label>
            <div class="relative flex items-center">
                @icon('calendar-week', 'w-5 h-5 svg-input absolute left-2.5 pointer-events-none')
                <input
                    x-model="modalObservation.effectiveDate"
                    datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                    type="text"
                    name="effectiveDate"
                    id="effectiveDate"
                    class="datepicker-input input-modal pl-10!"
                    autocomplete="off"
                    :required="modalObservation.effectiveType === 'date_time'"
                />
            </div>
        </div>

        <div class="w-3/5" onclick="document.getElementById('effectiveTime').showPicker()">
            <label for="effectiveTime" class="hidden"> {{ __('forms.time') }} </label>

            <div class="relative mt-7 flex items-center">
                @icon('mingcute-time-fill', 'svg-input left-2.5')
                <input
                    x-model="modalObservation.effectiveTime"
                    @input="$event.target.blur()"
                    type="time"
                    name="effectiveTime"
                    id="effectiveTime"
                    class="input-modal pl-10!"
                    autocomplete="off"
                    :required="modalObservation.effectiveType === 'date_time'"
                />
            </div>
        </div>
    </div>

    <div class="form-row-4" x-show="modalObservation.effectiveType === 'period'" x-cloak>
        <div>
            <label for="effectivePeriodRange" class="label-modal"> {{ __('observations.effective_period') }} </label>
            <div class="relative flex items-center">
                @icon('calendar-week', 'w-5 h-5 svg-input absolute left-2.5 pointer-events-none')
                <input
                    x-model="modalObservation.effectivePeriodRange"
                    type="text"
                    name="effectivePeriodRange"
                    id="effectivePeriodRange"
                    class="daterangepicker-uk input-modal pl-10!"
                    autocomplete="off"
                    :required="modalObservation.effectiveType === 'period'"
                />
            </div>
        </div>

        <div class="w-3/5" onclick="document.getElementById('observationEffectivePeriodStartTime').showPicker()">
            <label for="observationEffectivePeriodStartTime" class="label-modal">
                {{ __('observations.effective_period_start') }}
            </label>

            <div class="relative flex items-center">
                @icon('mingcute-time-fill', 'svg-input left-2.5')
                <input
                    x-model="modalObservation.effectivePeriodStartTime"
                    @input="$event.target.blur()"
                    type="time"
                    name="effectivePeriodStartTime"
                    id="observationEffectivePeriodStartTime"
                    class="input-modal pl-10!"
                    autocomplete="off"
                    :required="modalObservation.effectiveType === 'period'"
                />
            </div>
        </div>

        <div class="w-3/5" onclick="document.getElementById('observationEffectivePeriodEndTime').showPicker()">
            <label for="observationEffectivePeriodEndTime" class="label-modal">
                {{ __('observations.effective_period_end') }}
            </label>

            <div class="relative flex items-center">
                @icon('mingcute-time-fill', 'svg-input left-2.5')
                <input
                    x-model="modalObservation.effectivePeriodEndTime"
                    @input="$event.target.blur()"
                    type="time"
                    name="effectivePeriodEndTime"
                    id="observationEffectivePeriodEndTime"
                    class="input-modal pl-10!"
                    autocomplete="off"
                    :required="modalObservation.effectiveType === 'period'"
                />
            </div>
        </div>
    </div>

    <div class="form-row">
        <div>
            <label for="observationComment" class="label-modal"> {{ __('observations.comment') }} </label>

            <textarea
                rows="4"
                x-model="modalObservation.comment"
                id="observationComment"
                name="observationComment"
                class="textarea"
                placeholder="{{ __('forms.write_comment_here') }}"
            ></textarea>
        </div>
    </div>
</fieldset>
