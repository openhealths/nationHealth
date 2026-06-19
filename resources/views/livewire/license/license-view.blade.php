@use('App\Enums\License\Type')
@use('Carbon\CarbonImmutable')

<section class="section-form">
    <div class="flex items-center justify-between gap-4 flex-wrap">
        <x-header-navigation class="breadcrumb-form flex-1 min-w-0">
            <x-slot name="title">{{ __('licenses.details') }}</x-slot>
        </x-header-navigation>
    </div>

    <div class="shift-content pl-3.5 mt-8">
        <fieldset class="fieldset">

            <div class="form-row-2">
                <div class="form-group group">
                    <label for="isPrimary" class="label">
                        {{ __('licenses.kind') }}
                    </label>
                    <input
                        value="{{ $license->isPrimary ? __('licenses.primary') : __('licenses.not_primary') }}"
                        type="text"
                        name="isPrimary"
                        id="isPrimary"
                        class="input peer"
                        placeholder=" "
                        disabled
                        autocomplete="off"
                    />
                </div>
            </div>

            <div class="form-row-2 items-end">
                <div class="form-group group">
                    <span class="label">
                        {{ __('licenses.type.label') }}
                    </span>
                    <div class="input h-auto min-h-10.5 py-2.5 wrap-break-word whitespace-normal text-sm">
                        {{ $license->type->label() }}
                    </div>
                </div>

                <div class="form-group group">
                    <label for="orderNo" class="label">
                        {{ __('licenses.order_no') }}
                    </label>
                    <input
                        value="{{ $license->orderNo }}"
                        type="text"
                        name="orderNo"
                        id="orderNo"
                        class="input peer"
                        placeholder=" "
                        disabled
                        autocomplete="off"
                    />
                </div>
            </div>

            <div class="form-row-2 items-end">
                <div class="form-group group">
                    <label for="issuedBy" class="label">
                        {{ __('licenses.issued_by') }}
                    </label>
                    <input
                        value="{{ $license->issuedBy }}"
                        type="text"
                        name="issuedBy"
                        id="issuedBy"
                        class="input peer"
                        placeholder=" "
                        disabled
                        autocomplete="off"
                    />
                </div>

                <div class="form-group group">
                    <label for="whatLicensed" class="label">
                        {{ __('licenses.what_licensed') }}
                    </label>
                    <input
                        value="{{ $license->whatLicensed }}"
                        type="text"
                        name="whatLicensed"
                        id="whatLicensed"
                        class="input peer"
                        placeholder=" "
                        disabled
                        autocomplete="off"
                    />
                </div>
            </div>

            <div class="form-row-2">
                <div class="form-group group">
                    <label for="number" class="label">
                        {{ __('licenses.number') }}
                    </label>
                    <input
                        value="{{ $license->licenseNumber }}"
                        type="text"
                        name="number"
                        id="number"
                        class="input peer"
                        placeholder=" "
                        disabled
                        autocomplete="off"
                    />
                </div>

                <div class="form-group group">
                    <label for="issuedDate" class="label">
                        {{ __('licenses.issued_date') }}
                    </label>
                    <input
                        value="{{ $license->issuedDate }}"
                        type="text"
                        name="issuedDate"
                        id="issuedDate"
                        class="input peer"
                        placeholder=" "
                        disabled
                        autocomplete="off"
                    />
                </div>
            </div>

            <div class="form-row-2">
                <div class="form-group group">
                    <label for="activeFromDate" class="label">
                        {{ __('licenses.active_from_date') }}
                    </label>
                    <input
                        value="{{ $license->activeFromDate }}"
                        type="text"
                        name="activeFromDate"
                        id="activeFromDate"
                        class="input peer"
                        placeholder=" "
                        disabled
                        autocomplete="off"
                    />
                </div>

                <div class="form-group group">
                    <label for="expiryDate" class="label">
                        {{ __('licenses.expiry_date') }}
                    </label>
                    <input value="{{ $license->expiryDate }}"
                           type="text"
                           name="expiryDate"
                           id="expiryDate"
                           class="input peer"
                           placeholder=" "
                           disabled
                           autocomplete="off"
                    />
                </div>
            </div>

            <a
                href="{{ route('license.index', ['legalEntity' => legalEntity()]) }}"
                type="submit"
                class="button-minor"
            >
                {{ __('forms.back') }}
            </a>
        </fieldset>
    </div>
</section>
