<fieldset class="fieldset">
    <legend class="legend">
        {{ __('patients.patient_information') }}
    </legend>

    <div x-show="patientType === 'unidentified'" x-cloak class="contents">
        <div class="form-row-2">
            <div class="form-group group">
                <div class="relative w-full">
                    <input wire:model="form.person.lastName"
                           type="text"
                           name="patientLastNameUnidentified"
                           id="patientLastNameUnidentified"
                           class="input peer @error('form.person.lastName') input-error @enderror"
                           placeholder=" "
                           required
                           autocomplete="off"
                    />
                    <label for="patientLastNameUnidentified" class="label">
                        {{ __('forms.last_name') }}
                    </label>
                    <button type="button"
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
                    <input wire:model="form.person.firstName"
                           type="text"
                           name="patientFirstNameUnidentified"
                           id="patientFirstNameUnidentified"
                           class="input peer @error('form.person.firstName') input-error @enderror"
                           placeholder=" "
                           required
                           autocomplete="off"
                    />
                    <label for="patientFirstNameUnidentified" class="label">
                        {{ __('forms.first_name') }}
                    </label>
                    <button type="button"
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
                    <input wire:model="form.person.secondName"
                           type="text"
                           name="patientSecondNameUnidentified"
                           id="patientSecondNameUnidentified"
                           class="input peer @error('form.person.secondName') input-error @enderror"
                           placeholder=" "
                           autocomplete="off"
                    />
                    <label for="patientSecondNameUnidentified" class="label">
                        {{ __('forms.second_name') }}
                    </label>
                    <button type="button"
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
                    <input wire:model="form.person.birthDate"
                           datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                           type="text"
                           name="birthDateUnidentified"
                           id="birthDateUnidentified"
                           class="datepicker-input with-leading-icon input peer @error('form.person.birthDate') input-error @enderror"
                           placeholder=" "
                           required
                           autocomplete="off"
                    />
                    <label for="birthDateUnidentified" class="wrapped-label">
                        {{ __('forms.birth_date') }}
                    </label>
                </div>

                @error('form.person.birthDate') <p class="text-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="form-row-2">
            <div class="form-group">
                <select wire:model="form.person.gender"
                        name="patientGenderUnidentified"
                        id="patientGenderUnidentified"
                        class="input-select peer @error('form.person.gender') input-error @enderror"
                        required
                >
                    <option value="" selected>{{ __('forms.select') }} *</option>
                    @foreach($this->dictionaries['GENDER'] as $key => $gender)
                        <option value="{{ $key }}">{{ $gender }}</option>
                    @endforeach
                </select>
                <label for="patientGenderUnidentified" class="label">
                    {{ __('forms.gender') }}
                </label>

                @error('form.person.gender')
                <p class="text-error">
                    {{ $message }}
                </p>
                @enderror
            </div>
        </div>
    </div>
    <div x-show="patientType === 'identified'" x-cloak class="contents">
        <div class="form-row-3">
            <div class="form-group group">
                <input wire:model="form.person.firstName"
                       type="text"
                       name="patientFirstName"
                       id="patientFirstName"
                       class="input peer @error('form.person.firstName') input-error @enderror"
                       placeholder=" "
                       required
                       autocomplete="off"
                />
                <label for="patientFirstName" class="label">
                    {{ __('forms.first_name') }}
                </label>

                @error('form.person.firstName') <p class="text-error">{{ $message }}</p> @enderror
            </div>

            <div class="form-group group">
                <input wire:model="form.person.lastName"
                       type="text"
                       name="patientLastName"
                       id="patientLastName"
                       class="input peer @error('form.person.lastName') input-error @enderror"
                       placeholder=" "
                       required
                       autocomplete="off"
                />
                <label for="patientLastName" class="label">
                    {{ __('forms.last_name') }}
                </label>

                @error('form.person.lastName') <p class="text-error">{{ $message }}</p> @enderror
            </div>

            <div class="form-group group">
                <input wire:model="form.person.secondName"
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

                @error('form.person.secondName') <p class="text-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="form-row-3">
            <div class="form-group group">
                <div class="datepicker-wrapper">
                    <input wire:model="form.person.birthDate"
                           datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                           type="text"
                           name="birthDate"
                           id="birthDate"
                           class="datepicker-input with-leading-icon input peer @error('form.person.birthDate') input-error @enderror"
                           placeholder=" "
                           required
                           autocomplete="off"
                    />
                    <label for="birthDate" class="wrapped-label">
                        {{ __('forms.birth_date') }}
                    </label>
                </div>

                @error('form.person.birthDate') <p class="text-error">{{ $message }}</p> @enderror
            </div>

            <div class="form-group group">
                <input wire:model="form.person.birthCountry"
                       type="text"
                       name="birthCountry"
                       id="birthCountry"
                       class="input peer @error('form.person.birthCountry') input-error @enderror"
                       placeholder=" "
                       required
                       autocomplete="off"
                />
                <label for="birthCountry" class="label">
                    {{ __('forms.birth_country') }}
                </label>

                @error('form.person.birthCountry') <p class="text-error">{{ $message }}</p> @enderror
            </div>

            <div class="form-group group">
                <input wire:model="form.person.birthSettlement"
                       type="text"
                       name="birthSettlement"
                       id="birthSettlement"
                       class="input peer @error('form.person.birthSettlement') input-error @enderror"
                       placeholder=" "
                       required
                       autocomplete="off"
                />
                <label for="birthSettlement" class="label">
                    {{ __('forms.birth_settlement') }}
                </label>

                @error('form.person.birthSettlement') <p class="text-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="form-row-3">
            <div class="form-group">
                <select wire:model="form.person.gender"
                        name="patientGender"
                        id="patientGender"
                        class="input-select peer
                        @error('form.person.gender') input-error @enderror"
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

            <div class="form-group group">
                <input wire:model="form.person.unzr"
                       type="text"
                       name="unzr"
                       id="unzr"
                       class="input peer @error('form.person.unzr') input-error @enderror"
                       placeholder=" "
                       maxlength="14"
                       autocomplete="off"
                />
                <label for="unzr" class="label">
                    {{ __('patients.unzr') }}
                </label>

                @error('form.person.unzr') <p class="text-error">{{ $message }}</p> @enderror
            </div>
        </div>
    </div>
</fieldset>
