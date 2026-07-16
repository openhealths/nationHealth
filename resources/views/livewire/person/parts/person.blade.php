<fieldset class="fieldset">
    <legend class="legend">{{ __('patients.patient_information') }}</legend>

    {{-- Using Alpine to dynamically add and remove name groups filled in different languages --}}
    <div x-data="{ names: $wire.entangle('form.person.names') }">
        <template x-for="(name, index) in names">
            <div class="mb-4">
                <div class="form-row-4">
                    <div class="form-group">
                        <label :for="'nameLanguage-' + index" class="label">
                            {{ __('patients.name_language') }}
                        </label>
                        <select
                            x-model="name.language"
                            :id="'nameLanguage-' + index"
                            class="input-select peer @error('form.person.names.*.language') input-error @enderror"
                            required
                        >
                            <option value="" selected>{{ __('forms.select') }} *</option>
                            @foreach($this->dictionaries['LANGUAGE'] as $key => $language)
                                <option value="{{ $key }}">{{ $language }}</option>
                            @endforeach
                        </select>

                        @error('form.person.names.*.language') <p class="text-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="form-group group">
                        <input
                            x-model="name.lastName"
                            type="text"
                            :id="'patientLastName-' + index"
                            class="input peer @error('form.person.names.*.lastName') input-error @enderror"
                            placeholder=" "
                            :required="!name.noLastName"
                            :disabled="name.noLastName"
                            autocomplete="off"
                        />
                        <label :for="'patientLastName-' + index" class="label">
                            {{ __('forms.last_name') }}
                        </label>

                        <div class="flex items-center gap-2 mt-2">
                            <label :for="'patientNoLastName-' + index" class="default-label">
                                {{ __('patients.no_last_name') }}
                            </label>
                            <input
                                x-model="name.noLastName"
                                @change="if (name.noLastName) name.lastName = ''"
                                type="checkbox"
                                :id="'patientNoLastName-' + index"
                                class="default-checkbox"
                            />
                        </div>

                        @error('form.person.names.*.lastName') <p class="text-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="form-group group">
                        <input
                            x-model="name.firstName"
                            type="text"
                            :id="'patientFirstName-' + index"
                            class="input peer @error('form.person.names.*.firstName') input-error @enderror"
                            placeholder=" "
                            required
                            autocomplete="off"
                        />
                        <label :for="'patientFirstName-' + index" class="label">
                            {{ __('forms.first_name') }}
                        </label>

                        @error('form.person.names.*.firstName') <p class="text-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="form-group group">
                        <input
                            x-model="name.secondName"
                            type="text"
                            :id="'patientSecondName-' + index"
                            class="input peer @error('form.person.names.*.secondName') input-error @enderror"
                            placeholder=" "
                            autocomplete="off"
                        />
                        <label :for="'patientSecondName-' + index" class="label">
                            {{ __('forms.second_name') }}
                        </label>

                        @error('form.person.names.*.secondName') <p class="text-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <template x-if="index != 0">
                    <button @click="names.splice(index, 1)" type="button" class="item-remove">
                        {{ __('patients.remove_name') }}
                    </button>
                </template>
            </div>
        </template>

        <button
            @click="names.push({ language: '', noLastName: false, lastName: '', firstName: '', secondName: '' })"
            type="button"
            class="item-add my-5"
        >
            {{ __('patients.add_name') }}
        </button>
    </div>

    <div class="form-row-3">
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
            <input
                wire:model="form.person.birthCountry"
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
            <input
                wire:model="form.person.birthSettlement"
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
            <select
                wire:model="form.person.gender"
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
            <input
                wire:model="form.person.unzr"
                required
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
</fieldset>
