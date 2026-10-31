<fieldset
    class="mt-6 mb-16 max-w-7xl rounded-lg border border-gray-200 p-4 shadow sm:p-8 sm:pb-10 dark:border-gray-700 dark:bg-gray-800"
    xmlns="http://www.w3.org/1999/html"
    x-data="{ title: '{{ __('forms.complete') }}', index: 8 }"
    x-init="typeof addHeader !== 'undefined' && addHeader(title, index)"
    x-show="activeStep === index || isEdit"
    x-cloak
    :key="`step-${index}`"
>
    <template x-if="isEdit">
        <legend x-text="title" class="legend"></legend>
    </template>

    <div class="form-row sm:w-1/2 lg:w-1/2">
        <div class="form-group group pb-4">
            <select
                required
                id="publicOfferKnedp"
                wire:model="legalEntityForm.knedp"
                aria-describedby="@error('legalEntityForm.knedp') publicOfferKnedpErrorHelp @enderror"
                class="input-select @error('legalEntityForm.knedp') input-error border-red-500 focus:border-red-500 scroll-to-error @enderror peer"
            >
                <option value="_placeholder_" selected hidden>-- {{ __('forms.select') }} --</option>

                @foreach (signatureService()->getCertificateAuthorities() as $certificateType)
                    <option value="{{ $certificateType['id'] }}">{{ $certificateType['name'] }}</option>
                @endforeach
            </select>

            @error('legalEntityForm.knedp')
                <p id="publicOfferKnedpErrorHelp" class="text-error">{{ $message }}</p>
            @enderror

            <label for="publicOfferKnedp" class="label z-10"> {{ __('forms.knedp') }} </label>
        </div>

        <div wire:ignore.self class="form-group group py-4">
            <x-forms.file
                required
                wire:model="legalEntityForm.keyContainerUpload"
                file="{{ isset($legalEntityForm->keyContainerUpload) ? $legalEntityForm->keyContainerUpload->getClientOriginalName() : '' }}"
                accept=".dat,.pfx,.pk8,.zs2,.jks,.p7s"
                aria-describedby="{{ $errors->has('legalEntityForm.keyContainerUpload') ? 'publicOfferFileErrorHelp' : '' }}"
                :id="'keyContainerFileUpload'"
            />

            @error('legalEntityForm.keyContainerUpload')
                <p id="publicOfferFileErrorHelp" class="text-error">{{ $message }}</p>
            @enderror

            <label
                for="keyContainerFileUpload"
                class="label z-10 @error('legalEntityForm.keyContainerUpload') scroll-to-error @enderror"
            >
                {{ __('forms.key_container_upload') }} *
            </label>
        </div>

        <div class="form-group group">
            <x-password-field>
                <input
                    required
                    type="password"
                    placeholder=" "
                    id="publicOfferPassword"
                    wire:model="legalEntityForm.password"
                    aria-describedby="@error('legalEntityForm.password') publicOfferPasswordErrorHelp @enderror"
                    class="input @error('legalEntityForm.password') input-error border-red-500 focus:border-red-500 scroll-to-error @enderror peer"
                />

                @error('legalEntityForm.password')
                    <p id="publicOfferPasswordErrorHelp" class="text-error">{{ $message }}</p>
                @enderror

                <label for="publicOfferPassword" class="label z-10"> {{ __('forms.password') }} </label>
            </x-password-field>
        </div>
    </div>
</fieldset>
