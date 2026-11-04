@php
    $isDetails ??= false; // Disable showing the change owner switch if the form is in details or create mode
@endphp

<fieldset
    class="p-4 sm:p-8 sm:pb-10 mb-16 mt-6 border border-gray-200 rounded-lg shadow dark:bg-gray-800 dark:border-gray-700 max-w-7xl"
    xmlns="http://www.w3.org/1999/html"
    x-data="{
        index: 2,
        title: '{{ __('forms.owner') }}',

        isDisabled: @json($isEdit || $isDetails),
        isOwnerChange: $wire.entangle('isOwnerChanged'),

        owner: $wire.entangle('legalEntityForm.owner'),
        ownerData: {},
        tempOwnerData: {},

        defaultDoc: { type: '', number: '', issuedBy: '', issuedAt: '' },
        defaultPhone: { type: '', number: '' },
        keysMap: new WeakMap(),

        getKey(doc) {
            if (!this.keysMap.has(doc)) {
                this.keysMap.set(doc, crypto.randomUUID());
            }
            return this.keysMap.get(doc);
        },
        initTempOwnerData() {
            this.tempOwnerData = Object.keys(this.owner).reduce((acc, key) => ({ ...acc, [key]: null }), {});
            this.tempOwnerData.noTaxId = this.owner.noTaxId;
            this.tempOwnerData.phones = [{ ...this.defaultPhone }];
            this.tempOwnerData.documents = [{ ...this.defaultDoc }];
        },
        updateTaxIdInput() {
            if (this.isOwnerChange ? this.tempOwnerData.noTaxId : this.owner.noTaxId) {
                this.$refs.taxIdInput.value = ''
            } else {
                this.isOwnerChange
                    ? this.$refs.taxIdInput.value = (this.tempOwnerData.taxId ?? '')
                    : this.$refs.taxIdInput.value = this.owner.taxId
            }
        },
        ownerChange() {
            if (this.isOwnerChange) {
                this.isDisabled = false;
                this.copyOwner(this.owner, this.ownerData);
                this.copyOwner(this.tempOwnerData, this.owner);
                this.owner.noTaxId = this.tempOwnerData.noTaxId;
                this.updateTaxIdInput();
            } else {
                this.isDisabled = @json($isEdit);
                this.copyOwner(this.owner, this.tempOwnerData);
                this.tempOwnerData.noTaxId = this.owner.noTaxId;
                this.copyOwner(this.ownerData, this.owner);
            }
        },
        copyOwner(from, to) {
            for (const key in from) {
                if (Array.isArray(from[key])) {
                    to[key] = from[key].map(item => ({ ...item }));
                } else if (from[key] !== null && typeof from[key] === 'object') {
                    to[key] = { ...from[key] };
                } else {
                    to[key] = from[key];
                }
            }
        }
    }"
    x-init="
        typeof addHeader !== 'undefined' && addHeader(title, index);
        initTempOwnerData();
        if (!Array.isArray(owner.phones) || owner.phones.length === 0) { owner.phones = [{ ...defaultPhone }] };
        if (!Array.isArray(owner.documents) || owner.documents.length === 0) { owner.documents = [{ ...defaultDoc }] };
    "
    x-cloak
    x-show="activeStep === index || isEdit"
    :key="`step-${index}`"
>
    <template x-if="isEdit">
        <legend x-text="title" class="legend"></legend>
    </template>

    <div class="flex items-center justify-between mb-2">
        <h3 class="font-bold text-sm text-gray-600 mb-6">{{ __('forms.credentials_owner') }} *</h3>

        @if($isEdit && !$isDetails)
            <label class="form-toggle">
                <input
                    type="checkbox"
                    id="isOwnerChange"
                    class="form-toggle-input"
                    x-model="isOwnerChange"
                    :checked="isOwnerChange"
                    @change="ownerChange()"
                >
                <div class="form-toggle-track" aria-hidden="true"></div>
                <span
                    class="form-toggle-label"
                    x-text="'{{ __('forms.owner_change') }}'"
                ></span>
            </label>
        @endif
    </div>

    <div class='form-row-3'>
        {{-- Owner Last Name --}}
        <div class="form-group group">
            <input
                required
                type="text"
                placeholder=" "
                id="ownerLastName"
                x-model="owner.lastName"
                aria-describedby="@error('legalEntityForm.owner.lastName') ownerLastNameErrorHelp @enderror"
                class="input @error('legalEntityForm.owner.lastName') input-error border-red-500 focus:border-red-500 scroll-to-error @enderror peer"
                x-effect="$el.value = isOwnerChange ? (tempOwnerData.lastName ?? '') : owner.lastName ?? ''"
                @blur="if (isOwnerChange) { tempOwnerData.lastName = $event.target.value } else { owner.lastName = $event.target.value }"
            />

            @error('legalEntityForm.owner.lastName')
                <p id="ownerLastNameErrorHelp" class="text-error">
                    {{ $message }}
                </p>
            @enderror

            <label for="ownerLastName" class="label z-10">
                {{ __('forms.last_name') }}
            </label>
        </div>

        {{-- Owner First Name --}}
        <div class="form-group group">
            <input
                required
                type="text"
                placeholder=" "
                id="ownerFirstName"
                x-model="owner.firstName"
                aria-describedby="@error('legalEntityForm.owner.firstName') ownerFirstNameErrorHelp @enderror"
                class="input @error('legalEntityForm.owner.firstName') input-error border-red-500 focus:border-red-500 scroll-to-error @enderror peer"
                x-effect="$el.value = isOwnerChange ? (tempOwnerData.firstName ?? '') : owner.firstName ?? ''"
                @blur="if (isOwnerChange) { tempOwnerData.firstName = $event.target.value } else { owner.firstName = $event.target.value }"
            />

            @error('legalEntityForm.owner.firstName')
                <p id="ownerFirstNameErrorHelp" class="text-error">
                    {{ $message }}
                </p>
            @enderror

            <label for="ownerFirstName" class="label z-10">
                {{ __('forms.first_name') }}
            </label>
        </div>

        {{-- Owner Second Name --}}
        <div class="form-group group">
            <input
                type="text"
                placeholder=" "
                id="ownerSecondName"
                x-model="owner.secondName"
                aria-describedby="@error('legalEntityForm.owner.secondName') ownerSecondNameErrorHelp @enderror"
                class="input @error('legalEntityForm.owner.secondName') input-error border-red-500 focus:border-red-500 scroll-to-error @enderror peer"
                x-effect="$el.value = isOwnerChange ? (tempOwnerData.secondName ?? '') : owner.secondName ?? ''"
                @blur="if (isOwnerChange) { tempOwnerData.secondName = $event.target.value } else { owner.secondName = $event.target.value }"
            />

            @error('legalEntityForm.owner.secondName')
                <p id="ownerSecondNameErrorHelp" class="text-error">
                    {{ $message }}
                </p>
            @enderror

            <label for="ownerSecondName" class="label z-10">
                {{ __('forms.second_name') }}
            </label>
        </div>

        {{-- Owner Birth Date --}}
        <div class="form-group group">
            <svg class="svg-input" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor"
                 viewBox="0 0 20 20">
                <path
                    d="M20 4a2 2 0 0 0-2-2h-2V1a1 1 0 0 0-2 0v1h-3V1a1 1 0 0 0-2 0v1H6V1a1 1 0 0 0-2 0v1H2a2 2 0 0 0-2 2v2h20V4ZM0 18a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8H0v10Zm5-8h10a1 1 0 0 1 0 2H5a1 1 0 0 1 0-2Z"
                />
            </svg>

            <input
                required
                type="text"
                placeholder=" "
                id="ownerBirthDate"
                datepicker-format="{{ frontendDateFormat() }}"
                x-model="owner.birthDate"
                aria-describedby="@error('legalEntityForm.owner.birthDate') ownerBirthDateErrorHelp @enderror"
                class="input datepicker-input @error('legalEntityForm.owner.birthDate') input-error border-red-500 focus:border-red-500 scroll-to-error @enderror peer"
                :class="isDisabled && !isOwnerChange ? 'text-gray-400 border-gray-200 dark:text-gray-500' : 'text-gray-900 border-gray-300'"
                :disabled="isDisabled && !isOwnerChange"
                x-effect="$el.value = isOwnerChange ? (tempOwnerData.birthDate ?? '') : owner.birthDate ?? ''"
                @blur="if (isOwnerChange) { tempOwnerData.birthDate = $event.target.value } else { owner.birthDate = $event.target.value }"
            />

            @error('legalEntityForm.owner.birthDate')
                <p id="ownerBirthDateErrorHelp" class="text-error">
                    {{ $message }}
                </p>
            @enderror

            <label for="ownerBirthDate" class="label z-10">
                {{__('forms.birth_date')}}
            </label>
        </div>

        {{-- Owner Gender --}}
        <div class="form-group group">
            <div for="ownerGender" class='label z-10'>
                {{ __('forms.gender') }} *
            </div>

            <ul
                aria-describedby="@error('legalEntityForm.owner.gender') ownerGenderErrorHelp @enderror"
                class="steps-owner_gender_list @error('legalEntityForm.owner.gender') text-error border-red-500 focus:border-red-500 scroll-to-error @enderror"
            >
                @isset($dictionaries['GENDER'])
                    @foreach($dictionaries['GENDER'] as $k => $gender)
                        <li class="w-content me-3">
                            <div class="flex items-center">
                                <input
                                    type="radio"
                                    name="gender"
                                    value="{{ $k }}"
                                    class="steps-owner_radio"
                                    id="owner_gender_{{ $k }}"
                                    x-model="owner.gender"
                                    x-effect="$el.checked = isOwnerChange ? ($el.value === (tempOwnerData.gender ?? '')) : ($el.value === owner.gender)"
                                    @change="if (isOwnerChange) { tempOwnerData.gender = $event.target.value } else { owner.gender = $event.target.value }"
                                >
                                <label
                                    name="label"
                                    for="owner_gender_{{ $k }}"
                                    class="steps-owner_radio_label"
                                >
                                    {{ $gender }}
                                </label>
                            </div>
                        </li>
                    @endforeach
                @endisset
            </ul>

            @error('legalEntityForm.owner.gender')
                <p id="ownerGenderErrorHelp" class="text-error">
                    {{ $message }}
                </p>
            @enderror
        </div>

        {{-- Owner Position --}}
        <div class="form-group group">
            <select
                required
                id="ownerPosition"
                x-model="owner.position"
                aria-describedby="@error('legalEntityForm.owner.position') ownerPositionErrorHelp @enderror"
                class="input-select @error('legalEntityForm.owner.position') input-error border-red-500 focus:border-red-500 scroll-to-error @enderror peer"
                :class="isDisabled ? 'text-gray-400 border-gray-200 dark:text-gray-500' : 'text-gray-900 border-gray-300'"
                :disabled="isDisabled && !isOwnerChange"
                x-effect="$el.value = isOwnerChange ? (tempOwnerData.position || '_placeholder_') : (owner.position || '_placeholder_')"
                @blur="if (isOwnerChange) { tempOwnerData.position = $event.target.value } else { owner.position = $event.target.value }"
            >
                <option value="_placeholder_" selected hidden>-- {{ __('forms.select_position') }} --</option>

                @foreach($dictionaries['POSITION'] as $k => $position)
                    <option value="{{ $k }}">{{ $position }}</option>
                @endforeach
            </select>

            @error('legalEntityForm.owner.position')
                <p id="ownerPositionErrorHelp" class="text-error">
                    {{ $message }}
                </p>
            @enderror

            <label for="ownerPosition" class="label z-10">
                {{ __('forms.owner_position') }}
            </label>
        </div>
    </div>

    {{-- Owner IPN --}}
    <div
        class='form-row-3'
    >
        <div class="form-group group relative z-0">
            <input
                required
                id="taxId"
                type="text"
                name="taxId"
                maxlength="10"
                placeholder=" "
                x-model="owner.taxId"
                aria-describedby="@error('legalEntityForm.owner.taxId') ownerTaxIdErrorHelp @enderror"
                class="input @error('legalEntityForm.owner.taxId') input-error border-red-500 focus:border-red-500 scroll-to-error @enderror peer"
                :class="{ 'border-gray-200 dark:border-gray-700': owner.noTaxId }"
                :disabled="owner.noTaxId || (isDisabled && !isOwnerChange)"
                x-ref="taxIdInput"
                x-effect="$el.value = isOwnerChange ? (owner.noTaxId ? '' : (owner.taxId ?? '')) : (owner.noTaxId ? '' : (owner.taxId ?? ''))"
                @blur="if (isOwnerChange) { tempOwnerData.taxId = $event.target.value } else { owner.taxId = $event.target.value }"
            />

            @error('legalEntityForm.owner.taxId')
                <p id="ownerTaxIdErrorHelp" class="text-error">
                    {{ $message }}
                </p>
            @enderror

            <label
                for="taxId"
                class="label z-10"
                :class="{ 'text-gray-200 dark:text-gray-700': owner.noTaxId }"
                x-text="'{{ __('forms.number') . ' ' . __('forms.ipn') . ' / ' . __('forms.rnokpp') }}'"
            ></label>
        </div>

        <div class="form-group group">
            <div class="mt-3">
                <input
                    type="checkbox"
                    id="noTaxId"
                    class="default-checkbox text-blue-500 focus:ring-blue-300"
                    x-model="owner.noTaxId"
                    :checked="owner.noTaxId"
                    :disabled="isDisabled && !isOwnerChange"
                    x-effect="isOwnerChange ? ($el.checked = (tempOwnerData.noTaxId ?? false)) : ($el.checked = owner.noTaxId)"
                    @change="if (isOwnerChange) { tempOwnerData.noTaxId = $event.target.checked } else { owner.noTaxId = $event.target.checked }; updateTaxIdInput()"
                >

                <label for="noTaxId" class="ms-2 text-sm font-medium text-gray-500 dark:text-gray-300 @error('legalEntityForm.owner.noTaxId') scroll-to-error @enderror">
                    {{ __('forms.no_tax_id') }}
                </label>

                @error('legalEntityForm.owner.noTaxId')
                    <p id="ownerNoTaxIdErrorHelp" class="text-error">
                        {{ $message }}
                    </p>
                @enderror
            </div>
        </div>
    </div>

    <h3 class="font-bold text-sm text-gray-600 mb-6">{{ __('forms.phones_owner') }} *</h3>

    {{-- Email --}}
    <div class='form-row-3'>
        <div class="form-group group">
            <svg class="svg-input w-5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor"
                 viewBox="0 0 24 24">
                <path
                    d="M2.038 5.61A2.01 2.01 0 0 0 2 6v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6c0-.12-.01-.238-.03-.352l-.866.65-7.89 6.032a2 2 0 0 1-2.429 0L2.884 6.288l-.846-.677Z" />
                <path
                    d="M20.677 4.117A1.996 1.996 0 0 0 20 4H4c-.225 0-.44.037-.642.105l.758.607L12 10.742 19.9 4.7l.777-.583Z" />
            </svg>

            <input
                required
                type="text"
                placeholder=" "
                id="ownerEmail"
                x-model="owner.email"
                aria-describedby="@error('legalEntityForm.owner.email') ownerEmailErrorHelp @enderror"
                class="input @error('legalEntityForm.owner.email') input-error border-red-500 focus:border-red-500 scroll-to-error @enderror peer"
                x-effect="$el.value = isOwnerChange ? (tempOwnerData.email ?? '') : owner.email ?? ''"
                @blur="if (isOwnerChange) { tempOwnerData.email = $event.target.value } else { owner.email = $event.target.value }"
            />

            @error('legalEntityForm.owner.email')
                <p id="ownerEmailErrorHelp" class="text-error">
                    {{ $message }}
                </p>
            @enderror

            <label for="ownerEmail" class="label z-10">
                {{ __('forms.email') }}
            </label>
        </div>
    </div>

    {{-- Owner Phones --}}
    <div
        class="space-y-2"
        x-id="['phone']"
    >
        <template x-for="(phone, index) in owner.phones" :key="getKey(phone)">
            <div
                class="form-row-3"
                x-data="{errors: [] }"
                x-init="errors = @js($errors->getMessages())"
                :class="{ 'mb-2': index == owner.phones.length - 1 }"
            >
                {{-- Phone Type Select --}}
                <div class="form-group">
                    <select
                        required
                        x-model="phone.type"
                        class="input-select"
                        :class="{ 'input-error scroll-to-error': errors[`legalEntityForm.owner.phones.${index}.type`] }"
                        :id="$id('phone', '_type_' + index)"
                    >
                        <option value="_placeholder_" selected hidden>-- {{ __('forms.type_mobile') }} --</option>
                        <template x-for="(phoneType, key) in $wire.dictionaries.PHONE_TYPE" :key="key">
                            <option
                                x-text="phoneType"
                                :value="key"
                                :disabled="owner.phones.some((p) => p.type === key)"
                                :selected="phone.type === key"
                            ></option>
                        </template>
                    </select>

                    <template x-if="errors[`legalEntityForm.owner.phones.${index}.type`]">
                        <p class="text-error" x-text="errors[`legalEntityForm.owner.phones.${index}.type`]"></p>
                    </template>

                    <label
                        :for="$id('phone', '_type_' + index)"
                        class="label"
                    >
                        {{ __('forms.phone_type') }}
                    </label>
                </div>

                {{-- Phone Number Input --}}
                <div class="form-group phone-wrapper">
                    <input
                        required
                        type="tel"
                        placeholder=" "
                        class="peer input pl-10 with-leading-icon text-gray-500"
                        x-model="phone.number"
                        x-mask="+380999999999"
                        :id="$id('phone', '_number' + index)"
                        :class="{ 'input-error border-red-500 scroll-to-error': errors[`legalEntityForm.owner.phones.${index}.number`] }"
                    />

                    <template x-if="errors[`legalEntityForm.owner.phones.${index}.number`]">
                        <p class="text-error" x-text="errors[`legalEntityForm.owner.phones.${index}.number`]"></p>
                    </template>

                    <label
                        :for="$id('phone', '_number' + index)"
                        class="wrapped-label"
                    >
                        {{ __('forms.phone') }}
                    </label>
                </div>

                <!-- Action Phone Buttons -->
                <div
                    x-cloak
                    x-show="!@json($isDetails ?? false)"
                    class="flex items-center space-x-4 justify-start"
                >
                    <!-- Add phone -->
                    <template x-if="owner.phones.length > 1">
                        <button
                            type="button"
                            @click.prevent="owner.phones.splice(index, 1)"
                            class="item-remove text-red-600 hover:text-red-800 justify-self-start"
                        >
                            <span>{{__('forms.remove_phone')}}</span>
                        </button>
                    </template>

                    <!-- Remove Phone -->
                    <template x-if="index === owner.phones.length - 1 && owner.phones.length < 2">
                        <button type="button" @click.prevent="owner.phones.push({ type: '', number: '' })" class="item-add">
                            <span>{{__('forms.add_phone')}}</span>
                        </button>
                    </template>
                </div>
            </div>
        </template>
    </div>

    <h3 class="font-bold text-sm text-gray-600 mb-10">{{ __('forms.documents_owner') }} *</h3>

    {{-- OWNER DOCUMENTS --}}
    <div
        class="space-y-2"
        x-id="['doc']"
    >
        <template x-for="(doc, index) in owner.documents" :key="getKey(doc)">
            <div
                x-data="{errors: [] }"
                x-init="errors = @js($errors->getMessages())"
                class="mb-6"
                :class="{ 'mb-2': index == owner.documents.length - 1 }"
            >
                <template x-if="index > 0">
                    <hr class="border-gray-200 dark:border-gray-700 mb-6">
                </template>

                <div class='form-row-3'>
                    {{-- Owner Document Type --}}
                    <div class="form-group group relative z-0">
                        <select
                            required
                            x-model="doc.type"
                            class="input-select peer"
                            :class="{ 'input-error scroll-to-error': errors[`legalEntityForm.owner.documents.${index}.type`] }"
                            :id="$id('doc', '_type_' + index)"
                        >
                            <option value="_placeholder_" selected hidden>-- {{ __('Обрати тип') }} --</option>

                            <template x-for="(docType, key) in $wire.dictionaries.DOCUMENT_TYPE" :key="key">
                                <option
                                    x-text="docType"
                                    :value="key"
                                    :disabled="owner.documents.some((d, i) => i !== index && d.type === key)"
                                    :selected="doc.type === key"
                                ></option>
                            </template>
                        </select>

                        <template x-if="errors[`legalEntityForm.owner.documents.${index}.type`]">
                            <p class="text-error" x-text="errors[`legalEntityForm.owner.documents.${index}.type`]"></p>
                        </template>

                        <label
                            :for="$id('doc', '_type_' + index)"
                            class="label z-10 pointer-events-none"
                        >
                            {{ __('forms.document_type') }}
                        </label>
                    </div>

                    {{-- Owner Document Number --}}
                    <div class="form-group group relative z-0">
                        <input
                            required
                            type="text"
                            placeholder=" "
                            x-model="doc.number"
                            class="peer input"
                            :id="$id('doc', '_number' + index)"
                            :class="{ 'input-error border-red-500 scroll-to-error': errors[`legalEntityForm.owner.documents.${index}.number`] }"
                        />

                        <template x-if="errors[`legalEntityForm.owner.documents.${index}.number`]">
                            <p class="text-error" x-text="errors[`legalEntityForm.owner.documents.${index}.number`]"></p>
                        </template>

                        <label
                            :for="$id('doc', '_number' + index)"
                            class="label z-10 pointer-events-none"
                        >
                            {{ __('forms.document_number') }}
                        </label>
                    </div>

                    {{-- Remove Documents Buttons --}}
                    <div
                        x-cloak
                        x-show="!@json($isDetails ?? false)"
                        class="flex items-center space-x-4 justify-start"
                    >
                        <template x-if="owner.documents.length > 1">
                            <button
                                type="button"
                                @click.prevent="owner.documents.splice(index, 1)"
                                class="item-remove text-red-600 hover:text-red-800 justify-self-start"
                            >
                                <span>{{ __('forms.remove_document') }}</span>
                            </button>
                        </template>
                    </div>
                </div>

                <div class='form-row-3'>
                    {{-- Owner Document Issued By --}}
                    <div class="form-group group relative z-0">
                        <input
                            type="text"
                            placeholder=" "
                            class="input peer"
                            x-model="doc.issuedBy"
                            :id="$id('doc', '_issuedBy' + index)"
                            :class="{ 'input-error border-red-500 scroll-to-error': errors[`legalEntityForm.owner.documents.${index}.issuedBy`] }"
                        />
                        <template x-if="errors[`legalEntityForm.owner.documents.${index}.issuedBy`]">
                            <p class="text-error" x-text="errors[`legalEntityForm.owner.documents.${index}.issuedBy`]"></p>
                        </template>

                        <label
                            :for="$id('doc', '_issuedBy' + index)"
                            class="label z-10 pointer-events-none"
                        >
                            {{__('forms.issued_by')}}
                        </label>
                    </div>

                    {{-- Owner Document Issued At --}}
                    <div class="form-group group relative z-0">
                        <svg class="svg-input pointer-events-none" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor"
                            viewBox="0 0 20 20">
                            <path
                                d="M20 4a2 2 0 0 0-2-2h-2V1a1 1 0 0 0-2 0v1h-3V1a1 1 0 0 0-2 0v1H6V1a1 1 0 0 0-2 0v1H2a2 2 0 0 0-2 2v2h20V4ZM0 18a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8H0v10Zm5-8h10a1 1 0 0 1 0 2H5a1 1 0 0 1 0-2Z" />
                        </svg>

                        <input
                            type="text"
                            placeholder=" "
                            x-model="doc.issuedAt"
                            datepicker-format="{{ frontendDateFormat() }}"
                            class="input datepicker-input peer"
                            :id="$id('doc', '_issuedAt' + index)"
                            :class="{ 'input-error border-red-500 scroll-to-error': errors[`legalEntityForm.owner.documents.${index}.issuedAt`] }"
                        />

                        <template x-if="errors[`legalEntityForm.owner.documents.${index}.issuedAt`]">
                            <p class="text-error" x-text="errors[`legalEntityForm.owner.documents.${index}.issuedAt`]"></p>
                        </template>

                        <label
                            :for="$id('doc', '_issuedAt' + index)"
                            class="label z-10 pointer-events-none"
                        >
                            {{ __('forms.document_issued_at') }}
                        </label>
                    </div>
                </div>
            </div>
        </template>

        {{-- Add Document --}}
        <div
            x-cloak
            x-show="!@json($isDetails ?? false)"
            class="flex items-center space-x-4 justify-start"
        >
            <button
                x-cloak
                x-show ="owner.documents.length < 5"
                type="button"
                @click.prevent="owner.documents.push({ ...defaultDoc })"
                class="item-add"
            >
                <span>{{__('forms.add_document')}}</span>
            </button>
        </div>
    </div>
</fieldset>
