<div class="form shift-content">
    <div class="form-row-2">
        <div class="form-group">
            <input
                type="text"
                name="isPrimary"
                id="isPrimary"
                class="peer input dark:text-gray-400"
                value="{{ __('licenses.not_primary') }}"
                placeholder=" "
                disabled
            />

            <label for="isPrimary" class="label">{{ __('licenses.kind') }}</label>
        </div>

        <div class="form-group">
            <input
                wire:model="form.orderNo"
                type="text"
                name="orderNumber"
                id="orderNumber"
                class="peer input"
                placeholder=" "
                required
            />

            <label for="orderNumber" class="label">{{ __('licenses.order_no') }}</label>

            @error('form.orderNo')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    @if ($uuid)
        <div class="form-row">
            <div
                class="form-group"
                x-data="{ licenseType: $wire.entangle('form.type'), licenseTypes: @js($licenseTypes) }"
            >
                <div
                    id="licenseTypeValue"
                    role="textbox"
                    aria-readonly="true"
                    aria-labelledby="licenseTypeLabel"
                    class="input h-auto min-h-10.5 whitespace-normal wrap-break-word dark:text-gray-400"
                    x-text="licenseTypes[licenseType]"
                ></div>

                <span id="licenseTypeLabel" class="label">{{ __('licenses.type.label') }}</span>
            </div>
        </div>
    @else
        <div class="form-row">
            <x-forms.listbox
                :options="$licenseTypes"
                bind="form.type"
                :label="__('licenses.type.label')"
                :placeholder="__('licenses.type.placeholder')"
                :is-required="true"
            />
        </div>
    @endif

    <div class="form-row-2">
        <div class="form-group">
            <input
                wire:model="form.issuedBy"
                type="text"
                name="issuedBy"
                id="issuedBy"
                class="peer input"
                placeholder=" "
                required
            />
            <label for="issuedBy" class="label">{{ __('licenses.issued_by') }}</label>

            @error('form.issuedBy')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="form-group">
            <input
                wire:model="form.whatLicensed"
                type="text"
                name="whatLicensed"
                id="whatLicensed"
                class="peer input"
                placeholder=" "
                required
            />
            <label for="whatLicensed" class="label">{{ __('licenses.what_licensed') }}</label>

            @error('form.whatLicensed')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="form-row-2">
        <div class="form-group">
            <input
                wire:model="form.licenseNumber"
                type="text"
                name="licenseNumber"
                id="licenseNumber"
                class="peer input"
                placeholder=" "
            />
            <label for="licenseNumber" class="label">{{ __('licenses.number') }}</label>

            @error('form.licenseNumber')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="form-group">
            <div class="datepicker-wrapper">
                <input
                    wire:model="form.issuedDate"
                    type="text"
                    name="dateOfLicenseIssuance"
                    id="dateOfLicenseIssuance"
                    class="datepicker-input with-leading-icon input peer @error('form.issuedDate') input-error @enderror"
                    placeholder=" "
                    required
                />
                <label for="dateOfLicenseIssuance" class="wrapped-label">{{ __('licenses.issued_date') }}</label>
            </div>

            @error('form.issuedDate')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="form-row-2">
        <div class="form-group">
            <div class="datepicker-wrapper">
                <input
                    wire:model="form.activeFromDate"
                    type="text"
                    name="activeFromDate"
                    id="activeFromDate"
                    class="datepicker-input with-leading-icon input peer @error('form.activeFromDate') input-error @enderror"
                    placeholder=" "
                    required
                />
                <label for="activeFromDate" class="wrapped-label">{{ __('licenses.active_from_date') }}</label>
            </div>

            @error('form.activeFromDate')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="form-group">
            <div class="datepicker-wrapper">
                <input
                    wire:model="form.expiryDate"
                    type="text"
                    name="expiryDate"
                    id="expiryDate"
                    class="datepicker-input with-leading-icon input peer @error('form.expiryDate') input-error @enderror"
                    placeholder=" "
                    datepicker-min-date="{{ now()->format(config('app.date_format')) }}"
                />
                <label for="expiryDate" class="wrapped-label">{{ __('licenses.expiry_date') }}</label>
            </div>

            @error('form.expiryDate')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    @yield('action-buttons')

    <livewire:components.x-message :key="time()" />
    <x-forms.loading />
</div>
