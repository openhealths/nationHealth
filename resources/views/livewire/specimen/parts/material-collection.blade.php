<fieldset class="fieldset-card mb-6 p-4 sm:p-8 sm:pb-10">
    <legend class="legend">{{ __('specimens.material_collection') }}</legend>

    <div class="form-row-2">
        <div class="form-group group">
            <select
                x-model="modalSpecimen.collectorType"
                @change="changeCollectorType()"
                id="collectionCollectorType"
                class="input-select peer"
                required
            >
                <option value="current">{{ __('specimens.current_employee') }}</option>
                <option value="other">{{ __('specimens.other_employee') }}</option>
                <option value="patient">{{ __('forms.patient') }}</option>
            </select>
            <label for="collectionCollectorType" class="label">{{ __('specimens.collector') }}</label>
        </div>

        <div class="form-group group" x-show="modalSpecimen.collectorType === 'current'" x-cloak>
            <input
                type="text"
                id="collectionCurrentCollector"
                class="input-select peer cursor-not-allowed! text-gray-500! dark:text-gray-400!"
                :value="currentEmployee.name"
                disabled
            />
            <label for="collectionCurrentCollector" class="label">{{ __('specimens.current_employee') }}</label>
        </div>

        <div class="form-group group" x-show="modalSpecimen.collectorType === 'other'" x-cloak>
            <select x-model="modalSpecimen.collectorId" id="collectionOtherCollector" class="input-select peer">
                <option value="" selected>{{ __('forms.select') }}</option>
                @foreach ($employees as $employee)
                    <option value="{{ $employee['uuid'] }}">{{ $employee['name'] }}</option>
                @endforeach
            </select>
            <label for="collectionOtherCollector" class="label">{{ __('specimens.select_other_employee') }}</label>
        </div>
    </div>

    <div class="mt-6 mb-4 flex flex-wrap items-center gap-8">
        <span class="text-sm font-bold text-gray-700 dark:text-gray-300">{{ __('specimens.when_done') }}</span>

        <div class="flex items-center">
            <input
                x-model="modalSpecimen.collectedType"
                id="specimenCollectedTypeDateTime"
                type="radio"
                value="date_time"
                name="specimenCollectedType"
                class="default-radio"
            />
            <label
                for="specimenCollectedTypeDateTime"
                class="ms-2 text-sm font-medium text-gray-900 dark:text-gray-300"
            >
                {{ __('specimens.exact_date_and_time') }}
            </label>
        </div>

        <div class="flex items-center">
            <input
                x-model="modalSpecimen.collectedType"
                id="specimenCollectedTypePeriod"
                type="radio"
                value="period"
                name="specimenCollectedType"
                class="default-radio"
            />
            <label for="specimenCollectedTypePeriod" class="ms-2 text-sm font-medium text-gray-900 dark:text-gray-300">
                {{ __('specimens.period') }}
            </label>
        </div>
    </div>

    <div class="form-row-2" x-show="modalSpecimen.collectedType === 'date_time'" x-cloak>
        <div class="form-group group relative flex justify-between">
            <div class="flex-1">
                <x-forms.date
                    x-model="modalSpecimen.collectedDate"
                    x-bind:datepicker-max-date="{{ $maxCollectedDate ?? 'null' }}"
                    id="specimenCollectedDate"
                    class="rounded-r-none border-r-0"
                    x-bind:required="modalSpecimen.collectedType === 'date_time'"
                    :label="__('specimens.date_time')"
                />
            </div>
            <div class="-ml-px w-32">
                <x-forms.time
                    x-model="modalSpecimen.collectedTime"
                    id="specimenCollectedTime"
                    class="rounded-l-none"
                    x-bind:required="modalSpecimen.collectedType === 'date_time'"
                    :label="__('forms.time')"
                />
            </div>
        </div>
    </div>

    <div class="form-row-3" x-show="modalSpecimen.collectedType === 'period'" x-cloak>
        <div class="form-group group relative">
            <input
                x-model="modalSpecimen.collectedPeriodRange"
                type="text"
                id="specimenCollectedPeriodRange"
                class="daterangepicker-uk input peer"
                autocomplete="off"
                placeholder=" "
                :required="modalSpecimen.collectedType === 'period'"
            />
            <label for="specimenCollectedPeriodRange" class="label">{{ __('specimens.period') }}</label>
        </div>

        <div class="form-group group">
            <x-forms.time
                x-model="modalSpecimen.collectedPeriodStartTime"
                id="specimenCollectedPeriodStartTime"
                x-bind:required="modalSpecimen.collectedType === 'period'"
                :label="__('specimens.period_start')"
            />
        </div>

        <div class="form-group group">
            <x-forms.time
                x-model="modalSpecimen.collectedPeriodEndTime"
                id="specimenCollectedPeriodEndTime"
                :label="__('specimens.period_end')"
            />
        </div>
    </div>

    <div class="form-row-2 mt-6">
        <div class="form-group group flex items-start gap-2">
            <div class="relative flex-1">
                <input
                    x-model="modalSpecimen.durationValue"
                    type="number"
                    min="0"
                    step="any"
                    id="specimenDurationValue"
                    class="input peer"
                    placeholder=" "
                />
                <label for="specimenDurationValue" class="label">{{ __('specimens.collection_duration') }}</label>
            </div>
            <div class="w-40">
                <label for="specimenDurationCode" class="sr-only">{{ __('specimens.unit') }}</label>
                <select x-model="modalSpecimen.durationCode" id="specimenDurationCode" class="input-select peer">
                    <option value="" selected>{{ __('forms.select') }}</option>
                    @foreach (config('ehealth.specimen.duration_allowed_codes') as $code)
                        <option value="{{ $code }}">{{ $this->dictionaries['eHealth/ucum/units'][$code] }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="form-group group flex items-start gap-2">
            <div class="relative flex-1">
                <input
                    x-model="modalSpecimen.quantityValue"
                    type="number"
                    min="0"
                    step="any"
                    id="specimenQuantityValue"
                    class="input peer"
                    placeholder=" "
                />
                <label for="specimenQuantityValue" class="label">{{ __('specimens.material_amount') }}</label>
            </div>
            <div class="w-40">
                <label for="specimenQuantityCode" class="sr-only">{{ __('specimens.unit') }}</label>
                <select x-model="modalSpecimen.quantityCode" id="specimenQuantityCode" class="input-select peer">
                    <option value="" selected>{{ __('forms.select') }}</option>
                    @foreach ($this->dictionaries['eHealth/ucum/units'] as $code => $unit)
                        <option value="{{ $code }}">{{ $unit }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    <div class="form-row-2 mt-6">
        <div class="form-group group">
            <select x-model="modalSpecimen.methodCode" id="collectionMethod" class="input-select peer">
                <option value="" selected>{{ __('forms.select') }}</option>
                @foreach ($this->dictionaries['specimen_collection_methods'] as $code => $collectionMethod)
                    <option value="{{ $code }}">{{ $collectionMethod }}</option>
                @endforeach
            </select>
            <label for="collectionMethod" class="label">{{ __('specimens.collection_method') }}</label>
        </div>

        <div class="form-group group">
            <x-select2
                modelPath="modalSpecimen.bodySiteCode"
                dictionaryName="eHealth/body_sites"
                id="collectionBodySite"
                class="input peer"
            />
            <label for="collectionBodySite" class="label">{{ __('specimens.body_site') }}</label>
        </div>
    </div>

    <div class="form-row-2 mt-6">
        <div class="form-group group">
            <select x-model="modalSpecimen.fastingStatusCode" id="collectionFastingStatus" class="input-select peer">
                <option value="" selected>{{ __('forms.select') }}</option>
                @foreach ($this->dictionaries['fasting_statuses'] as $code => $fastingStatus)
                    <option value="{{ $code }}">{{ $fastingStatus }}</option>
                @endforeach
            </select>
            <label for="collectionFastingStatus" class="label">{{ __('specimens.fasting_status') }}</label>
        </div>

        @if (($context ?? null) === 'encounter')
            <div class="form-group group">
                <select x-model="modalSpecimen.procedureId" id="collectionProcedure" class="input-select peer">
                    <option value="" selected>{{ __('forms.select') }}</option>
                    <template x-for="procedure in procedures" :key="procedure.uuid">
                        <option :value="procedure.uuid" x-text="procedureLabel(procedure)"></option>
                    </template>
                </select>
                <label for="collectionProcedure" class="label">{{ __('specimens.procedure_during_collection') }}</label>
            </div>
        @endif
    </div>
</fieldset>
