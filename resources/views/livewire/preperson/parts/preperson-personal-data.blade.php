<fieldset class="fieldset">
    <legend class="legend">
        {{ __('patients.patient_information') }}
    </legend>

    <div class="form-row-2">
        <div class="form-group group">
            <div class="relative w-full">
                <input
                    wire:model="form.person.lastName"
                    type="text"
                    name="patientLastName"
                    id="patientLastName"
                    class="input peer @error('form.person.lastName') input-error @enderror"
                    placeholder=" "
                    autocomplete="off"
                />
                <label for="patientLastName" class="label">
                    {{ __('forms.last_name') }}
                </label>
                <button
                    type="button"
                    class="absolute inset-y-0 end-0 flex items-center pe-3 text-gray-400 hover:text-gray-600"
                    x-show="$wire.form.person.lastName"
                    @click="$wire.set('form.person.lastName', '')"
                >
                    @icon('close', 'w-4 h-4')
                </button>
            </div>

            @error('form.person.lastName') <p class="text-error">{{ $message }}</p> @enderror
        </div>

        <div class="form-group group">
            <div class="relative w-full">
                <input
                    wire:model="form.person.firstName"
                    type="text"
                    name="patientFirstName"
                    id="patientFirstName"
                    class="input peer @error('form.person.firstName') input-error @enderror"
                    placeholder=" "
                    autocomplete="off"
                />
                <label for="patientFirstName" class="label">
                    {{ __('forms.first_name') }}
                </label>
                <button
                    type="button"
                    class="absolute inset-y-0 end-0 flex items-center pe-3 text-gray-400 hover:text-gray-600"
                    x-show="$wire.form.person.firstName"
                    @click="$wire.set('form.person.firstName', '')"
                >
                    @icon('close', 'w-4 h-4')
                </button>
            </div>

            @error('form.person.firstName') <p class="text-error">{{ $message }}</p> @enderror
        </div>
    </div>

    <div class="form-row-2">
        <div class="form-group group">
            <div class="relative w-full">
                <input
                    wire:model="form.person.secondName"
                    type="text"
                    name="patientSecondName"
                    id="patientSecondName"
                    class="input peer @error('form.person.secondName') input-error @enderror"
                    placeholder=" "
                    autocomplete="off"
                />
                <label for="patientSecondName" class="label">
                    {{ __('forms.second_name') }}
                </label>
                <button
                    type="button"
                    class="absolute inset-y-0 end-0 flex items-center pe-3 text-gray-400 hover:text-gray-600"
                    x-show="$wire.form.person.secondName"
                    @click="$wire.set('form.person.secondName', '')"
                >
                    @icon('close', 'w-4 h-4')
                </button>
            </div>

            @error('form.person.secondName') <p class="text-error">{{ $message }}</p> @enderror
        </div>

        <div class="form-group group">
            <div class="datepicker-wrapper">
                <input
                    wire:model="form.person.birthDate"
                    datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                    type="text"
                    name="birthDate"
                    id="birthDate"
                    class="datepicker-input with-leading-icon input peer @error('form.person.birthDate') input-error @enderror"
                    placeholder=" "
                    autocomplete="off"
                />
                <label for="birthDate" class="wrapped-label">
                    {{ __('forms.birth_date') }}
                </label>
            </div>

            @error('form.person.birthDate') <p class="text-error">{{ $message }}</p> @enderror
        </div>
    </div>

    <div class="form-row-2">
        <div class="form-group">
            <select
                wire:model="form.person.gender"
                name="patientGender"
                id="patientGender"
                class="input-select peer @error('form.person.gender') input-error @enderror"
                required
            >
                <option value="" selected>{{ __('forms.select') }} *</option>
                @foreach($this->dictionaries['GENDER'] as $key => $gender)
                    <option value="{{ $key }}">{{ $gender }}</option>
                @endforeach
            </select>
            <label for="patientGender" class="label">
                {{ __('forms.gender') }}
            </label>

            @error('form.person.gender')
            <p class="text-error">
                {{ $message }}
            </p>
            @enderror
        </div>
    </div>
</fieldset>
