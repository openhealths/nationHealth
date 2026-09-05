@use('App\Models\Relations\Address')

<fieldset class="fieldset">
    <legend class="legend">{{ __('forms.address') }}</legend>

    @foreach ($addresses as $index => $address)
        <div
            wire:key="address-{{ $index }}"
            class="border-b border-gray-200 pb-8 last:border-b-0 last:pb-0 dark:border-gray-700"
        >
            <div class="form-row-3 mt-8">
                <div class="form-group">
                    <select
                        wire:model="addresses.{{ $index }}.type"
                        id="addressType{{ $index }}"
                        class="input-select peer @error('form.person.addresses.' . $index . '.type') input-error @enderror"
                        required
                    >
                        <option value="" hidden>-- {{ __('forms.select') }} --</option>

                        @foreach ($this->dictionaries['ADDRESS_TYPE'] as $key => $addressType)
                            <option value="{{ $key }}">{{ $addressType }}</option>
                        @endforeach
                    </select>
                    <label for="addressType{{ $index }}" class="label"> {{ __('forms.type') }} </label>
                    @error('form.person.addresses.' . $index . '.type')
                        <p class="text-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="form-group">
                    <select
                        wire:model.live="addresses.{{ $index }}.country"
                        id="addressCountry{{ $index }}"
                        class="input-select peer @error('form.person.addresses.' . $index . '.country') input-error @enderror"
                        required
                    >
                        @foreach ($this->dictionaries['COUNTRY'] as $key => $country)
                            <option value="{{ $key }}">{{ $country }}</option>
                        @endforeach
                    </select>
                    <label for="addressCountry{{ $index }}" class="label"> {{ __('forms.country') }} </label>
                    @error('form.person.addresses.' . $index . '.country')
                        <p class="text-error">{{ $message }}</p>
                    @enderror
                </div>

                @if (count($addresses) > 1)
                    <div class="flex items-start">
                        <button
                            type="button"
                            wire:click="removeAddress({{ $index }})"
                            wire:target="removeAddress({{ $index }})"
                            wire:loading.attr="disabled"
                            class="button-minor disabled:opacity-50"
                        >
                            <span wire:target="removeAddress({{ $index }})" wire:loading.remove>
                                @icon('trash', 'w-4 h-4 mr-2')
                            </span>
                            <span wire:target="removeAddress({{ $index }})" wire:loading>
                                @icon('refresh', 'w-4 h-4 mr-2 animate-spin')
                            </span>
                            {{ __('forms.addresses.remove') }}
                        </button>
                    </div>
                @endif
            </div>

            @if (($address['country'] ?? Address::DEFAULT_COUNTRY) === Address::DEFAULT_COUNTRY)
                <div wire:key="address-{{ $index }}-ukraine">
                    <x-forms.addresses-search
                        :address="$address"
                        :districts="$districts"
                        :settlements="$settlements"
                        :streets="$streets"
                        :property="'addresses.' . $index"
                        class="form-row-3 mt-8"
                    />

                    <div class="mt-auto pt-6">
                        <p class="text-xs font-medium text-gray-400 italic dark:text-gray-300">
                            {{ __('forms.addresses.try_without_region') }}
                        </p>
                    </div>
                </div>
            @else
                <div wire:key="address-{{ $index }}-foreign">
                    @include('livewire.person.parts.foreign-address', ['index' => $index])
                </div>
            @endif
        </div>
    @endforeach

    <div class="mt-8">
        <button
            type="button"
            wire:click="addAddress"
            wire:target="addAddress"
            wire:loading.attr="disabled"
            class="button-minor disabled:opacity-50"
        >
            <span wire:target="addAddress" wire:loading.remove>@icon('plus', 'w-4 h-4 mr-2')</span>
            <span wire:target="addAddress" wire:loading>@icon('refresh', 'w-4 h-4 mr-2 animate-spin')</span>
            {{ __('forms.addresses.add') }}
        </button>
    </div>
</fieldset>
