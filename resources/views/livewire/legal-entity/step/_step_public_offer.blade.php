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

    <div class="form-row mb-6 sm:w-1/2 lg:w-1/2">
        <div class="form-group group w-full">
            <input
                type="url"
                id="legalEntityRedirectUri"
                name="security.redirect_uri"
                value="{{ config('ehealth.api.connection_redirect_uri') }}"
                placeholder=" "
                class="input peer"
                readonly
            />
            <label for="legalEntityRedirectUri" class="label z-10">
                {{ __('legal-entity.security.redirect_uri') }}
            </label>
        </div>
    </div>

    <div class="form-row sm:w-1/2 lg:w-1/2">
        <x-signature-fields model="legalEntityForm" />
    </div>
</fieldset>
