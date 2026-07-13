<template x-teleport="body">
    <div
        x-show="showConsentFormModal"
        style="display: none"
        @keydown.escape.prevent.stop="showConsentFormModal = false"
        role="dialog"
        aria-modal="true"
        class="modal"
    >
        <div x-transition.opacity class="fixed inset-0 bg-black/30"></div>
        <div x-transition @click="showConsentFormModal = false" class="modal-wrapper">
            <div
                @click.stop
                x-trap.noscroll.inert="showConsentFormModal"
                class="modal-content w-full max-w-4xl mx-auto rounded-2xl shadow-lg bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden p-8 space-y-6"
                x-data="{
                    printModal() {
                        const iframe = document.createElement('iframe');
                        iframe.setAttribute('aria-hidden', 'true');
                        iframe.style.position = 'fixed';
                        iframe.style.width = '0';
                        iframe.style.height = '0';
                        iframe.style.border = '0';
                        iframe.srcdoc = $wire.dataToBeSigned?.content ?? '';

                        iframe.addEventListener('load', () => {
                            iframe.contentWindow.focus();
                            iframe.contentWindow.print();
                            iframe.contentWindow.addEventListener('afterprint', () => iframe.remove());
                        });

                        document.body.appendChild(iframe);
                    }
                }"
            >
                <div class="max-h-[70vh] overflow-y-auto rounded-lg bg-white text-gray-900 p-6">
                    {!! data_get($this->dataToBeSigned, 'content') !!}
                </div>

                <div class="flex gap-4 border-t border-gray-200 dark:border-gray-700 pt-6">
                    <button
                        type="button"
                        @click="showConsentFormModal = false"
                        class="button-minor"
                    >
                        {{ __('forms.close') }}
                    </button>
                    <button
                        type="button"
                        @click="printModal()"
                        class="inline-flex items-center gap-2 border border-blue-600 hover:bg-blue-50 dark:hover:bg-blue-950/20 text-blue-600 dark:text-blue-400 px-4 py-2.5 rounded-lg transition-colors font-semibold text-sm cursor-pointer"
                    >
                        @icon('printer', 'w-4 h-4')
                        <span>{{ __('preperson.print') }}</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
