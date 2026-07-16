<fieldset class="fieldset">
    <legend class="legend">
        {{ __('forms.address') }}
    </legend>
    <x-forms.addresses-search
        :address="$address"
        :districts="$districts"
        :settlements="$settlements"
        :streets="$streets"
        class="mt-8 form-row-3"
    />

    <div class="pt-6 mt-auto">
        <p class="italic text-xs font-medium text-gray-400 dark:text-gray-300">{{ __('forms.addresses.try_without_region') }}</p>
    </div>
</fieldset>
