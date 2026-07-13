<fieldset
    class="p-4 sm:p-8 sm:pb-10 mb-16 mt-6 border border-gray-200 rounded-lg shadow dark:bg-gray-800 dark:border-gray-700 max-w-[1280px]"
    xmlns="http://www.w3.org/1999/html"
    x-data="{
        title: '{{ __('forms.licenses') }}',
        index: 6,
        isDisabled: false // let it be here... for now
    }"
    x-init="typeof addHeader !== 'undefined' && addHeader(title, index)"
    x-show="activeStep === index || isEdit"
    x-cloak
    :key="`step-${index}`"
>
    <template x-if="isEdit">
        <legend x-text="title" class="legend"></legend>
    </template>

    <div class='form-row-3'>
        <div class="form-group group">
            <select
                required
                id="licenseType"
                wire:model.defer="legalEntityForm.license.type"
                aria-describedby="@error('legalEntityForm.license.type') licenseTypeErrorHelp @enderror"
                class="input-select !cursor-default text-gray-400 border-gray-200 dark:text-gray-500 @error('legalEntityForm.license.type') input-error border-red-500 focus:border-red-500 @enderror peer"
                disabled
            >
                <option value="_placeholder_" selected hidden>-- {{ __('forms.select') }} --</option>

                @foreach($dictionaries['LICENSE_TYPE'] as $k => $license_type)
                    <option value="{{ $k }}" @selected($k == $this->getLicenseTypesByLegalEntityType($legalEntityForm->type))>
                        {{ $license_type }}
                    </option>
                @endforeach
            </select>

            @error('legalEntityForm.license.type')
                <p id="licenseTypeErrorHelp" class="text-error">
                    {{ $message }}
                </p>
            @enderror

            <label for="licenseType" class="label z-10">
                {{ __('licenses.type.label') }}
            </label>
        </div>

        <div class="form-group group">
            <input
                type="text"
                placeholder=" "
                id="licenseNumber"
                wire:model="legalEntityForm.license.licenseNumber"
                class="input  @error('legalEntityForm.license.licenseNumber') input-error border-red-500 focus:border-red-500 @enderror peer"
                aria-describedby="@error('legalEntityForm.license.licenseNumber') licenseNumberErrorHelp @enderror"
                :class="isDisabled ? 'text-gray-400 border-gray-200 dark:text-gray-500' : 'text-gray-900 border-gray-300'"
                :disabled="isDisabled"
            />

            @error('legalEntityForm.license.licenseNumber')
                <p id="licenseNumberErrorHelp" class="text-error">
                    {{ $message }}
                </p>
            @enderror

            <label for="licenseNumber" class="label z-10">
                {{ __('licenses.number') }}
            </label>
        </div>

        <div class="form-group group">
            <input
                required
                type="text"
                placeholder=" "
                id="licenseIssuedBy"
                wire:model="legalEntityForm.license.issuedBy"
                aria-describedby="@error('legalEntityForm.license.issuedBy') licenseIssuedByErrorHelp @enderror"
                class="input @error('legalEntityForm.license.issuedBy') input-error border-red-500 focus:border-red-500 @enderror peer"
                :class="isDisabled ? 'text-gray-400 border-gray-200 dark:text-gray-500' : 'text-gray-900 border-gray-300'"
                :disabled="isDisabled"
            />

            @error('legalEntityForm.license.issuedBy')
                <p id="licenseIssuedByErrorHelp" class="text-error">
                    {{ $message }}
                </p>
            @enderror

            <label for="licenseIssuedBy" class="label z-10">
                {{ __('forms.issued_by') }}
            </label>
        </div>

        <div class="form-group group">
            <svg class="svg-input" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 20 20">
                <path d="M20 4a2 2 0 0 0-2-2h-2V1a1 1 0 0 0-2 0v1h-3V1a1 1 0 0 0-2 0v1H6V1a1 1 0 0 0-2 0v1H2a2 2 0 0 0-2 2v2h20V4ZM0 18a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8H0v10Zm5-8h10a1 1 0 0 1 0 2H5a1 1 0 0 1 0-2Z"/>
            </svg>

            <input
                required
                type="text"
                placeholder=" "
                datepicker-format="{{ frontendDateFormat() }}"
                id="licenseIssuedDate"
                wire:model="legalEntityForm.license.issuedDate"
                aria-describedby="@error('legalEntityForm.license.issuedDate') licenseIssuedDateErrorHelp @enderror"
                class="input datepicker-input @error('legalEntityForm.license.issuedDate') input-error border-red-500 focus:border-red-500 @enderror peer"
                :class="isDisabled ? 'text-gray-400 border-gray-200 dark:text-gray-500' : 'text-gray-900 border-gray-300'"
                :disabled="isDisabled"
            />

            @error('legalEntityForm.license.issuedDate')
                <p id="licenseIssuedDateErrorHelp" class="text-error">
                    {{ $message }}
                </p>
            @enderror

            <label for="licenseIssuedDate" class="label z-10">
                {{ __('forms.document_issued_at') }}
            </label>
        </div>

        <div class="form-group group">
            <svg class="svg-input" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 20 20">
                <path d="M20 4a2 2 0 0 0-2-2h-2V1a1 1 0 0 0-2 0v1h-3V1a1 1 0 0 0-2 0v1H6V1a1 1 0 0 0-2 0v1H2a2 2 0 0 0-2 2v2h20V4ZM0 18a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8H0v10Zm5-8h10a1 1 0 0 1 0 2H5a1 1 0 0 1 0-2Z"/>
            </svg>

            <input
                required
                type="text"
                placeholder=" "
                datepicker-format="{{ frontendDateFormat() }}"
                id="licenseActiveFromDate"
                wire:model="legalEntityForm.license.activeFromDate"
                aria-describedby="@error('legalEntityForm.license.activeFromDate') licenseActiveFromDateErrorHelp @enderror"
                class="input datepicker-input @error('legalEntityForm.license.activeFromDate') input-error border-red-500 focus:border-red-500 @enderror peer"
                :class="isDisabled ? 'text-gray-400 border-gray-200 dark:text-gray-500' : 'text-gray-900 border-gray-300'"
                :disabled="isDisabled"
            />

            @error('legalEntityForm.license.activeFromDate')
                <p id="licenseActiveFromDateErrorHelp" class="text-error">
                    {{ $message }}
                </p>
            @enderror

            <label for="licenseActiveFromDate" class="label z-10">
                {{ __('licenses.active_from_date') }}
            </label>
        </div>

        <div class="form-group group">
            <svg class="svg-input" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 20 20">
                <path d="M20 4a2 2 0 0 0-2-2h-2V1a1 1 0 0 0-2 0v1h-3V1a1 1 0 0 0-2 0v1H6V1a1 1 0 0 0-2 0v1H2a2 2 0 0 0-2 2v2h20V4ZM0 18a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8H0v10Zm5-8h10a1 1 0 0 1 0 2H5a1 1 0 0 1 0-2Z"/>
            </svg>

            <input
                type="text"
                placeholder=" "
                datepicker-format="{{ frontendDateFormat() }}"
                id="licenseExpiryDate"
                wire:model="legalEntityForm.license.expiryDate"
                class="input @error('legalEntityForm.license.expiryDate') input-error border-red-500 focus:border-red-500 @enderror datepicker-input peer"
                aria-describedby="@error('legalEntityForm.license.expiryDate') licenseExpirationDateErrorHelp @enderror"
                :class="isDisabled ? 'text-gray-400 border-gray-200 dark:text-gray-500' : 'text-gray-900 border-gray-300'"
                :disabled="isDisabled"
            />

            @error('legalEntityForm.license.expiryDate')
                <p id="licenseExpirationDateErrorHelp" class="text-error">
                    {{ $message }}
                </p>
            @enderror

            <label for="licenseExpiryDate" class="label z-10">
                {{ __('forms.end_date') }}
            </label>
        </div>

        <div class="form-group group">
            <input
                required
                type="text"
                placeholder=" "
                id="licenseWhatLicensed"
                wire:model="legalEntityForm.license.whatLicensed"
                class="input @error('legalEntityForm.license.whatLicensed') input-error border-red-500 focus:border-red-500 @enderror peer"
                aria-describedby="@error('legalEntityForm.license.whatLicensed') licenseWhatLicensedErrorHelp @enderror"
                :class="isDisabled ? 'text-gray-400 border-gray-200 dark:text-gray-500' : 'text-gray-900 border-gray-300'"
                :disabled="isDisabled"
            />

            @error('legalEntityForm.license.whatLicensed')
                <p id="licenseWhatLicensedErrorHelp" class="text-error">
                    {{ $message }}
                </p>
            @enderror

            <label for="licenseWhatLicensed" class="label z-10">
                {{ __('licenses.what_licensed') }}
            </label>
        </div>

        <div class="form-group group">
            <input
                required
                type="text"
                placeholder=" "
                id="licenseOrderNumber"
                wire:model="legalEntityForm.license.orderNo"
                aria-describedby="@error('legalEntityForm.license.orderNo') licenseOrderNumberErrorHelp @enderror"
                class="input @error('legalEntityForm.license.orderNo') input-error border-red-500 focus:border-red-500 @enderror peer"
                :class="isDisabled ? 'text-gray-400 border-gray-200 dark:text-gray-500' : 'text-gray-900 border-gray-300'"
                :disabled="isDisabled"
            />

            @error('legalEntityForm.license.orderNo')
                <p id="licenseOrderNumberErrorHelp" class="text-error">
                    {{ $message }}
                </p>
            @enderror

            <label for="licenseOrderNumber" class="label z-10">
                {{ __('licenses.order_no') }}
            </label>
        </div>
    </div>
</fieldset>
