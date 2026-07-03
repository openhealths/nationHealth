<div x-data>
    <template x-teleport="body">
        <div
            x-show="showAlternativeIdentificationModal"
            style="display: none"
            @keydown.escape.prevent.stop="showAlternativeIdentificationModal = false"
            role="dialog"
            aria-modal="true"
            class="modal"
        >
            <div x-transition.opacity class="fixed inset-0 bg-black/30"></div>
            <div x-transition @click="showAlternativeIdentificationModal = false" class="modal-wrapper">
                <div
                    @click.stop x-trap.noscroll.inert="showAlternativeIdentificationModal"
                    class="modal-content w-full max-w-3xl mx-auto p-8 rounded-xl bg-white dark:bg-gray-800"
                >
                    <div class="flex flex-col gap-6">
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white">
                            {{ __('patients.unidentified_modal_title') }}
                        </h3>

                        <p class="text-gray-600 dark:text-gray-300 text-sm leading-relaxed">
                            {{ __('patients.unidentified_modal_text') }}
                        </p>

                        <div
                            class="p-6 rounded-xl bg-red-50 dark:bg-red-950/20 border border-red-100 dark:border-red-900/30 flex flex-col gap-3">
                            <div class="flex items-center gap-2">
                                @icon('alert-circle', 'w-5 h-5 text-red-600 dark:text-red-400')
                                <h4 class="font-bold text-red-600 dark:text-red-400 text-sm">
                                    {{ __('patients.unidentified_warning_title') }}
                                </h4>
                            </div>
                            <div class="text-red-500 dark:text-red-300 text-xs leading-relaxed">
                                {{ __('patients.unidentified_modal_warning_text') }}
                            </div>
                        </div>

                        <div class="flex gap-4 xl:flex-row justify-start items-center mt-4">
                            <a
                                href="{{ route('persons.index', [legalEntity()]) }}"
                                wire:navigate
                                class="button-minor"
                            >
                                {{ __('patients.unidentified_modal_btn_later') }}
                            </a>
                            <a
                                href="{{ $createdPrepersonId ? route('encounter.create', [legalEntity(), 'person' => $createdPrepersonId]) : '' }}"
                                wire:navigate
                                class="button-primary"
                            >
                                {{ __('patients.unidentified_modal_btn_create') }}
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>
