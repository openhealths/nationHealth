<fieldset class="fieldset">
    <legend class="legend">{{ __('patients.contact_data') }}</legend>

    {{-- Using Alpine to dynamically add and remove phone input fields --}}
    <div class="mb-4" x-data="{ phones: $wire.entangle('form.person.phones') }">
        <template x-for="(phone, index) in phones">
            <div class="form-row-3 md:mb-0">
                <div class="form-group group">
                    <label :for="'phoneType-' + index" class="sr-only">{{ __('forms.type_mobile') }}</label>
                    <select x-model="phone.type" :id="'phoneType-' + index" class="input-select peer">
                        <option value="" selected>{{ __('forms.type_mobile') }}</option>
                        @foreach ($this->dictionaries['PHONE_TYPE'] as $key => $phoneType)
                            <option value="{{ $key }}">{{ $phoneType }}</option>
                        @endforeach
                    </select>

                    @error('form.person.phones.*.type')
                        <p class="text-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="form-group group">
                    <div class="phone-wrapper">
                        <input
                            x-model="phone.number"
                            x-mask="+999999999999"
                            type="tel"
                            name="phoneNumber"
                            :id="'phoneNumber-' + index"
                            class="input with-leading-icon peer @error('form.person.phones.*.number') input-error @enderror"
                            placeholder=" "
                        />
                        <label :for="'phoneNumber-' + index" class="wrapped-label">
                            {{ __('forms.phone_number') }}
                        </label>
                    </div>

                    @error('form.person.phones.*.number')
                        <p class="text-error">{{ $message }}</p>
                    @enderror
                </div>
                <template x-if="(index == phones.length - 1) & (index != 0)">
                    {{-- Remove a phone if button is clicked --}}
                    <button @click="(phones.pop(), index--)" class="item-remove">{{ __('forms.remove_phone') }}</button>
                </template>
                <template x-if="index == phones.length - 1">
                    {{-- Add new phone if button is clicked --}}
                    <button
                        @click="phones.push({ type: '', number: '' })"
                        class="item-add lg:justify-self-start"
                        :class="{ 'lg:justify-self-start': index > 0 }"
                        {{-- Apply this style only if it's not a first phone group --}}
                    >
                        {{ __('forms.add_phone') }}
                    </button>
                </template>
            </div>
        </template>
    </div>

    <div class="form-row-3">
        <div class="form-group group">
            <input
                wire:model="form.person.email"
                type="email"
                name="email"
                id="email"
                class="input peer @error('form.person.email') input-error @enderror"
                placeholder=" "
                autocomplete="off"
            />
            <label for="email" class="label"> {{ __('forms.email') }} </label>

            @error('form.person.email')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="form-group group">
            <input
                wire:model="form.person.secret"
                type="text"
                name="secret"
                id="secret"
                class="input peer @error('form.person.secret') input-error @enderror"
                placeholder=" "
                required
                autocomplete="off"
            />
            <label for="secret" class="label"> {{ __('patients.secret') }} </label>

            @error('form.person.secret')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="form-group">
            <select
                wire:model="form.person.preferredWayCommunication"
                name="preferredWayCommunication"
                id="preferredWayCommunication"
                class="input-select peer @error('form.person.preferredWayCommunication') input-error @enderror"
            >
                <option value="" selected>{{ __('forms.select') }}</option>
                {{-- The dictionary keys are uppercase, while the eHealth enum accepts only the lowercase form --}}
                @foreach ($this->dictionaries['PREFERRED_WAY_COMMUNICATION'] as $key => $wayCommunication)
                    <option value="{{ strtolower($key) }}">{{ $wayCommunication }}</option>
                @endforeach
            </select>
            <label for="preferredWayCommunication" class="label">
                {{ __('patients.preferred_way_communication') }}
            </label>

            @error('form.person.preferredWayCommunication')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>
</fieldset>
