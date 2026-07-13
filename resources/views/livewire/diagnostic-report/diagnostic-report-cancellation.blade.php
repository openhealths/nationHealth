<div x-data="{ showCancellationModal: $wire.entangle('showCancellationModal') }">
    <template x-teleport="body">
        <div x-show="showCancellationModal"
             x-cloak
             role="dialog"
             aria-modal="true"
             class="modal"
             @keydown.escape.prevent.stop="$wire.closeDiagnosticReportCancellationModal()"
        >
            <div x-transition.opacity
                 class="fixed inset-0 bg-black/30"
                 @click="$wire.closeDiagnosticReportCancellationModal()"
            ></div>

            <div class="modal-wrapper">
                <div class="modal-content w-full max-w-6xl mx-auto bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100"
                     @click.stop
                     x-transition
                     x-trap.noscroll.inert="showCancellationModal"
                >
                    <div class="p-8 md:p-12">
                        <h3 class="text-2xl md:text-3xl font-bold text-gray-900 dark:text-gray-100 leading-tight max-w-5xl">
                            {{ __('patients.messages.diagnostic_report_cancel_modal_title') }}
                        </h3>

                        <p class="mt-12 text-xl md:text-2xl leading-relaxed text-gray-700 dark:text-gray-200 max-w-5xl">
                            {{ __('patients.messages.diagnostic_report_cancel_modal_description') }}
                        </p>

                        <div class="mt-12 max-w-5xl">
                            <label for="cancellationReason" class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-4">
                                {{ __('patients.messages.diagnostic_report_cancel_reason_label') }} *
                            </label>

                            <select
                                class="w-full border-0 border-b border-gray-300 dark:border-gray-600 bg-transparent px-1 py-3 text-lg text-gray-700 dark:text-gray-100 focus:border-blue-500 focus:ring-0"
                                wire:model="form.cancellationReason"
                                name="cancellationReason"
                                id="cancellationReason"
                            >
                                <option value="" class="bg-white text-gray-900 dark:bg-gray-800 dark:text-white">{{ __('patients.messages.diagnostic_report_cancel_reason_placeholder') }}</option>

                                @foreach(data_get($this->dictionaries, 'eHealth/cancellation_reasons', []) as $code => $label)
                                    <option value="{{ $code }}" class="bg-white text-gray-900 dark:bg-gray-800 dark:text-white" wire:key="diagnostic-report-cancel-reason-{{ $code }}">
                                        {{ $label }}
                                    </option>
                                @endforeach
                            </select>

                            @error('form.cancellationReason')
                                <p class="text-error mt-2">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="mt-10 max-w-5xl">
                            <label for="explanatoryLetter" class="block text-base font-semibold text-gray-700 dark:text-gray-200 mb-4">
                                {{ __('patients.messages.diagnostic_report_cancel_explanation_label') }}
                            </label>

                            <textarea
                                wire:model="form.explanatoryLetter"
                                id="explanatoryLetter"
                                name="explanatoryLetter"
                                maxlength="255"
                                class="w-full min-h-48 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-5 py-4 text-lg text-gray-700 dark:text-gray-100 placeholder-gray-500 dark:placeholder-gray-400 focus:border-blue-500 focus:ring-blue-500"
                                placeholder="{{ __('forms.write_comment_here') }}"
                            ></textarea>

                            @error('form.explanatoryLetter')
                                <p class="text-error mt-2">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="mt-16 flex flex-row items-center gap-8 text-gray-900 dark:text-gray-100">
                            <button type="button"
                                    wire:click="closeDiagnosticReportCancellationModal"
                                    class="button-minor px-8"
                            >
                                {{ __('forms.cancel') }}
                            </button>

                            <button type="button"
                                    wire:click="proceedToSignature"
                                    wire:loading.attr="disabled"
                                    wire:loading.class="opacity-50 cursor-not-allowed"
                                    wire:target="proceedToSignature"
                                    class="px-8 py-3 rounded-lg bg-red-600 text-white font-semibold hover:bg-red-700 transition-colors"
                            >
                                <span wire:loading.remove wire:target="proceedToSignature">
                                    {{ __('patients.messages.diagnostic_report_cancel_confirm_button') }}
                                </span>

                                <span wire:loading wire:target="proceedToSignature">
                                    {{ __('forms.loading') ?? 'Завантаження...' }}
                                </span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </template>

    <x-signature-modal method="cancelSelectedDiagnosticReport"/>
</div>