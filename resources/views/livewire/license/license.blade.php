<div class="form shift-content"
     x-data="{
          licenseType: $wire.entangle('form.type'),
          licenseTypes: @js($licenseTypes)
      }"
>
    <div class="form-row-2">
        <div class="form-group">
            <input type="text"
                   name="licenseType"
                   id="licenseType"
                   class="peer input dark:text-gray-400"
                   value="{{ __('licenses.not_primary') }}"
                   placeholder=" "
                   disabled
            />

            <label for="licenseType" class="label">{{ __('licenses.kind') }}</label>
        </div>

        <div class="form-group">
            <input wire:model="form.orderNo"
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

    <div class="form-row"
         x-data="{
             open: false,
             selected: licenseTypes[licenseType],
             choose(key, label) {
                 this.selected = label;
                 licenseType = key;
                 this.open = false;
             }
         }"
         @click.away="open = false"
    >
        <div class="relative w-full">
            <div class="input-select peer cursor-pointer whitespace-normal break-words min-h-[48px] px-3 py-2 pr-10"
                 @click="open = !open"
                 :class="{ 'ring-1 ring-blue-500 border-blue-500': open }"
            >
                <span x-text="selected || 'Оберіть тип ліцензії'"></span>
                <span
                    class="absolute right-3 top-1/2 w-2 h-2 border-r-2 border-b-2 border-gray-500 dark:border-gray-400 transform -translate-y-1/2 rotate-45 pointer-events-none"></span>
            </div>

            <ul x-show="open" x-transition x-cloak class="dropdown-panel w-full max-h-60 overflow-auto z-10">
                @foreach ($licenseTypes ?? [] as $key => $label)
                    <li>
                        <button type="button"
                                x-text="'{{ $label }}'"
                                @click="choose('{{ $key }}', '{{ $label }}')"
                            @class([
                                'text-left text-sm whitespace-normal break-words px-3 py-2 w-full text-start',
                                'rounded-t-md' => $loop->first,
                                'rounded-b-md' => $loop->last,
                            ])>
                        </button>
                    </li>
                @endforeach
            </ul>

            <label class="label" for="licenseType">{{ __('licenses.type.label') }}</label>
            <input type="hidden" name="licenseType" :value="selected">

            @error('form.type')
            <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="form-row-2">
        <div class="form-group">
            <input wire:model="form.issuedBy"
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
            <input wire:model="form.whatLicensed"
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
            <input wire:model="form.licenseNumber"
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

        <div class="form-group datepicker-wrapper relative w-full">
            <input wire:model="form.issuedDate"
                   type="text"
                   name="dateOfLicenseIssuance"
                   id="dateOfLicenseIssuance"
                   class="peer input pl-10 appearance-none datepicker-input"
                   placeholder=" "
                   required
                   datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
            />
            <label for="dateOfLicenseIssuance" class="wrapped-label">{{ __('licenses.issued_date') }}</label>

            @error('form.issuedDate')
            <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="form-row-2">
        <div class="form-group datepicker-wrapper relative w-full">
            <input wire:model="form.activeFromDate"
                   type="text"
                   name="activeFromDate"
                   id="activeFromDate"
                   class="peer input pl-10 appearance-none datepicker-input"
                   placeholder=" "
                   required
                   datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
            />
            <label for="activeFromDate" class="wrapped-label">{{ __('licenses.active_from_date') }}</label>

            @error('form.activeFromDate')
            <p class="text-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="form-group datepicker-wrapper relative w-full">
            <input wire:model="form.expiryDate"
                   type="text"
                   name="expiryDate"
                   id="expiryDate"
                   class="peer input pl-10 appearance-none datepicker-input"
                   placeholder=" "
                   datepicker-min-date="{{ now()->format(config('app.date_format')) }}"
            />
            <label for="expiryDate" class="wrapped-label">{{ __('licenses.expiry_date') }}</label>

            @error('form.expiryDate')
            <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    @yield('action-buttons')

    <livewire:components.x-message :key="time()" />
    <x-forms.loading />
</div>
