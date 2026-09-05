<div x-data="{ showCancellationModal: $wire.entangle('showCancellationModal') }">
    <template x-teleport="body">
        <div
            x-show="showCancellationModal"
            x-cloak
            role="dialog"
            aria-modal="true"
            class="modal"
            @keydown.escape.prevent.stop="$wire.closeProcedureCancellationModal()"
        >
            <div
                x-transition.opacity
                class="fixed inset-0 bg-black/30"
                @click="$wire.closeProcedureCancellationModal()"
            ></div>

            <div class="modal-wrapper">
                <div
                    class="modal-content mx-auto w-full max-w-6xl bg-white text-gray-900 dark:bg-gray-800 dark:text-gray-100"
                    @click.stop
                    x-transition
                    x-trap.noscroll.inert="showCancellationModal"
                >
                    <div class="p-8 md:p-12">
                        <h3 class="max-w-5xl text-2xl leading-tight font-bold text-gray-900 md:text-3xl dark:text-gray-100">
                            {{ __('procedures.messages.cancel_modal_title') }}
                        </h3>

                        <p class="mt-12 max-w-5xl text-xl leading-relaxed text-gray-700 md:text-2xl dark:text-gray-200">
                            {{ __('procedures.messages.cancel_modal_description') }}
                        </p>

                        <div class="mt-12 max-w-5xl">
                            <label
                                for="cancellationReason"
                                class="mb-4 block text-sm font-medium text-gray-700 dark:text-gray-200"
                            >
                                {{ __('procedures.messages.cancel_reason_label') }} *
                            </label>

                            <select
                                class="w-full border-0 border-b border-gray-300 bg-transparent px-1 py-3 text-lg text-gray-700 focus:border-blue-500 focus:ring-0 dark:border-gray-600 dark:text-gray-100"
                                wire:model="form.statusReason"
                                name="statusReason"
                                id="statusReason"
                            >
                                <option value="">{{ __('procedures.messages.cancel_reason_placeholder') }}</option>

                                @foreach (data_get($this->dictionaries, 'eHealth/procedure_status_reasons', []) as $code => $label)
                                    <option value="{{ $code }}" wire:key="procedure-cancel-reason-{{ $code }}">
                                        {{ $label }}
                                    </option>
                                @endforeach
                            </select>

                            @error('form.statusReason')
                                <p class="text-error mt-2">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="mt-10 max-w-5xl">
                            <label
                                for="explanatoryLetter"
                                class="mb-4 block text-base font-semibold text-gray-700 dark:text-gray-200"
                            >
                                {{ __('procedures.messages.cancel_explanation_label') }} *
                            </label>

                            <textarea
                                wire:model="form.explanatoryLetter"
                                id="explanatoryLetter"
                                name="explanatoryLetter"
                                maxlength="255"
                                class="min-h-48 w-full rounded-lg border border-gray-300 bg-white px-5 py-4 text-lg text-gray-700 placeholder-gray-500 focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:placeholder-gray-400"
                                placeholder="{{ __('forms.write_comment_here') }}"
                            ></textarea>

                            @error('form.explanatoryLetter')
                                <p class="text-error mt-2">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="mt-16 flex flex-row items-center gap-8 text-gray-900 dark:text-gray-100">
                            <button
                                type="button"
                                wire:click="closeProcedureCancellationModal"
                                class="button-minor px-8"
                            >
                                {{ __('forms.cancel') }}
                            </button>

                            <button
                                type="button"
                                wire:click="proceedToSignature"
                                wire:loading.attr="disabled"
                                wire:loading.class="opacity-50 cursor-not-allowed"
                                wire:target="proceedToSignature"
                                class="rounded-lg bg-red-600 px-8 py-3 font-semibold text-white transition-colors hover:bg-red-700"
                            >
                                <span wire:loading.remove wire:target="proceedToSignature">
                                    {{ __('procedures.messages.cancel_confirm_button') }}
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

    <x-signature-modal method="cancelSelectedProcedure" />
</div>
