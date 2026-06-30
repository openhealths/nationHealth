<fieldset class="fieldset" x-data="{ showContactPerson: false }">
    <legend class="legend flex items-baseline gap-2">
        <input type="checkbox"
               class="default-checkbox mb-2"
               x-model="showContactPerson"
               id="showContactPerson"
        />
        <label for="showContactPerson" class="cursor-pointer select-none">
            {{ __('patients.emergency_contact_mother_or_father') }}
        </label>
    </legend>

    <div x-show="showContactPerson" x-cloak>
        <div class="form-row-3">
            <div class="form-group group">
                <div class="relative w-full">
                    <input
                        wire:model="form.person.emergencyContact.firstName"
                        type="text"
                        name="emergencyContactFirstName"
                        id="emergencyContactFirstName"
                        class="input peer @error('form.person.emergencyContact.firstName') input-error @enderror"
                        placeholder=" "
                        :required="showContactPerson"
                        autocomplete="off"
                    />
                    <label for="emergencyContactFirstName" class="label">
                        {{ __('forms.first_name') }}
                    </label>
                    <button
                        type="button"
                        class="absolute inset-y-0 end-0 flex items-center pe-3 text-gray-400 hover:text-gray-600"
                        x-show="$wire.form.person.emergencyContact.firstName"
                        @click="$wire.set('form.person.emergencyContact.firstName', '')"
                    >
                        @icon('close', 'w-4 h-4')
                    </button>
                </div>
                @error('form.person.emergencyContact.firstName') <p class="text-error">{{ $message }}</p> @enderror
            </div>

            <div class="form-group group">
                <div class="relative w-full">
                    <input
                        wire:model="form.person.emergencyContact.lastName"
                        type="text"
                        name="emergencyContactLastName"
                        id="emergencyContactLastName"
                        class="input peer @error('form.person.emergencyContact.lastName') input-error @enderror"
                        placeholder=" "
                        :required="showContactPerson"
                        autocomplete="off"
                    />
                    <label for="emergencyContactLastName" class="label">
                        {{ __('forms.last_name') }}
                    </label>
                    <button
                        type="button"
                        class="absolute inset-y-0 end-0 flex items-center pe-3 text-gray-400 hover:text-gray-600"
                        x-show="$wire.form.person.emergencyContact.lastName"
                        @click="$wire.set('form.person.emergencyContact.lastName', '')"
                    >
                        @icon('close', 'w-4 h-4')
                    </button>
                </div>
                @error('form.person.emergencyContact.lastName') <p class="text-error">{{ $message }}</p> @enderror
            </div>

            <div class="form-group group">
                <div class="relative w-full">
                    <input
                        wire:model="form.person.emergencyContact.secondName"
                        type="text"
                        name="emergencyContactSecondName"
                        id="emergencyContactSecondName"
                        class="input peer @error('form.person.emergencyContact.secondName') input-error @enderror"
                        placeholder=" "
                        autocomplete="off"
                    />
                    <label for="emergencyContactSecondName" class="label">
                        {{ __('forms.second_name') }}
                    </label>
                    <button
                        type="button"
                        class="absolute inset-y-0 end-0 flex items-center pe-3 text-gray-400 hover:text-gray-600"
                        x-show="$wire.form.person.emergencyContact.secondName"
                        @click="$wire.set('form.person.emergencyContact.secondName', '')"
                    >
                        @icon('close', 'w-4 h-4')
                    </button>
                </div>
                @error('form.person.emergencyContact.secondName') <p class="text-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="form-row-3">
            <div class="form-group">
                <select
                    wire:model="form.person.emergencyContact.phones.0.type"
                    name="emergencyContactPhoneType"
                    id="emergencyContactPhoneType"
                    class="input-select peer @error('form.person.emergencyContact.phones.0.type') input-error @enderror"
                    :required="showContactPerson"
                >
                    <option value="" selected>{{ __('forms.select') }} *</option>
                    @foreach($this->dictionaries['PHONE_TYPE'] as $key => $phoneType)
                        <option value="{{ $key }}">{{ $phoneType }}</option>
                    @endforeach
                </select>
                <label for="emergencyContactPhoneType" class="label">
                    {{ __('forms.phone_type') }}
                </label>
                @error('form.person.emergencyContact.phones.0.type') <p class="text-error">{{ $message }}</p> @enderror
            </div>

            <div class="form-group group">
                <div class="phone-wrapper">
                    <input
                        wire:model="form.person.emergencyContact.phones.0.number"
                        x-mask="+380999999999"
                        type="tel"
                        name="emergencyContactPhone"
                        id="emergencyContactPhone"
                        class="input with-leading-icon peer @error('form.person.emergencyContact.phones.0.number') input-error @enderror"
                        placeholder=" "
                        :required="showContactPerson"
                    />
                    <label for="emergencyContactPhone" class="wrapped-label">
                        {{ __('forms.phone') }}
                    </label>
                </div>
                @error('form.person.emergencyContact.phones.0.number')
                <p class="text-error">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </div>
</fieldset>
