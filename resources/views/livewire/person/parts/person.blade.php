@use('App\Enums\Person\Gender')

<fieldset class="fieldset">
    <legend class="legend">{{ __('patients.patient_information') }}</legend>

    {{-- Using Alpine to dynamically add and remove name groups filled in different languages. The name groups after
         the first are drawn by Alpine, so they read their errors from the data attribute, read again each time the
         component has been redrawn --}}
    <div
        data-name-errors="{{ json_encode($errors->get('form.person.names.*')) }}"
        x-data="{
             names: $wire.entangle('form.person.names'),
             documents: $wire.entangle('form.person.documents'),
             foreignDocumentTypes: @js(config('ehealth.identity_document_types_foreign')),
             nameErrors: {},
             init() {
                 this.nameErrors = JSON.parse(this.$root.dataset.nameErrors);
                 this.$wire.$hook('morphed', () => this.nameErrors = JSON.parse(this.$root.dataset.nameErrors));
             },
             nameError(index, field) {
                 return (this.nameErrors[`form.person.names.${index}.${field}`] ?? [])[0] ?? '';
             },
             get hasForeignDocument() {
                 return (this.documents ?? []).some(document => this.foreignDocumentTypes.includes(document.type));
             }
         }"
        x-effect="if (hasForeignDocument && $wire.get('form.person.unzr')) $wire.set('form.person.unzr', '', false);"
    >
        <template x-if="names && names.length > 0">
            <div class="mb-6 grid grid-cols-1 gap-x-6 gap-y-5 md:grid-cols-3">
                <div class="form-group group">
                    <input
                        x-model="names[0].firstName"
                        type="text"
                        id="patientFirstName-0"
                        class="input peer @error('form.person.names.0.firstName') input-error @enderror"
                        placeholder=" "
                        required
                        autocomplete="off"
                    />
                    <label for="patientFirstName-0" class="label"> {{ __('forms.first_name') }} </label>
                    @error('form.person.names.0.firstName')
                        <p class="text-error">{{ $message }}</p>
                    @enderror
                </div>

                <div
                    class="flex items-center"
                    x-data="{ hasLastName: ! names[0].noLastName }"
                    x-init="$watch('names[0].noLastName', (value) => (hasLastName = ! value))"
                >
                    <label class="form-toggle">
                        <input
                            type="checkbox"
                            x-model="hasLastName"
                            x-on:change="
                                names[0].noLastName = ! hasLastName;
                                if (! hasLastName) names[0].lastName = '';
                            "
                            class="form-toggle-input"
                        />
                        <div class="form-toggle-track" aria-hidden="true"></div>
                        <span class="form-toggle-label">
                            {{ __('patients.has_last_name') }}
                        </span>
                    </label>
                </div>

                <div class="form-group group">
                    <input
                        x-model="names[0].lastName"
                        type="text"
                        id="patientLastName-0"
                        class="input peer @error('form.person.names.0.lastName') input-error @enderror"
                        placeholder=" "
                        :required="! names[0].noLastName"
                        :disabled="names[0].noLastName"
                        autocomplete="off"
                    />
                    <label for="patientLastName-0" class="label"> {{ __('forms.last_name') }} </label>
                    @error('form.person.names.0.lastName')
                        <p class="text-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="form-group group">
                    <input
                        x-model="names[0].secondName"
                        type="text"
                        id="patientSecondName-0"
                        class="input peer @error('form.person.names.0.secondName') input-error @enderror"
                        placeholder=" "
                        autocomplete="off"
                    />
                    <label for="patientSecondName-0" class="label"> {{ __('forms.second_name') }} </label>
                    @error('form.person.names.0.secondName')
                        <p class="text-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="form-group">
                    <select
                        x-model="names[0].language"
                        id="nameLanguage-0"
                        class="input-select peer @error('form.person.names.0.language') input-error @enderror"
                        required
                    >
                        <option value="" selected>{{ __('forms.select') }}</option>
                        @foreach ($this->dictionaries['LANGUAGE'] as $key => $language)
                            <option value="{{ $key }}">{{ $language }}</option>
                        @endforeach
                    </select>
                    <label for="nameLanguage-0" class="label"> {{ __('patients.name_language') }} </label>
                    @error('form.person.names.0.language')
                        <p class="text-error">{{ $message }}</p>
                    @enderror
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
                            required
                            autocomplete="off"
                        />
                        <label for="birthDate" class="wrapped-label"> {{ __('forms.birth_date') }} </label>
                    </div>
                    @error('form.person.birthDate')
                        <p class="text-error">{{ $message }}</p>
                    @enderror
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
                    <label for="birthCountry" class="label"> {{ __('forms.birth_country') }} </label>
                    @error('form.person.birthCountry')
                        <p class="text-error">{{ $message }}</p>
                    @enderror
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
                    <label for="birthSettlement" class="label"> {{ __('forms.birth_settlement') }} </label>
                    @error('form.person.birthSettlement')
                        <p class="text-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="form-group">
                    <select
                        wire:model="form.person.gender"
                        name="patientGender"
                        id="patientGender"
                        class="input-select peer @error('form.person.gender') input-error @enderror"
                        required
                    >
                        <option value="" selected>{{ __('forms.select') }}</option>
                        @foreach ($this->dictionaries['GENDER'] as $key => $gender)
                            <option value="{{ $key }}">{{ $gender }}</option>
                        @endforeach
                    </select>
                    <label for="patientGender" class="label"> {{ __('forms.gender') }} </label>
                    @error('form.person.gender')
                        <p class="text-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="form-group group">
                    {{-- Required only along with the biometric passport --}}
                    <input
                        wire:model="form.person.unzr"
                        :required="(documents ?? []).some((document) => document.type === 'NATIONAL_ID')"
                        type="text"
                        name="unzr"
                        id="unzr"
                        x-mask="99999999-99999"
                        inputmode="numeric"
                        pattern="[0-9]{8}-[0-9]{5}"
                        aria-describedby="unzr-format"
                        class="input peer @error('form.person.unzr') input-error @enderror"
                        placeholder=" "
                        maxlength="14"
                        autocomplete="off"
                    />
                    <label for="unzr" class="label"> {{ __('patients.unzr') }} </label>
                    <p id="unzr-format" class="text-note">
                        {{ __('patients.unzr_format') }}
                    </p>
                    @error('form.person.unzr')
                        <p class="text-error">{{ $message }}</p>
                    @enderror
                </div>

                <div></div>

                <div></div>
            </div>
        </template>

        <template x-for="(name, index) in names">
            <template x-if="index > 0">
                <div class="mt-6 border-t border-gray-200 pt-6 dark:border-gray-700" :key="'name-group-' + index">
                    <div class="mb-4 flex items-center justify-between">
                        <h4 class="text-sm font-semibold text-gray-900 dark:text-white">
                            {{ __('forms.first_name') }} №<span x-text="index + 1"></span>
                        </h4>
                        <button @click="names.splice(index, 1)" type="button" class="item-remove">
                            {{ __('patients.remove_name') }}
                        </button>
                    </div>
                    <div class="grid grid-cols-1 gap-x-6 gap-y-5 md:grid-cols-3">
                        <div class="form-group group">
                            <input
                                x-model="name.firstName"
                                type="text"
                                :id="'patientFirstName-' + index"
                                class="input peer"
                                :class="{ 'input-error': nameError(index, 'firstName') }"
                                placeholder=" "
                                required
                            />
                            <label :for="'patientFirstName-' + index" class="label">
                                {{ __('forms.first_name') }}
                            </label>
                            <p
                                class="text-error"
                                x-show="nameError(index, 'firstName')"
                                x-text="nameError(index, 'firstName')"
                            ></p>
                        </div>

                        <div
                            class="flex items-center"
                            x-data="{ hasLastName: ! name.noLastName }"
                            x-init="$watch('name.noLastName', (value) => (hasLastName = ! value))"
                        >
                            <label class="form-toggle">
                                <input
                                    type="checkbox"
                                    x-model="hasLastName"
                                    x-on:change="
                                        name.noLastName = ! hasLastName;
                                        if (! hasLastName) name.lastName = '';
                                    "
                                    class="form-toggle-input"
                                />
                                <div class="form-toggle-track" aria-hidden="true"></div>
                                <span class="form-toggle-label">
                                    {{ __('patients.has_last_name') }}
                                </span>
                            </label>
                        </div>

                        <div class="form-group group">
                            <input
                                x-model="name.lastName"
                                type="text"
                                :id="'patientLastName-' + index"
                                class="input peer"
                                :class="{ 'input-error': nameError(index, 'lastName') }"
                                placeholder=" "
                                :required="! name.noLastName"
                                :disabled="name.noLastName"
                            />
                            <label :for="'patientLastName-' + index" class="label"> {{ __('forms.last_name') }} </label>
                            <p
                                class="text-error"
                                x-show="nameError(index, 'lastName')"
                                x-text="nameError(index, 'lastName')"
                            ></p>
                        </div>

                        <div class="form-group group">
                            <input
                                x-model="name.secondName"
                                type="text"
                                :id="'patientSecondName-' + index"
                                class="input peer"
                                :class="{ 'input-error': nameError(index, 'secondName') }"
                                placeholder=" "
                            />
                            <label :for="'patientSecondName-' + index" class="label">
                                {{ __('forms.second_name') }}
                            </label>
                            <p
                                class="text-error"
                                x-show="nameError(index, 'secondName')"
                                x-text="nameError(index, 'secondName')"
                            ></p>
                        </div>

                        <div class="form-group">
                            <select
                                x-model="name.language"
                                :id="'nameLanguage-' + index"
                                class="input-select peer"
                                :class="{ 'input-error': nameError(index, 'language') }"
                                required
                            >
                                <option value="" selected>{{ __('forms.select') }}</option>
                                @foreach ($this->dictionaries['LANGUAGE'] as $key => $language)
                                    <option value="{{ $key }}">{{ $language }}</option>
                                @endforeach
                            </select>
                            <label :for="'nameLanguage-' + index" class="label">
                                {{ __('patients.name_language') }}
                            </label>
                            <p
                                class="text-error"
                                x-show="nameError(index, 'language')"
                                x-text="nameError(index, 'language')"
                            ></p>
                        </div>
                    </div>
                </div>
            </template>
        </template>

        <button
            @click="names.push({ language: '', noLastName: false, lastName: '', firstName: '', secondName: '' })"
            type="button"
            class="item-add my-5"
        >
            {{ __('patients.add_name') }}
        </button>
    </div>
</fieldset>
