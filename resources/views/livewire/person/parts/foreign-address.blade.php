{{-- Address of a country other than Ukraine: the eHealth address registry covers Ukrainian addresses only,
     so every part of such an address is typed in --}}
<div class="form-row-3 mt-8">
    <div class="form-group group">
        <input
            wire:model="addresses.{{ $index }}.area"
            type="text"
            id="foreignAddressArea{{ $index }}"
            class="input peer @error('form.person.addresses.' . $index . '.area') input-error @enderror"
            placeholder=" "
            autocomplete="off"
        />
        <label for="foreignAddressArea{{ $index }}" class="label"> {{ __('forms.area') }} </label>
        @error('form.person.addresses.' . $index . '.area')
            <p class="text-error">{{ $message }}</p>
        @enderror
    </div>

    <div class="form-group group">
        <input
            wire:model="addresses.{{ $index }}.region"
            type="text"
            id="foreignAddressRegion{{ $index }}"
            class="input peer @error('form.person.addresses.' . $index . '.region') input-error @enderror"
            placeholder=" "
            autocomplete="off"
        />
        <label for="foreignAddressRegion{{ $index }}" class="label"> {{ __('forms.region') }} </label>
        @error('form.person.addresses.' . $index . '.region')
            <p class="text-error">{{ $message }}</p>
        @enderror
    </div>

    <div class="form-group group">
        <input
            wire:model="addresses.{{ $index }}.settlement"
            type="text"
            id="foreignAddressSettlement{{ $index }}"
            class="input peer @error('form.person.addresses.' . $index . '.settlement') input-error @enderror"
            placeholder=" "
            autocomplete="off"
        />
        <label for="foreignAddressSettlement{{ $index }}" class="label"> {{ __('forms.settlement') }} </label>
        @error('form.person.addresses.' . $index . '.settlement')
            <p class="text-error">{{ $message }}</p>
        @enderror
    </div>

    <div class="form-group group">
        <input
            wire:model="addresses.{{ $index }}.street"
            type="text"
            id="foreignAddressStreet{{ $index }}"
            class="input peer @error('form.person.addresses.' . $index . '.street') input-error @enderror"
            placeholder=" "
            autocomplete="off"
        />
        <label for="foreignAddressStreet{{ $index }}" class="label"> {{ __('forms.street') }} </label>
        @error('form.person.addresses.' . $index . '.street')
            <p class="text-error">{{ $message }}</p>
        @enderror
    </div>

    <div class="form-group group">
        <input
            wire:model="addresses.{{ $index }}.building"
            type="text"
            id="foreignAddressBuilding{{ $index }}"
            class="input peer @error('form.person.addresses.' . $index . '.building') input-error @enderror"
            placeholder=" "
            autocomplete="off"
        />
        <label for="foreignAddressBuilding{{ $index }}" class="label"> {{ __('forms.building') }} </label>
        @error('form.person.addresses.' . $index . '.building')
            <p class="text-error">{{ $message }}</p>
        @enderror
    </div>

    <div class="form-group group">
        <input
            wire:model="addresses.{{ $index }}.apartment"
            type="text"
            id="foreignAddressApartment{{ $index }}"
            class="input peer @error('form.person.addresses.' . $index . '.apartment') input-error @enderror"
            placeholder=" "
            autocomplete="off"
        />
        <label for="foreignAddressApartment{{ $index }}" class="label"> {{ __('forms.apartment') }} </label>
        @error('form.person.addresses.' . $index . '.apartment')
            <p class="text-error">{{ $message }}</p>
        @enderror
    </div>

    <div class="form-group group">
        <input
            wire:model="addresses.{{ $index }}.zip"
            type="text"
            id="foreignAddressZip{{ $index }}"
            class="input peer @error('form.person.addresses.' . $index . '.zip') input-error @enderror"
            placeholder=" "
            autocomplete="off"
        />
        <label for="foreignAddressZip{{ $index }}" class="label"> {{ __('forms.zip_code') }} </label>
        @error('form.person.addresses.' . $index . '.zip')
            <p class="text-error">{{ $message }}</p>
        @enderror
    </div>
</div>

<div class="mt-auto pt-6">
    <p class="text-xs font-medium text-gray-400 italic dark:text-gray-300">{{ __('forms.addresses.latin_only') }}</p>
</div>
