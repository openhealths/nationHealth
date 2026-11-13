{{-- Signature Drawer Overlay --}}
<div x-show="showSignatureDrawer"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     x-cloak
     @click="showSignatureDrawer = false"
     class="fixed inset-0 bg-gray-900/70"
     style="z-index: 46;"
></div>

{{-- Signature Drawer --}}
<div x-show="showSignatureDrawer"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="translate-x-full"
     x-transition:enter-end="translate-x-0"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="translate-x-0"
     x-transition:leave-end="translate-x-full"
     x-cloak
     @click.stop
     class="fixed top-0 right-0 h-screen pt-20 bg-white dark:bg-gray-800 shadow-2xl"
     style="z-index: 47; width: 50%;"
     id="signature-drawer"
     tabindex="-1"
>
    <div class="border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 px-6 py-4">
        <h2 class="text-xl font-bold text-gray-900 dark:text-white">
            {{ __('forms.sign_with_KEP') }}
        </h2>
    </div>

    <div class="overflow-y-auto p-6 bg-white dark:bg-gray-800" style="height: calc(100% - 70px);">
        <x-signature-fields />

        <div class="flex gap-3 mt-8">
            <button type="button"
                    @click="showSignatureDrawer = false"
                    class="button-minor"
            >
                {{ __('forms.cancel') }}
            </button>

            <button type="button"
                    wire:click.prevent="signConfidantPersonRelationship"
                    class="button-primary"
            >
                {{ __('forms.sign') }}
            </button>
        </div>
    </div>
</div>
