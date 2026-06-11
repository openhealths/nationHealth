@use('App\Enums\License\Type')
@use('Carbon\CarbonImmutable')

<section class="section-form">
    <div class="flex items-center justify-between gap-4 flex-wrap">
        <x-header-navigation class="breadcrumb-form flex-1 min-w-0">
            <x-slot name="title">
                {{ __('licenses.details') }}
            </x-slot>
        </x-header-navigation>
    </div>

    <fieldset class="fieldset shift-content">
        <legend class="legend">
            {{ __('licenses.details') }}
        </legend>

        <div class="form-row-2">
            <div class="form-group group">
                <label for="isPrimary" class="label">
                    {{ __('licenses.kind') }}
                </label>
                <input value="{{ $license->isPrimary ? __('licenses.primary') : __('licenses.not_primary') }}"
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
                <div class="input !h-auto min-h-10.5 py-2.5 wrap-break-word whitespace-normal text-sm">
                    {{ $license->type->label() }}
                </div>
            </div>

            <div class="form-group group">
                <label for="orderNo" class="label">
                    {{ __('licenses.order_no') }}
                </label>
                <input value="{{ $license->orderNo }}"
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
                <input value="{{ $license->issuedBy }}"
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
                <input value="{{ $license->whatLicensed }}"
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
                <input value="{{ $license->licenseNumber }}"
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
                <input value="{{ CarbonImmutable::parse($license->issuedDate)->format(config('app.date_format')) }}"
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
                <input value="{{ CarbonImmutable::parse($license->activeFromDate)->format(config('app.date_format')) }}"
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
                <input value="{{ CarbonImmutable::parse($license->expiryDate)->format(config('app.date_format')) }}"
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

        <a href="{{ url()->previous() }}" type="submit" class="button-minor">
            {{ __('forms.back') }}
        </a>
    </fieldset>
</section>
