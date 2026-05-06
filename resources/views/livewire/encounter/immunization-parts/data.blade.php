<fieldset class="fieldset">
    <legend class="legend">
        {{ __('patients.data') }}
    </legend>

    <div class="form-row-3">
        {{-- Fix autofocus that was on date --}}
        <button class="sr-only" autofocus tabindex="-1"></button>
        <div>
            <label for="immunizationDate" class="label-modal">
                {{ __('forms.date') }}
            </label>
            <div class="relative flex items-center">
                @icon('calendar-week', 'svg-input absolute left-2.5 pointer-events-none')
                <input x-model="modalImmunization.date"
                       datepicker-max-date="{{ now()->format('Y-m-d') }}"
                       type="text"
                       name="immunizationDate"
                       id="immunizationDate"
                       class="datepicker-input input-modal !pl-10"
                       autocomplete="off"
                       required
                >
            </div>

            <p class="text-error text-xs" x-show="modalImmunization.date.trim() === ''">
                {{ __('forms.field_empty') }}
            </p>
        </div>

        <div class="w-1/2" onclick="document.getElementById('immunizationTime').showPicker()">
            <label for="immunizationTime" class="label-modal">
                {{ __('patients.time') }}
            </label>

            <div class="relative flex items-center">
                @icon('mingcute-time-fill', 'svg-input left-2.5')
                <input x-model="modalImmunization.time"
                       @input="$event.target.blur()"
                       datepicker-max-date="{{ now()->format('Y-m-d') }}"
                       type="time"
                       name="immunizationTime"
                       id="immunizationTime"
                       class="input-modal !pl-10"
                       autocomplete="off"
                       required
                >
            </div>

            <p class="text-error text-xs" x-show="modalImmunization.time.trim() === ''">
                {{ __('forms.field_empty') }}
            </p>
        </div>
    </div>

    <div class="mt-12">
        <div class="flex gap-20 md:mb-5 mb-4">
            <h2 class="default-p font-bold">{{ __('patients.was_performed') }}</h2>
            <div class="flex items-center">
                <input x-model="modalImmunization.notGiven"
                       @change="modalImmunization.notGiven = false; if (modalImmunization.reasons.length === 0) modalImmunization.reasons = [{code: ''}]"
                       id="yes"
                       type="radio"
                       value="false"
                       name="notGiven"
                       class="default-radio"
                       :checked="modalImmunization.notGiven === false"
                >
                <label for="yes" class="ms-2 text-sm font-medium text-gray-900 dark:text-gray-300">
                    {{ __('forms.yes') }}
                </label>
            </div>

            <div class="flex items-center">
                <input x-model="modalImmunization.notGiven"
                       @change="modalImmunization.notGiven = true; modalImmunization.primarySource = true"
                       id="no"
                       type="radio"
                       value="true"
                       name="notGiven"
                       class="default-radio"
                       :checked="modalImmunization.notGiven === true"
                >
                <label for="no" class="ms-2 text-sm font-medium text-gray-900 dark:text-gray-300">
                    {{ __('forms.no') }}
                </label>
            </div>
        </div>

        <div>
            <div x-show="modalImmunization.notGiven === false" class="form-group group">
                <template x-for="(reason, index) in modalImmunization.reasons" :key="index">
                    <div class="form-row-modal md:mb-0">
                        <div class="form-group group">
                            <label :for="'reasonExplanation-' + index" class="label-modal">
                                {{ __('patients.reasons') }}
                            </label>
                            <select x-model="modalImmunization.reasons[index].code"
                                    :id="'reasonExplanation-' + index"
                                    class="input-modal"
                                    required
                            >
                                <option value="" selected>{{ __('forms.select') }}</option>
                                @foreach($this->dictionaries['eHealth/reason_explanations'] as $key => $reasonExplanation)
                                    <option value="{{ $key }}">{{ $reasonExplanation }}</option>
                                @endforeach
                            </select>

                            <p class="text-error text-xs"
                               x-show="!Object.keys(reasonExplanationsDictionary).includes(modalImmunization.reasons[index]?.code)"
                            >
                                {{ __('forms.field_empty') }}
                            </p>
                        </div>

                        <!-- Remove Button -->
                        <template x-if="index == modalImmunization.reasons.length - 1 & index != 0">
                            <button type="button"
                                    @click="modalImmunization.reasons.pop(), index--"
                                    class="item-remove"
                            >
                                {{ __('forms.delete') }}
                            </button>
                        </template>
                        <!-- Add Button -->
                        <template x-if="index === modalImmunization.reasons.length - 1">
                            <button type="button"
                                    @click="modalImmunization.reasons.push({code: ''})"
                                    class="item-add lg:justify-self-start"
                                    :class="{ 'lg:justify-self-start': index > 0 }"
                            >
                                {{ __('forms.add') }}
                            </button>
                        </template>
                    </div>
                </template>
            </div>

            <div x-show="modalImmunization.notGiven === true" class="form-group group !w-1/2">
                <label for="reasonsNotGiven" class="label-modal">
                    {{ __('patients.reasons') }}
                </label>
                <select type="text"
                        x-model="modalImmunization.reasonNotGivenCode"
                        id="reasonsNotGiven"
                        class="input-modal"
                        required
                >
                    <option selected>{{ __('forms.select') }}</option>
                    @foreach($this->dictionaries['eHealth/reason_not_given_explanations'] as $key => $reasonNotGivenExplanation)
                        <option value="{{ $key }}">{{ $reasonNotGivenExplanation }}</option>
                    @endforeach
                </select>

                <p class="text-error text-xs"
                   x-show="!Object.keys(reasonNotGivenExplanationsDictionary).includes(modalImmunization.reasonNotGivenCode)"
                >
                    {{ __('forms.field_empty') }}
                </p>
            </div>
        </div>
    </div>

    <div class="mt-12" x-show="modalImmunization.notGiven === false">
        <div class="flex gap-20 md:mb-5 mb-4">
            <h2 class="default-p font-bold">{{ __('patients.information_source') }}</h2>
            <div class="flex items-center">
                <input x-model.boolean="modalImmunization.primarySource"
                       id="performer"
                       type="radio"
                       value="true"
                       name="primarySource"
                       class="default-radio"
                       :checked="modalImmunization.primarySource === true"
                >
                <label for="performer" class="ms-2 text-sm font-medium text-gray-900 dark:text-gray-300">
                    {{ __('patients.performer') }}
                </label>
            </div>

            <div class="flex items-center">
                <input x-model.boolean="modalImmunization.primarySource"
                       id="patient"
                       type="radio"
                       value="false"
                       name="primarySource"
                       class="default-radio"
                       :checked="modalImmunization.primarySource === false"
                >
                <label for="patient" class="ms-2 text-sm font-medium text-gray-900 dark:text-gray-300">
                    {{ __('forms.patient') }}
                </label>
            </div>
        </div>

        <div x-show="modalImmunization.primarySource === false" x-transition>
            <div class="form-row-modal">
                <div>
                    <label for="reportOrigin" class="label-modal">
                        {{ __('patients.source_link') }}
                    </label>
                    <select class="input-modal"
                            x-model="modalImmunization.reportOriginCode"
                            id="reportOrigin"
                            type="text"
                            required
                    >
                        <option selected>{{ __('forms.select') }}</option>
                        @foreach($this->dictionaries['eHealth/immunization_report_origins'] as $key => $reportOrigin)
                            <option value="{{ $key }}">{{ $reportOrigin }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="form-row-modal">
                <div class="form-group group">
                    <label for="doctorComment" class="label-modal">
                        {{ __('forms.additional_info') }}
                    </label>
                    <textarea class="textarea"
                              x-model="modalImmunization.reportOriginText"
                              id="doctorComment"
                              name="doctorComment"
                              rows="4"
                              placeholder="{{ __('forms.write_comment_here') }}"
                    ></textarea>
                </div>
            </div>
        </div>
    </div>
</fieldset>
