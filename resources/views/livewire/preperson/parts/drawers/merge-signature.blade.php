<x-dialog-drawer
    x-model="showMergeSignatureDrawer"
    onCloseClick="showMergeSignatureDrawer = false"
    maxWidth="4/5"
>
    <x-slot name="title">
        {{ __('preperson.merge.electronic_signature') }}
    </x-slot>

    <div class="space-y-6 max-w-2xl">
        <x-signature-fields />

        <div class="flex gap-3 pt-6">
            <button
                type="button"
                @click="showMergeSignatureDrawer = false; showMergeFinalConsentDrawer = true"
                class="button-minor"
            >
                {{ __('forms.cancel') }}
            </button>

            <button
                type="button"
                wire:click="sign"
                wire:loading.attr="disabled"
                wire:loading.class="opacity-50 cursor-not-allowed"
                wire:target="sign"
                class="button-primary"
            >
                <span wire:loading.remove wire:target="sign">{{ __('forms.sign') }}</span>
                <span wire:loading wire:target="sign">{{ __('forms.signature') }}...</span>
            </button>
        </div>
    </div>
</x-dialog-drawer>
