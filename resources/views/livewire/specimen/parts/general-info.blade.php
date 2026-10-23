@php
    $isEncounter = ($context ?? null) === 'encounter';
@endphp

<fieldset class="fieldset-card mb-6 p-4 sm:p-8 sm:pb-10">
    <legend class="legend">{{ __('specimens.general_info') }}</legend>

    <div class="form-row-2">
        <div class="form-group group">
            <select x-model="modalSpecimen.typeCode" id="specimenType" class="input-select peer" required>
                <option value="" selected>{{ __('forms.select') }}</option>
                @foreach ($this->dictionaries['specimen_types'] as $code => $specimenType)
                    <option value="{{ $code }}">{{ $specimenType }}</option>
                @endforeach
            </select>
            <label for="specimenType" class="label">{{ __('specimens.specimen_type') }}</label>
        </div>

        <div class="form-group group">
            <select
                x-model="modalSpecimen.conditionCode"
                @change="if (! modalSpecimen.conditionCode) modalSpecimen.conditionText = '';"
                id="specimenCondition"
                class="input-select peer"
            >
                <option value="" selected>{{ __('forms.select') }}</option>
                @foreach ($this->dictionaries['specimen_conditions'] as $code => $specimenCondition)
                    <option value="{{ $code }}">{{ $specimenCondition }}</option>
                @endforeach
            </select>
            <label for="specimenCondition" class="label">{{ __('specimens.specimen_condition') }}</label>
        </div>
    </div>

    <div class="form-row-2 mt-6">
        <div class="form-group group">
            <input
                x-model="modalSpecimen.typeText"
                type="text"
                id="specimenTypeText"
                class="input peer"
                placeholder=" "
                autocomplete="off"
            />
            <label for="specimenTypeText" class="label">{{ __('specimens.specimen_type_clarification') }}</label>
        </div>

        <div class="form-group group">
            <input
                x-model="modalSpecimen.conditionText"
                :disabled="! modalSpecimen.conditionCode"
                type="text"
                id="specimenConditionText"
                class="input peer"
                placeholder=" "
                autocomplete="off"
            />
            <label for="specimenConditionText" class="label">
                {{ __('specimens.specimen_condition_clarification') }}
            </label>
        </div>
    </div>

    <div class="form-row-2 mt-6">
        <div class="form-group group">
            <input
                type="text"
                id="specimenStatus"
                class="input-select peer cursor-not-allowed! text-gray-500! dark:text-gray-400!"
                @if ($isEncounter)
                    :value="statusName(modalSpecimen.uuid)"
                @else
                    value="{{ __('specimens.statuses.available') }}"
                @endif
                disabled
            />
            <label for="specimenStatus" class="label">{{ __('forms.status.label') }}</label>
        </div>

        @unless ($isEncounter)
            <div class="form-group group">
                <select
                    x-model="modalSpecimen.registeredById"
                    @change="changeRegisteredBy()"
                    id="specimenRegisteredBy"
                    class="input-select peer"
                    required
                >
                    <option value="" selected>{{ __('forms.select') }}</option>
                    @foreach ($registeredByEmployees as $employee)
                        <option value="{{ $employee['uuid'] }}">
                            {{ $employee['name'] }} ({{ $this->dictionaries['POSITION'][$employee['position']] ?? $employee['position'] }})
                        </option>
                    @endforeach
                </select>
                <label for="specimenRegisteredBy" class="label">{{ __('specimens.employee_created_record') }}</label>
            </div>
        @endunless

        @if ($isEncounter)
            <div
                class="form-group group relative flex justify-between"
                x-show="isReferenced(modalSpecimen.uuid)"
                x-cloak
            >
                <div class="datepicker-wrapper flex-1">
                    <input
                        x-model="modalSpecimen.receivedDate"
                        type="text"
                        id="specimenReceivedDate"
                        autocomplete="off"
                        class="datepicker-input with-leading-icon input peer rounded-r-none border-r-0"
                        placeholder=" "
                    />
                    <label
                        for="specimenReceivedDate"
                        class="wrapped-label"
                    >{{ __('specimens.date_time_received') }}</label>
                </div>
                <div class="relative -ml-px w-32">
                    <label for="specimenReceivedTime" class="sr-only">{{ __('forms.time') }}</label>
                    <input
                        x-model="modalSpecimen.receivedTime"
                        type="time"
                        id="specimenReceivedTime"
                        class="input peer rounded-l-none pl-10"
                        placeholder=" "
                    />
                    @icon('clock', 'svg-input left-2.5 text-gray-400')
                </div>
            </div>
        @endif
    </div>

    @if ($isEncounter)
        <div class="form-row-2 mt-6" x-show="isReferenced(modalSpecimen.uuid)" x-cloak>
            <div class="form-group group">
                <input
                    type="text"
                    id="specimenStatusReason"
                    class="input-select peer cursor-not-allowed! text-gray-500! dark:text-gray-400!"
                    :value="$wire.dictionaries['specimen_invalidate_reasons']['used']"
                    disabled
                />
                <label for="specimenStatusReason" class="label">{{ __('specimens.unavailability_reason') }}</label>
            </div>
        </div>
    @endif

    <div class="form-row-2 mt-6">
        <div class="form-group group" x-effect="if (! modalSpecimen.parentIds.length) modalSpecimen.parentIds.push('');">
            <p class="label-modal mb-2 block">{{ __('specimens.parent_specimen') }}</p>

            <template x-for="(parentId, parentIndex) in modalSpecimen.parentIds" :key="parentIndex">
                <div class="mt-4 flex items-center gap-2">
                    <div class="form-group group relative flex-1">
                        <label :for="`specimenParent${parentIndex}`" class="sr-only">
                            {{ __('specimens.parent_specimen') }}
                        </label>
                        <select
                            x-model="modalSpecimen.parentIds[parentIndex]"
                            :id="`specimenParent${parentIndex}`"
                            class="input-select peer"
                        >
                            <option value="" selected>{{ __('forms.select') }}</option>
                            <template x-for="parent in parentOptions()" :key="parent.uuid">
                                <option
                                    :value="parent.uuid"
                                    :selected="parent.uuid === modalSpecimen.parentIds[parentIndex]"
                                    x-text="
                                        [parent.accessionIdentifier, specimenTypesDictionary[parent.typeCode]]
                                            .filter(Boolean)
                                            .join(' - ') || parent.uuid
                                    "
                                ></option>
                            </template>
                        </select>
                    </div>
                    <button
                        type="button"
                        @click="modalSpecimen.parentIds.splice(parentIndex, 1)"
                        class="mt-2 text-gray-400 transition-colors hover:text-red-500 dark:text-gray-500 dark:hover:text-red-500"
                    >
                        @icon('delete', 'w-5 h-5')
                    </button>
                </div>
            </template>

            <div class="mt-2">
                <button
                    type="button"
                    @click="modalSpecimen.parentIds.push('')"
                    class="cursor-pointer text-sm font-medium text-blue-600 hover:text-blue-800"
                >
                    {{ __('specimens.add_parent_specimen') }}
                </button>
            </div>
        </div>
    </div>

    <div class="form-row-1 mt-6">
        <div class="form-group group">
            <label for="specimenNote" class="label-modal mb-2 block">{{ __('specimens.note') }}</label>
            <textarea
                x-model="modalSpecimen.note"
                id="specimenNote"
                class="textarea"
                rows="3"
                placeholder="{{ __('forms.text_for_input') }}"
            ></textarea>
        </div>
    </div>
</fieldset>
