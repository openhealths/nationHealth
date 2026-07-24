@use('App\Enums\Person\Gender')

<fieldset class="fieldset">
    <legend class="legend">{{ __('patients.patient_information') }}</legend>

    {{-- Using Alpine to dynamically add and remove name groups filled in different languages --}}
    <div x-data="{ names: $wire.entangle('form.person.names') }">

        <template x-if="names && names.length > 0">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-x-6 gap-y-5 mb-6">

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
                    <label for="patientFirstName-0" class="label">
                        {{ __('forms.first_name') }}
                    </label>
                    @error('form.person.names.0.firstName') <p class="text-error">{{ $message }}</p> @enderror
                </div>

                <div class="flex items-center" x-data="{ hasLastName: !names[0].noLastName }" x-init="$watch('names[0].noLastName', value => hasLastName = !value)">
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox"
                               x-model="hasLastName"
                               x-on:change="names[0].noLastName = !hasLastName; if (!hasLastName) names[0].lastName = ''"
                               class="sr-only peer"
                        />
                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-blue-600"></div>
                        <span class="ml-3 text-sm font-semibold text-gray-900 dark:text-gray-300">
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
                        :required="!names[0].noLastName"
                        :disabled="names[0].noLastName"
                        autocomplete="off"
                    />
                    <label for="patientLastName-0" class="label">
                        {{ __('forms.last_name') }}
                    </label>
                    @error('form.person.names.0.lastName') <p class="text-error">{{ $message }}</p> @enderror
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
                    <label for="patientSecondName-0" class="label">
                        {{ __('forms.second_name') }}
                    </label>
                    @error('form.person.names.0.secondName') <p class="text-error">{{ $message }}</p> @enderror
                </div>

                <div class="form-group">
                    <select
                        x-model="names[0].language"
                        id="nameLanguage-0"
                        class="input-select peer @error('form.person.names.0.language') input-error @enderror"
                        required
                    >
                        <option value="" selected>{{ __('forms.select') }}</option>
                        @foreach($this->dictionaries['LANGUAGE'] as $key => $language)
                            <option value="{{ $key }}">{{ $language }}</option>
                        @endforeach
                    </select>
                    <label for="nameLanguage-0" class="label">
                        {{ __('patients.name_language') }}
                    </label>
                    @error('form.person.names.0.language') <p class="text-error">{{ $message }}</p> @enderror
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

                <div class="form-group">
                    <select
                        wire:model="form.person.gender"
                        name="patientGender"
                        id="patientGender"
                        class="input-select peer @error('form.person.gender') input-error @enderror"
                        required
                    >
                        <option value="" selected>{{ __('forms.select') }}</option>
                        @foreach($this->dictionaries['GENDER'] as $key => $gender)
                            <option value="{{ $key }}">{{ $gender }}</option>
                        @endforeach
                    </select>
                    <label for="patientGender" class="label">
                        {{ __('forms.gender') }}
                    </label>
                    @error('form.person.gender') <p class="text-error">{{ $message }}</p> @enderror
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

                <div></div>

                <div></div>

            </div>
        </template>

        <template x-for="(name, index) in names">
            <template x-if="index > 0">
                <div class="mt-6 border-t border-gray-200 dark:border-gray-700 pt-6" :key="'name-group-' + index">
                    <div class="flex items-center justify-between mb-4">
                        <h4 class="text-sm font-semibold text-gray-900 dark:text-white">
                            {{ __('forms.first_name') }} №<span x-text="index + 1"></span>
                        </h4>
                        <button @click="names.splice(index, 1)" type="button" class="text-sm text-red-600 hover:text-red-800 font-medium">
                            {{ __('patients.remove_name') }}
                        </button>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-x-6 gap-y-5">

                        <div class="form-group group">
                            <input
                                x-model="name.firstName"
                                type="text"
                                :id="'patientFirstName-' + index"
                                class="input peer"
                                placeholder=" "
                                required
                            />
                            <label :for="'patientFirstName-' + index" class="label">
                                {{ __('forms.first_name') }}
                            </label>
                        </div>

                        <div class="flex items-center" x-data="{ hasLastName: !name.noLastName }" x-init="$watch('name.noLastName', value => hasLastName = !value)">
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox"
                                       x-model="hasLastName"
                                       x-on:change="name.noLastName = !hasLastName; if (!hasLastName) name.lastName = ''"
                                       class="sr-only peer"
                                />
                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-0.5 after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-blue-600"></div>
                                <span class="ml-3 text-sm font-semibold text-gray-900 dark:text-gray-300">
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
                                placeholder=" "
                                :required="!name.noLastName"
                                :disabled="name.noLastName"
                            />
                            <label :for="'patientLastName-' + index" class="label">
                                {{ __('forms.last_name') }}
                            </label>
                        </div>

                        <div class="form-group group">
                            <input
                                x-model="name.secondName"
                                type="text"
                                :id="'patientSecondName-' + index"
                                class="input peer"
                                placeholder=" "
                            />
                            <label :for="'patientSecondName-' + index" class="label">
                                {{ __('forms.second_name') }}
                            </label>
                        </div>

                        <div class="form-group">
                            <select
                                x-model="name.language"
                                :id="'nameLanguage-' + index"
                                class="input-select peer"
                                required
                            >
                                <option value="" selected>{{ __('forms.select') }}</option>
                                @foreach($this->dictionaries['LANGUAGE'] as $key => $language)
                                    <option value="{{ $key }}">{{ $language }}</option>
                                @endforeach
                            </select>
                            <label :for="'nameLanguage-' + index" class="label">
                                {{ __('patients.name_language') }}
                            </label>
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
