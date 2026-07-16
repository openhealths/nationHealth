<div x-show="showFilter"
     wire:key="{{ time() }}"
     x-data="{
         focusNext(el) {
             let container = el.closest('.breadcrumb-form, [x-data]');
             if (!container) return;
             let elements = Array.from(container.querySelectorAll('input:not([readonly]):not([type=hidden]):not([type=checkbox]), button.button-primary')).filter(element => element.offsetWidth > 0 && element.offsetHeight > 0);
             let index = elements.indexOf(el);
             if (index > -1 && elements[index + 1]) {
                 elements[index + 1].focus();
             }
         }
     }"
>
    <div class="form-row-4">
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
            <input
                wire:model="form.firstName"
                type="text"
                name="filterFirstName"
                id="filterFirstName"
                class="input peer @error('form.firstName') input-error @enderror"
                placeholder=" "
                required
                autocomplete="off"
                x-on:keydown.enter.prevent="focusNext($el)"
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
                x-on:keydown.enter.prevent="focusNext($el)"
            />
            <label for="filterLastName" class="label">
                {{ __('forms.last_name') }}
            </label>

            <div class="flex items-center gap-2 mt-2">
                <label for="filterNoLastName" class="default-label">
                    {{ __('patients.no_last_name') }}
                </label>
                <input
                    wire:model="form.noLastName"
                    x-on:change="if ($event.target.checked) $wire.set('form.lastName', '', false)"
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
                    x-on:keydown.enter.prevent="focusNext($el)"
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
                        x-on:keydown.enter.prevent="focusNext($el)"
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
                        x-on:keydown.enter.prevent="focusNext($el)"
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
                        x-on:keydown.enter.prevent="focusNext($el)"
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
                        x-on:keydown.enter.prevent="focusNext($el)"
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
                    <div class="form-group group" x-data="{ open: false }">
                        <label for="filterDropdown" class="label"></label>
                        <div class="relative">
                            <input
                                type="text"
                                id="filterDropdown"
                                class="input peer w-full cursor-pointer text-gray-500 dark:text-gray-400"
                                placeholder="{{ __('forms.select_filter') }}"
                                @click="open = !open"
                                :value="
                                    $wire.activeFilter === 'all' ? '{{ __('forms.all') }}' :
                                    $wire.activeFilter === 'ehealth' ? '{{ __('patients.source.ehealth') }}' :
                                    $wire.activeFilter === 'local' ? '{{ __('patients.source.local') }}' :
                                    $wire.activeFilter === 'request' ? '{{ __('patients.applications') }}'
                                    : ''
                                "
                                readonly
                            />
                            <div
                                x-show="open"
                                @click.away="open = false"
                                x-transition:enter="transition ease-out duration-100"
                                x-transition:enter-start="transform opacity-0 scale-95"
                                x-transition:enter-end="transform opacity-100 scale-100"
                                x-transition:leave="transition ease-in duration-75"
                                x-transition:leave-start="transform opacity-100 scale-100"
                                x-transition:leave-end="transform opacity-0 scale-95"
                                class="absolute z-10 mt-2 w-full bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-md shadow-lg"
                            >
                                @php
                                    $filters = [
                                        'all' => __('forms.all'),
                                        'local' => __('patients.source.local'),
                                        'ehealth' => __('patients.source.ehealth'),
                                        'request' => __('patients.applications')
                                    ];
                                @endphp
                                <ul class="py-2 px-3 space-y-2 text-sm text-gray-700 dark:text-gray-200">
                                    @foreach ($filters as $value => $label)
                                        <li>
                                            <label
                                                class="flex items-center space-x-2 cursor-pointer"
                                                @click="open = false"
                                            >
                                                <input
                                                    type="radio"
                                                    value="{{ $value }}"
                                                    wire:model="activeFilter"
                                                    class="sr-only"
                                                />

                                                <span class="{{ $activeFilter === $value ? 'text-blue-600' : '' }}">
                                                    {{ $label }}
                                                </span>
                                            </label>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
