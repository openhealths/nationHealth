<fieldset class="fieldset" x-data="{ unidentifiedReason: $wire.entangle('form.person.unidentifiedReason') }">
    <legend class="legend">
        {{ __('patients.patient_type') }}
    </legend>

    <div class="form-row-2">
        <div class="form-group">
            <select wire:model="form.person.patientType"
                    x-model="patientType"
                    name="patientType"
                    id="patientType"
                    class="input-select peer @error('form.person.patientType') input-error @enderror"
                    required
            >
                <option value="identified">{{ __('patients.identified') }}</option>
                <option value="unidentified">{{ __('patients.unidentified') }}</option>
            </select>
            <label for="patientType" class="label">
                {{ __('patients.patient_type') }}
            </label>

            @error('form.person.patientType')
            <p class="text-error">
                {{ $message }}
            </p>
            @enderror
        </div>
    </div>

    <div x-show="patientType === 'unidentified'" x-cloak class="contents">
        <div class="mb-6 p-6 rounded-xl bg-red-50 dark:bg-red-950/20 border border-red-100 dark:border-red-900/30 flex flex-col gap-3">
            <div class="flex items-center gap-2">
                @icon('alert-circle', 'w-5 h-5 text-red-600 dark:text-red-400')
                <h4 class="font-bold text-red-600 dark:text-red-400 text-lg">
                    {{ __('patients.unidentified_warning_title') }}
                </h4>
            </div>
            <div class="text-red-500 dark:text-red-300 text-sm leading-relaxed whitespace-pre-line">
                {{ __('patients.unidentified_warning_text') }}
            </div>
        </div>

        <div class="form-row-2">
            <div class="form-group">
                <select wire:model="form.person.unidentifiedReason"
                    x-model="unidentifiedReason"
                    name="unidentifiedReason"
                    id="unidentifiedReason"
                    class="input-select peer @error('form.person.unidentifiedReason') input-error @enderror"
                    required
                >
                    <option value="EMERGENCY_HOSPITALIZATION">
                        {{ __('patients.unidentified_reasons.EMERGENCY_HOSPITALIZATION') }}
                    </option>
                    <option value="POLICE_HOSPITALIZATION">
                        {{ __('patients.unidentified_reasons.POLICE_HOSPITALIZATION') }}
                    </option>
                    <option value="NEWBORN_WITHOUT_CERTIFICATE">
                        {{ __('patients.unidentified_reasons.NEWBORN_WITHOUT_CERTIFICATE') }}
                    </option>
                    <option value="OTHER_HOSPITALIZATION">
                        {{ __('patients.unidentified_reasons.OTHER_HOSPITALIZATION') }}
                    </option>
                </select>
                <label for="unidentifiedReason" class="label">
                    {{ __('patients.unidentified_reason') }}
                </label>

                @error('form.person.unidentifiedReason')
                <p class="text-error">
                    {{ $message }}
                </p>
                @enderror
            </div>
        </div>

        <!-- EMERGENCY_HOSPITALIZATION -->
        <div class="form-row-2" x-show="unidentifiedReason === 'EMERGENCY_HOSPITALIZATION'" wire:key="reason-emergency" x-cloak>
            <div class="form-group group">
                <div class="relative w-full">
                    <input wire:model="form.person.ambulanceCardNumber"
                           type="text"
                           name="ambulanceCardNumber"
                           id="ambulanceCardNumber"
                           class="input peer @error('form.person.ambulanceCardNumber') input-error @enderror"
                           placeholder=" "
                           autocomplete="off"
                    />
                    <label for="ambulanceCardNumber" class="label">
                        {{ __('patients.ambulance_card_number') }}
                    </label>
                    <button type="button"
                            class="absolute inset-y-0 end-0 flex items-center pe-3 text-gray-400 hover:text-gray-600"
                            x-show="$wire.form.person.ambulanceCardNumber"
                            @click="$wire.set('form.person.ambulanceCardNumber', '')"
                    >
                        @icon('close', 'w-4 h-4')
                    </button>
                </div>

                @error('form.person.ambulanceCardNumber')
                <p class="text-error">
                    {{ $message }}
                </p>
                @enderror
            </div>
        </div>

        <!-- POLICE_HOSPITALIZATION -->
        <div class="form-row-2" x-show="unidentifiedReason === 'POLICE_HOSPITALIZATION'" wire:key="reason-police" x-cloak>
            <div class="form-group group">
                <div class="relative w-full">
                    <input wire:model="form.person.policeReportId"
                           type="text"
                           name="policeReportId"
                           id="policeReportId"
                           class="input peer @error('form.person.policeReportId') input-error @enderror"
                           placeholder=" "
                           autocomplete="off"
                           :required="unidentifiedReason === 'POLICE_HOSPITALIZATION'"
                    />
                    <label for="policeReportId" class="label">
                        {{ __('patients.police_report_id') }}
                    </label>
                    <button type="button"
                            class="absolute inset-y-0 end-0 flex items-center pe-3 text-gray-400 hover:text-gray-600"
                            x-show="$wire.form.person.policeReportId"
                            @click="$wire.set('form.person.policeReportId', '')"
                    >
                        @icon('close', 'w-4 h-4')
                    </button>
                </div>

                @error('form.person.policeReportId')
                <p class="text-error">
                    {{ $message }}
                </p>
                @enderror
            </div>

            <div class="form-group group">
                <div class="datepicker-wrapper">
                    <input wire:model="form.person.policeReportDate"
                           datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                           type="text"
                           name="policeReportDate"
                           id="policeReportDate"
                           class="datepicker-input with-leading-icon input peer @error('form.person.policeReportDate') input-error @enderror"
                           placeholder=" "
                           autocomplete="off"
                           :required="unidentifiedReason === 'POLICE_HOSPITALIZATION'"
                    />
                    <label for="policeReportDate" class="wrapped-label">
                        {{ __('patients.police_report_date') }}
                    </label>
                </div>

                @error('form.person.policeReportDate')
                <p class="text-error">
                    {{ $message }}
                </p>
                @enderror
            </div>
        </div>

        <!-- NEWBORN_WITHOUT_CERTIFICATE -->
        <div class="form-row-2" x-show="unidentifiedReason === 'NEWBORN_WITHOUT_CERTIFICATE'" wire:key="reason-newborn" x-cloak>
            <div class="form-group group">
                <div class="relative">
                    @icon('clock', 'absolute left-2.5 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-500 dark:text-gray-400 pointer-events-none')
                    <input wire:model="form.person.childBirthTime"
                           type="time"
                           name="childBirthTime"
                           id="childBirthTime"
                           class="with-leading-icon input peer @error('form.person.childBirthTime') input-error @enderror"
                           placeholder=" "
                           autocomplete="off"
                           :required="unidentifiedReason === 'NEWBORN_WITHOUT_CERTIFICATE'"
                    />
                    <label for="childBirthTime" class="wrapped-label">
                        {{ __('patients.child_birth_time') }}
                    </label>
                </div>

                @error('form.person.childBirthTime')
                <p class="text-error">
                    {{ $message }}
                </p>
                @enderror
            </div>
        </div>

        <!-- OTHER_HOSPITALIZATION -->
        <div class="form-row-2" x-show="unidentifiedReason === 'OTHER_HOSPITALIZATION'" wire:key="reason-other" x-cloak>
            <div class="form-group group">
                <label for="unidentifiedOtherReason" class="label-secondary">
                    {{ __('patients.unidentified_other_reason') }} *
                </label>
                <textarea wire:model="form.person.unidentifiedOtherReason"
                          id="unidentifiedOtherReason"
                          name="unidentifiedOtherReason"
                          rows="4"
                          class="textarea @error('form.person.unidentifiedOtherReason') input-error @enderror"
                          placeholder="Текст для введення"
                          autocomplete="off"
                          :required="unidentifiedReason === 'OTHER_HOSPITALIZATION'"
                ></textarea>

                @error('form.person.unidentifiedOtherReason')
                <p class="text-error">
                    {{ $message }}
                </p>
                @enderror
            </div>
        </div>
    </div>
</fieldset>
