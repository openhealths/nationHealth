<template x-teleport="body">
    <div
        x-show="showRegisterDeathModal"
        style="display: none"
        @keydown.escape.prevent.stop="showRegisterDeathModal = false"
        role="dialog"
        aria-modal="true"
        class="modal"
    >
        <div x-transition.opacity class="fixed inset-0 bg-black/30"></div>
        <div x-transition @click="showRegisterDeathModal = false" class="modal-wrapper">
            <div
                @click.stop
                x-trap.noscroll.inert="showRegisterDeathModal"
                class="modal-content w-full max-w-3xl mx-auto p-8 rounded-xl bg-white dark:bg-gray-800"
            >
                <div class="flex flex-col gap-6">
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white">
                        {{ __('preperson.register_death_title') }}
                    </h3>

                    <div
                        class="p-6 rounded-xl bg-red-50 dark:bg-red-950/20 border border-red-100 dark:border-red-900/30 flex flex-col gap-3"
                    >
                        <div class="flex items-center gap-2">
                            @icon('alert-circle', 'w-5 h-5 text-red-600 dark:text-red-400')
                            <h4 class="font-bold text-red-600 dark:text-red-400 text-sm">
                                {{ __('preperson.warning_title') }}
                            </h4>
                        </div>
                        <div class="text-red-500 dark:text-red-300 text-xs leading-relaxed">
                            {{ __('preperson.register_death_warning') }}
                        </div>
                    </div>

                    <div class="flex gap-4 xl:flex-row justify-start items-center mt-4">
                        <button
                            type="button"
                            @click="showRegisterDeathModal = false"
                            class="button-minor"
                            style="margin: 0 !important;"
                        >
                            {{ __('forms.back') }}
                        </button>
                        <button
                            type="button"
                            @click="showRegisterDeathModal = false; showRegisterDeathDateModal = true;"
                            class="button-danger"
                            style="margin: 0 !important;"
                        >
                            {{ __('forms.continue') }}
                        </button>

                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
