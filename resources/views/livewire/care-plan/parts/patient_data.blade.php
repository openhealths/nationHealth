<fieldset class="fieldset !mb-6 !max-w-full !rounded-xl !border-gray-100 bg-white !p-6 !shadow-none dark:!border-gray-700 dark:bg-gray-800">
    <legend class="legend">{{ __('care-plan.patient_data') ?? 'Дані пацієнта' }}</legend>

    @if ($allowsPatientChange && $personId <= 0)
        <div>
            <div class="mb-4 flex items-center gap-1.5 font-semibold text-gray-900 dark:text-white">
                @icon('search-outline', 'w-4.5 h-4.5')
                <p>{{ __('patients.patient_search') }}</p>
            </div>

            <div class="form-row-3">
                <div class="form-group group">
                    <input
                        wire:model="patientSearch.firstName"
                        type="text"
                        name="searchFirstName"
                        id="searchFirstName"
                        class="input peer @error('patientSearch.firstName') input-error @enderror"
                        placeholder=" "
                        autocomplete="off"
                    />
                    <label for="searchFirstName" class="label"> {{ __('forms.first_name') }} </label>
                    @error('patientSearch.firstName')
                        <p class="text-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="form-group group">
                    <input
                        wire:model="patientSearch.lastName"
                        type="text"
                        name="searchLastName"
                        id="searchLastName"
                        class="input peer @error('patientSearch.lastName') input-error @enderror"
                        placeholder=" "
                        autocomplete="off"
                    />
                    <label for="searchLastName" class="label"> {{ __('forms.last_name') }} </label>
                    @error('patientSearch.lastName')
                        <p class="text-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="form-group group">
                    <div class="datepicker-wrapper">
                        <input
                            wire:model="patientSearch.birthDate"
                            datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                            type="text"
                            name="searchBirthDate"
                            id="searchBirthDate"
                            class="datepicker-input with-leading-icon input peer @error('patientSearch.birthDate') input-error @enderror"
                            placeholder=" "
                            autocomplete="off"
                        />
                        <label for="searchBirthDate" class="wrapped-label"> {{ __('forms.birth_date') }} </label>
                    </div>
                    @error('patientSearch.birthDate')
                        <p class="text-error">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="mt-4">
                <button
                    wire:click.prevent="searchForPatient"
                    type="button"
                    class="button-primary flex items-center gap-2"
                    wire:loading.attr="disabled"
                    wire:target="searchForPatient"
                >
                    @icon('search', 'w-4 h-4')
                    <span>{{ __('care-plan.search') }}</span>
                </button>
            </div>

            @if (!empty($patientSearchResults))
                <div class="index-table-wrapper mt-6 overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
                    <table class="index-table w-full table-auto">
                        <thead class="index-table-thead">
                            <tr>
                                <th scope="col" class="index-table-th">{{ __('care-plan.patient') }}</th>
                                <th scope="col" class="index-table-th">{{ __('forms.birth_date') }}</th>
                                <th scope="col" class="index-table-th text-center">{{ __('forms.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($patientSearchResults as $result)
                                <tr class="index-table-tr" wire:key="patient-search-{{ $result['id'] }}">
                                    <td class="index-table-td font-medium text-gray-900 dark:text-white">
                                        {{ $result['name'] }}
                                    </td>
                                    <td class="index-table-td text-gray-600 dark:text-gray-300">
                                        {{ $result['birthDate'] }}
                                    </td>
                                    <td class="index-table-td text-center">
                                        <button
                                            type="button"
                                            wire:click="selectPatient({{ $result['id'] }})"
                                            class="button-primary-outline px-4 py-1.5 text-sm"
                                        >
                                            {{ __('forms.select') }}
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @else
        <div class="form-row-2">
            <div class="form-group group relative">
                <input
                    type="text"
                    name="patient"
                    id="patient"
                    class="input peer"
                    placeholder=" "
                    autocomplete="off"
                    wire:model="form.patient"
                    readonly
                    required
                />

                <label for="patient" class="label"> {{ __('care-plan.patient') }} </label>

                @error('form.patient')
                    <p class="text-error" id="error-form-patient">{{ $message }}</p>
                @enderror
            </div>

            <div class="form-group group">
                <input
                    type="text"
                    name="medical_number"
                    id="medical_number"
                    class="input peer"
                    placeholder=" "
                    autocomplete="off"
                    wire:model="form.medical_number"
                    readonly
                    required
                />

                <label for="medical_number" class="label">
                    {{ __('care-plan.medical_number') ?? 'Медичний запис №' }}
                </label>
                @error('form.medical_number')
                    <p class="text-error" id="error-form-medical_number">{{ $message }}</p>
                @enderror
            </div>
        </div>

        @if ($allowsPatientChange && $personId > 0)
            <div class="mt-2">
                <button
                    type="button"
                    wire:click="clearSelectedPatient"
                    class="text-sm font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400"
                >
                    {{ __('patients.change') }}
                </button>
            </div>
        @endif
    @endif

    @if ($personId > 0)
        <div class="form-row-2 mt-5">
            <div class="form-group group">
                <select
                    id="encounter_select"
                    name="encounter_select"
                    class="input-select peer"
                    wire:model.live="form.encounter"
                >
                    <option value="">{{ __('forms.select') }} ...</option>
                    @foreach ($availableEncounters as $enc)
                        <option value="{{ $enc['uuid'] }}">{{ $enc['label'] }}</option>
                    @endforeach
                </select>
                <label for="encounter_select" class="label"> {{ __('care-plan.encounter') ?? 'Взаємодія' }} </label>
                @if (empty($availableEncounters))
                    <p class="mt-1 text-sm text-amber-600">
                        ⚠️ {{ __('care-plan.no_ehealth_encounters') ?? 'У пацієнта немає підтверджених ЕСОЗ взаємодій. Спочатку створіть та підпишіть взаємодію.' }}
                    </p>
                @endif
                @error('form.encounter')
                    <p class="text-error">{{ $message }}</p>
                @enderror
            </div>
        </div>
    @endif
</fieldset>
