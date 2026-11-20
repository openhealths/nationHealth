<fieldset
    class="mt-6 mb-16 max-w-[1280px] rounded-lg border border-gray-200 p-4 shadow sm:p-8 sm:pb-10 dark:border-gray-700 dark:bg-gray-800"
    xmlns="http://www.w3.org/1999/html"
    x-data="{ title: '{{ __('forms.le_contacts') }}', index: 3 }"
    x-init="typeof addHeader !== 'undefined' && addHeader(title, index)"
    x-show="activeStep === index || isEdit"
    x-cloak
    :key="`step-${index}`"
>
    <template x-if="isEdit">
        <legend x-text="title" class="legend"></legend>
    </template>

    <div class="form-row-3">
        {{-- Email --}}
        <div class="form-group group">
            <svg class="svg-input w-5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24">
                <path d="M2.038 5.61A2.01 2.01 0 0 0 2 6v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6c0-.12-.01-.238-.03-.352l-.866.65-7.89 6.032a2 2 0 0 1-2.429 0L2.884 6.288l-.846-.677Z" />
                <path d="M20.677 4.117A1.996 1.996 0 0 0 20 4H4c-.225 0-.44.037-.642.105l.758.607L12 10.742 19.9 4.7l.777-.583Z" />
            </svg>

            <input
                required
                type="text"
                placeholder=" "
                id="contact_email"
                wire:model="legalEntityForm.email"
                value="{{ $legalEntityForm->email ?? '' }}"
                aria-describedby="@error('legalEntityForm.email') contactEmailErrorHelp @enderror"
                class="input @error('legalEntityForm.email') input-error border-red-500 focus:border-red-500 scroll-to-error @enderror peer"
            />

            @error('legalEntityForm.email')
                <p id="contactEmailErrorHelp" class="text-error">{{ $message }}</p>
            @enderror

            <label for="contact_email" class="label z-10"> {{ __('forms.email') }} </label>
        </div>

        {{-- Web Site --}}
        <div class="form-group group">
            <input
                type="text"
                placeholder=" "
                id="website"
                value="{{ $legalEntityForm->website ?? '' }}"
                wire:model="legalEntityForm.website"
                aria-describedby="@error('legalEntityForm.website') websiteErrorHelp @enderror"
                class="input @error('legalEntityForm.website') input-error border-red-500 focus:border-red-500 scroll-to-error @enderror peer"
            />

            @error('legalEntityForm.website')
                <p id="websiteErrorHelp" class="text-error">{{ $message }}</p>
            @enderror

            <label for="website" class="label z-10"> {{ __('forms.website') }} </label>

            <span class="mt-1 text-xs text-blue-600"> {{ __('forms.website_hint') }} </span>
        </div>
    </div>

    {{-- P H O N E --}}
    <div
        class="form-row mt-6"
        x-data="{ phones: $wire.entangle('legalEntityForm.phones') }"
        x-init="
            if (! Array.isArray(phones) || phones.length === 0) {
                phones = [{ type: '', number: '' }];
            }
        "
        x-id="['phone']"
    >
        <template x-for="(phone, index) in phones" :key="index">
            <div
                class="form-row-3"
                x-data="{ errors: [] }"
                x-init="errors =@js($errors->getMessages())"
                :class="{ 'mb-2': index == phones.length - 1 }"
            >
                {{-- Phone Type Select --}}
                <div class="form-group">
                    <select
                        required
                        x-model="phones[index].type"
                        class="input-select"
                        :class="{ 'input-error scroll-to-error': errors[`legalEntityForm.phones.${index}.type`] }"
                        :id="$id('phone', '_type_' + index)"
                    >
                        <option value="_placeholder_" selected hidden>-- {{ __('forms.type_mobile') }} --</option>
                        <template x-for="(phoneType, key) in $wire.dictionaries.PHONE_TYPE" :key="key">
                            <option
                                x-text="phoneType"
                                :value="key"
                                :disabled="phones.some((p) => p.type === key)"
                                :selected="phone.type === key"
                            ></option>
                        </template>
                    </select>

                    <template x-if="errors[`legalEntityForm.phones.${index}.type`]">
                        <p class="text-error" x-text="errors[`legalEntityForm.phones.${index}.type`]"></p>
                    </template>

                    <label :for="$id('phone', '_type_' + index)" class="label">{{ __('forms.phone_type') }}</label>
                </div>

                {{-- Phone Number Input --}}
                <div class="form-group phone-wrapper">
                    <input
                        required
                        type="tel"
                        placeholder=" "
                        class="peer input with-leading-icon pl-10 text-gray-500"
                        x-model="phones[index].number"
                        x-mask="+380999999999"
                        :id="$id('phone', '_number' + index)"
                        :class="{
                            'input-error border-red-500 scroll-to-error':
                                errors[`legalEntityForm.phones.${index}.number`],
                        }"
                    />

                    <template x-if="errors[`legalEntityForm.phones.${index}.number`]">
                        <p class="text-error" x-text="errors[`legalEntityForm.phones.${index}.number`]"></p>
                    </template>

                    <label :for="$id('phone', '_number' + index)" class="wrapped-label">
                        {{ __('forms.phone') }}
                    </label>
                </div>

                <!-- Action Phone Buttons -->
                <div x-show="! @json($isDetails ?? false)" class="flex items-center justify-start space-x-4">
                    <!-- Add phone -->
                    <template x-if="phones.length > 1">
                        <button
                            type="button"
                            @click.prevent="phones.splice(index, 1)"
                            class="item-remove justify-self-start"
                        >
                            <span>{{ __('forms.remove_phone') }}</span>
                        </button>
                    </template>

                    <!-- Remove Phone -->
                    <template x-if="index === phones.length - 1 && phones.length < 2">
                        <button type="button" @click.prevent="phones.push({ type: '', number: '' })" class="item-add">
                            <span>{{ __('forms.add_phone') }}</span>
                        </button>
                    </template>
                </div>
            </div>
        </template>
    </div>
</fieldset>
