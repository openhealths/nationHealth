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
            <x-select2
                modelPath="modalObservation.bodySiteCode"
                dictionaryName="eHealth/body_sites"
                id="bodySite"
                required
            />
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
            <x-forms.date
                modal
                x-model="modalObservation.issuedDate"
                datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                name="issuedDate"
                id="observationIssuedDate"
                required
                :label="__('observations.result_received_at')"
            />

            <p class="text-error text-xs" x-show="modalObservation.issuedDate.trim() === ''">
                {{ __('forms.field_empty') }}
            </p>
        </div>

        <div class="w-3/5">
            <x-forms.time
                modal
                x-model="modalObservation.issuedTime"
                name="issuedTime"
                id="observationIssuedTime"
                required
                :label="__('forms.time')"
            />

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
            <x-forms.date
                modal
                x-model="modalObservation.effectiveDate"
                datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                name="effectiveDate"
                id="effectiveDate"
                x-bind:required="modalObservation.effectiveType === 'date_time'"
                :label="__('observations.date_and_time_of_receiving_the_indicators')"
            />
        </div>

        <div class="w-3/5">
            <x-forms.time
                modal
                x-model="modalObservation.effectiveTime"
                name="effectiveTime"
                id="effectiveTime"
                x-bind:required="modalObservation.effectiveType === 'date_time'"
                :label="__('forms.time')"
            />
        </div>
    </div>

    <div class="form-row-4" x-show="modalObservation.effectiveType === 'period'" x-cloak>
        <div>
            <label for="effectivePeriodRange" class="label-modal"> {{ __('observations.effective_period') }} </label>
            <div class="relative flex items-center">
                @icon('calendar-week', 'w-5 h-5 svg-input absolute left-2.5 pointer-events-none')
                <input
                    x-model="modalObservation.effectivePeriodRange"
                    datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                    type="text"
                    name="effectivePeriodRange"
                    id="effectivePeriodRange"
                    class="daterangepicker-uk input-modal pl-10!"
                    autocomplete="off"
                    :required="modalObservation.effectiveType === 'period'"
                />
            </div>
        </div>

        <div class="w-3/5">
            <x-forms.time
                modal
                x-model="modalObservation.effectivePeriodStartTime"
                name="effectivePeriodStartTime"
                id="observationEffectivePeriodStartTime"
                x-bind:required="modalObservation.effectiveType === 'period'"
                :label="__('observations.effective_period_start')"
            />
        </div>

        <div class="w-3/5">
            <x-forms.time
                modal
                x-model="modalObservation.effectivePeriodEndTime"
                name="effectivePeriodEndTime"
                id="observationEffectivePeriodEndTime"
                x-bind:required="modalObservation.effectiveType === 'period'"
                :label="__('observations.effective_period_end')"
            />
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
