<div x-show="showFilter"
     wire:key="{{ time() }}"
>
    <div class="form-row-4">
        <div class="form-group group">
            <input
                wire:model="form.firstName"
                type="text"
                name="filterFirstName"
                id="filterFirstName"
                class="input peer @error('form.firstName') input-error @enderror"
                placeholder=" "
                required
                autocomplete="off"
            />
            <label for="filterFirstName" class="label">
                {{ __('forms.first_name') }}
            </label>

            @error('form.firstName')
            <p class="text-error">
                {{ $message }}
            </p>
            @enderror
        </div>

        <div class="form-group group">
            <input
                wire:model="form.lastName"
                type="text"
                name="filterLastName"
                id="filterLastName"
                class="input peer @error('form.lastName') input-error @enderror"
                placeholder=" "
                autocomplete="off"
                :disabled="$wire.form.noLastName"
            />
            <label for="filterLastName" class="label">
                {{ __('forms.last_name') }} <span x-show="!$wire.form.noLastName">*</span>
            </label>

            <div class="flex items-center gap-2 mt-2">
                <label for="filterNoLastName" class="default-label">
                    {{ __('patients.no_last_name') }}
                </label>
                <input
                    wire:model="form.noLastName"
                    x-on:change="if ($event.target.checked) $wire.set('form.lastName', '', false);"
                    type="checkbox"
                    name="filterNoLastName"
                    id="filterNoLastName"
                    class="default-checkbox"
                />
            </div>

            @error('form.lastName')
            <p class="text-error">
                {{ $message }}
            </p>
            @enderror
        </div>

        <div class="form-group">
            <label for="filterLanguage" class="label">
                {{ __('patients.name_language') }}
            </label>
            <select
                wire:model="form.language"
                name="filterLanguage"
                id="filterLanguage"
                class="input-select peer @error('form.language') input-error @enderror"
                required
            >
                <option value="">{{ __('forms.select') }} *</option>
                @foreach($this->dictionaries['LANGUAGE'] as $key => $language)
                    <option value="{{ $key }}">{{ $language }}</option>
                @endforeach
            </select>

            @error('form.language')
            <p class="text-error">
                {{ $message }}
            </p>
            @enderror
        </div>

        <div class="form-group group">
            <div class="datepicker-wrapper">
                <input
                    wire:model="form.birthDate"
                    datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                    type="text"
                    name="filterBirthDate"
                    id="filterBirthDate"
                    class="datepicker-input with-leading-icon input peer @error('form.birthDate') input-error @enderror"
                    placeholder=" "
                    required
                    autocomplete="off"
                />
                <label for="filterBirthDate" class="wrapped-label">
                    {{ __('forms.birth_date') }}
                </label>
            </div>

            @error('form.birthDate')
            <p class="text-error">
                {{ $message }}
            </p>
            @enderror
        </div>
    </div>

    <div x-data="{ showAdditionalParams: $wire.entangle('showAdditionalParams') }">
        <button type="button"
                class="flex items-center gap-2 button-minor mb-2"
                @click.prevent="showAdditionalParams = !showAdditionalParams"
        >
            @icon('adjustments', 'w-4 h-4')
            <span>{{ __('forms.additional_search_parameters') }}</span>
        </button>

        <div x-show="showAdditionalParams" x-transition x-cloak>
            <div class="form-row-3">
                <div class="form-group group">
                    <input
                        wire:model="form.secondName"
                        type="text"
                        name="filterSecondName"
                        id="filterSecondName"
                        class="input peer @error('form.secondName') input-error @enderror"
                        placeholder=" "
                        autocomplete="off"
                    />
                    <label for="filterSecondName" class="label">
                        {{ __('forms.second_name') }}
                    </label>

                    @error('form.secondName')
                    <p class="text-error">
                        {{ $message }}
                    </p>
                    @enderror
                </div>

                <div class="form-group group">
                    <input
                        wire:model="form.taxId"
                        type="text"
                        name="filterTaxId"
                        id="filterTaxId"
                        class="input peer @error('form.taxId') input-error @enderror"
                        placeholder=" "
                        maxlength="10"
                        autocomplete="off"
                    />
                    <label for="filterTaxId" class="label">
                        {{ __('forms.rnokpp') }} ({{ __('forms.ipn') }})
                    </label>

                    @error('form.taxId')
                    <p class="text-error">
                        {{ $message }}
                    </p>
                    @enderror
                </div>
            </div>

            <div class="form-row-3">
                <div class="form-group">
                    <label for="filterDocumentType" class="label">
                        {{ __('forms.document_type') }}
                    </label>
                    <select
                        wire:model="form.documentType"
                        name="filterDocumentType"
                        id="filterDocumentType"
                        class="input-select peer @error('form.documentType') input-error @enderror"
                    >
                        <option value="">{{ __('forms.select') }}</option>
                        @foreach($this->dictionaries['DOCUMENT_TYPE'] as $key => $documentType)
                            <option value="{{ $key }}">{{ $documentType }}</option>
                        @endforeach
                    </select>

                    @error('form.documentType')
                    <p class="text-error">
                        {{ $message }}
                    </p>
                    @enderror
                </div>

                <div class="form-group group">
                    <input
                        wire:model="form.documentNumber"
                        type="text"
                        name="filterDocumentNumber"
                        id="filterDocumentNumber"
                        class="input peer @error('form.documentNumber') input-error @enderror"
                        placeholder=" "
                        autocomplete="off"
                    />
                    <label for="filterDocumentNumber" class="label">
                        {{ __('forms.document_number') }}
                    </label>

                    @error('form.documentNumber')
                    <p class="text-error">
                        {{ $message }}
                    </p>
                    @enderror
                </div>
            </div>

            <div class="form-row-3">
                <div class="form-group group">
                    <input
                        wire:model="form.phoneNumber"
                        name="filterPhoneNumber"
                        id="filterPhoneNumber"
                        type="text"
                        class="input peer @error('form.phoneNumber') input-error @enderror"
                        placeholder=" "
                        autocomplete="off"
                        x-mask="+380999999999"
                    />
                    <label for="filterPhoneNumber" class="label">
                        {{ __('forms.phone_number') }}
                    </label>

                    @error('form.phoneNumber')
                    <p class="text-error">
                        {{ $message }}
                    </p>
                    @enderror
                </div>

                @if($context === 'index')
                    <div class="form-group group">
                        <select
                            id="filterDropdown"
                            class="input-select peer"
                            :value="$wire.activeFilter === 'all' ? '' : $wire.activeFilter"
                            @change="$wire.activeFilter = $el.value || 'all'"
                        >
                            <option value="">
                                {{ __('forms.all') }}
                            </option>
                            <option value="local">
                                {{ __('patients.source.local') }}
                            </option>
                            <option value="ehealth">
                                {{ __('patients.source.ehealth') }}
                            </option>
                            <option value="request">
                                {{ __('patients.applications') }}
                            </option>
                        </select>
                        <label for="filterDropdown" class="label">{{ __('forms.type_drafts_patients') }}</label>
                        </div>
                @endif
            </div>
        </div>
    </div>
</div>
