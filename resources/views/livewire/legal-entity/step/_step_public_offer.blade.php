@php
    $hasPublicOfferFileError = $errors->has('keyContainerUpload');
@endphp

<fieldset
    class="p-4 sm:p-8 sm:pb-10 mb-16 mt-6 border border-gray-200 rounded-lg shadow dark:bg-gray-800 dark:border-gray-700 max-w-[1280px]"
    xmlns="http://www.w3.org/1999/html"
    x-data="{ title: '{{ __('forms.complete') }}', index: 8 }"
    x-init="typeof addHeader !== 'undefined' && addHeader(title, index)"
    x-show="activeStep === index  || isEdit"
    x-cloak
    :key="`step-${index}`"
>
    <template x-if="isEdit">
        <legend x-text="title" class="legend"></legend>
    </template>

    <div class='form-row lg:w-1/2 sm:w-1/2'>
        <div class="form-group group pb-4">
            <select
                required
                id="publicOfferKnedp"
                wire:model="knedp"
                aria-describedby="@error('knedp') publicOfferKnedpErrorHelp @enderror"
                class="input-select @error('knedp') input-error border-red-500 focus:border-red-500 scroll-to-error @enderror peer"
            >
                <option value="_placeholder_" selected hidden>-- {{ __('forms.select') }} --</option>

                @foreach($getCertificateAuthority as $k => $certificate_type)
                    <option value="{{ $certificate_type['id'] }}">{{ $certificate_type['name'] }}</option>
                @endforeach
            </select>

            @error('knedp')
                <p id="publicOfferKnedpErrorHelp" class="text-error">
                    {{ $message }}
                </p>
            @enderror

            <label for="publicOfferKnedp" class="label z-10">
                {{ __('forms.knedp') }}
            </label>
        </div>

        <div wire:ignore.self class="form-group group py-4">
            <x-forms.file
                required
                wire:model="keyContainerUpload"
                file="{{ $keyContainerUpload?->getClientOriginalName() }}"
                accept=".dat,.pfx,.pk8,.zs2,.jks,.p7s"
                aria-describedby="{{ $hasPublicOfferFileError ? 'publicOfferFileErrorHelp' : '' }}"
                :id="'keyContainerFileUpload'"
            />

            @error('keyContainerUpload')
                <p id="publicOfferFileErrorHelp" class="text-error">
                    {{ $message }}
                </p>
            @enderror

            <label for="keyContainerFileUpload" class="label z-10 @error('keyContainerUpload') scroll-to-error @enderror">
                {{ __('forms.key_container_upload') }} *
            </label>
        </div>

        <div class="form-group group">
            <input
                required
                type="password"
                placeholder=" "
                id="publicOfferPassword"
                wire:model="password"
                aria-describedby="@error('password') publicOfferPasswordErrorHelp @enderror"
                class="input @error('password') input-error border-red-500 focus:border-red-500 scroll-to-error @enderror peer"
            />

            @error('password')
                <p id="publicOfferPasswordErrorHelp" class="text-error">
                    {{ $message }}
                </p>
            @enderror

            <label for="publicOfferPassword" class="label z-10">
                {{ __('forms.password') }}
            </label>
        </div>
    </div>
</fieldset>
