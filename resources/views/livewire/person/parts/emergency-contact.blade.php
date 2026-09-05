<fieldset class="fieldset">
    <legend class="legend">{{ __('patients.emergency_contact') }}</legend>

    <div class="form-row-3">
        <div class="form-group group">
            <input
                wire:model="form.person.emergencyContact.firstName"
                type="text"
                name="patientFirstName"
                id="emergencyContactFirstName"
                class="input peer @error('form.person.emergencyContact.firstName') input-error @enderror"
                placeholder=" "
                required
                autocomplete="off"
            />
            <label for="emergencyContactFirstName" class="label"> {{ __('forms.first_name') }} </label>

            @error('form.person.emergencyContact.firstName')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="form-group group">
            <input
                wire:model="form.person.emergencyContact.lastName"
                type="text"
                name="patientFirstName"
                id="emergencyContactLastName"
                class="input peer @error('form.person.emergencyContact.lastName') input-error @enderror"
                placeholder=" "
                required
                autocomplete="off"
            />
            <label for="emergencyContactLastName" class="label"> {{ __('forms.last_name') }} </label>

            @error('form.person.emergencyContact.lastName')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="form-group group">
            <input
                wire:model="form.person.emergencyContact.secondName"
                type="text"
                name="emergencyContactSecondName"
                id="emergencyContactSecondName"
                class="input peer @error('form.person.emergencyContact.secondName') input-error @enderror"
                placeholder=" "
                autocomplete="off"
            />
            <label for="emergencyContactSecondName" class="label"> {{ __('forms.second_name') }} </label>

            @error('form.person.emergencyContact.secondName')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    {{-- Using Alpine to dynamically add and remove phone input fields --}}
    <div class="mb-4" x-data="{ emergencyContactPhones: $wire.entangle('form.person.emergencyContact.phones') }">
        <template x-for="(phone, index) in emergencyContactPhones">
            <div class="form-row-3 md:mb-0">
                <div class="form-group group">
                    <label :for="'emergencyContactPhoneType-' + index" class="sr-only">
                        {{ __('forms.type_mobile') }}
                    </label>
                    <select
                        x-model="phone.type"
                        :id="'emergencyContactPhoneType-' + index"
                        class="input-select peer"
                        required
                    >
                        <option selected>{{ __('forms.type_mobile') }} *</option>
                        @foreach ($this->dictionaries['PHONE_TYPE'] as $key => $phoneType)
                            <option value="{{ $key }}">{{ $phoneType }}</option>
                        @endforeach
                    </select>

                    @error('form.person.emergencyContact.phones.*.type')
                        <p class="text-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="form-group group">
                    <div class="phone-wrapper">
                        <input
                            x-model="phone.number"
                            x-mask="+999999999999"
                            type="tel"
                            name="emergencyContactPhone"
                            :id="'emergencyContactPhone-' + index"
                            class="input with-leading-icon peer @error('form.person.emergencyContact.phones.*.number') input-error @enderror"
                            placeholder=" "
                            required
                        />
                        <label :for="'emergencyContactPhone-' + index" class="wrapped-label">
                            {{ __('forms.phone_number') }}
                        </label>
                    </div>

                    @error('form.person.emergencyContact.phones.*.number')
                        <p class="text-error">{{ $message }}</p>
                    @enderror
                </div>
                <template x-if="(index == emergencyContactPhones.length - 1) & (index != 0)">
                    {{-- Remove a phone if button is clicked --}}
                    <button @click="(emergencyContactPhones.pop(), index--)" class="item-remove">
                        {{ __('forms.remove_phone') }}
                    </button>
                </template>
                <template x-if="index == emergencyContactPhones.length - 1">
                    {{-- Add new phone if button is clicked --}}
                    <button
                        @click="emergencyContactPhones.push({ type: '', number: '' })"
                        class="item-add lg:justify-self-start"
                        :class="{ 'lg:justify-self-start': index > 0 }"
                        {{-- Apply this style only if it's not a first phone group --}}
                    >
                        {{ __('forms.add_phone') }}
                    </button>
                </template>
            </div>
        </template>
    </div>
</fieldset>
