<x-dialog-drawer
    x-model="showMergeFinalConsentDrawer"
    onCloseClick="showMergeFinalConsentDrawer = false"
    maxWidth="4/5"
>
    <x-slot name="title">
        {{ __('preperson.merge.consent_form_title') }}
    </x-slot>

    <div x-data="{ consentSigned: false }">

    <div class="mt-8 space-y-6">
        <div class="space-y-3.5">
            <div class="p-4 rounded-lg bg-gray-50 dark:bg-gray-800/40 border border-gray-100 dark:border-gray-700/50 text-sm text-gray-700 dark:text-gray-300">
                {{ __('preperson.merge.consent_step_1') }}
            </div>
            <div>
                <button type="button"
                        class="inline-flex items-center gap-2 px-4 py-2.5 bg-gray-50 hover:bg-gray-100 dark:bg-gray-800 dark:hover:bg-gray-700 border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 text-sm font-medium rounded-lg transition-colors cursor-pointer"
                        @click="showConsentFormModal = true"
                >
                    @icon('printer', 'w-4 h-4 text-gray-600 dark:text-gray-400')
                    <span>{{ __('preperson.merge.print_consent_form') }}</span>
                </button>
            </div>
        </div>

        <div class="space-y-4">
            <div class="p-4 rounded-lg bg-gray-50 dark:bg-gray-800/40 border border-gray-100 dark:border-gray-700/50 text-sm text-gray-700 dark:text-gray-300">
                {{ __('preperson.merge.consent_step_2') }}
            </div>

            <div class="flex items-start">
                <div class="flex items-center h-5">
                    <input id="consentSigned"
                           type="checkbox"
                           x-model="consentSigned"
                           class="default-checkbox"
                    >
                </div>
                <label for="consentSigned" class="ml-2 text-sm font-medium text-gray-900 dark:text-gray-300 cursor-pointer select-none">
                    {{ __('preperson.merge.consent_form_signed_checkbox') }}
                </label>
            </div>
        </div>

        <div class="p-6 rounded-xl bg-blue-50 dark:bg-blue-950/20 border border-blue-100 dark:border-blue-900/30 flex flex-col gap-4">
            <div class="flex items-center gap-2">
                @icon('alert-circle', 'w-5 h-5 text-blue-600 dark:text-blue-400')
                <h4 class="font-bold text-blue-800 dark:text-blue-300 text-sm tracking-wider">
                    {{ __('preperson.merge.attention') }}
                </h4>
            </div>
            <p class="text-sm text-blue-755 dark:text-blue-300 leading-relaxed font-medium">
                {{ __('preperson.merge.attention_warning_text') }}
            </p>
        </div>
    </div>

    <div class="flex gap-3 mt-12">
        <button class="button-minor"
                type="button"
                @click="showMergeFinalConsentDrawer = false; if (currentMethod === 'SMS') { showMergeSmsDrawer = true; } else { showMergeDocumentsDrawer = true; }"
        >
            {{ __('forms.back') }}
        </button>

        <button class="button-primary"
                type="button"
                :disabled="!consentSigned"
                @click="showMergeFinalConsentDrawer = false; showMergeSignatureDrawer = true"
        >
            {{ __('forms.confirm') }}
        </button>
    </div>
</x-dialog-drawer>
