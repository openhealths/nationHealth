<div x-data="{ showCancellationModal: $wire.entangle('showCancellationModal') }">
    <template x-teleport="body">
        <div
            x-show="showCancellationModal"
            x-cloak
            role="dialog"
            aria-modal="true"
            class="modal"
            x-on:keydown.escape.prevent.stop="showCancellationModal = false"
        >
            <div
                x-transition.opacity
                class="fixed inset-0 bg-black/30"
                x-on:click="showCancellationModal = false"
            ></div>

            <div class="modal-wrapper">
                <div
                    class="modal-content mx-auto w-full max-w-2xl bg-white text-gray-900 dark:bg-gray-800 dark:text-gray-100"
                    x-on:click.stop
                    x-transition
                    x-trap.noscroll.inert="showCancellationModal"
                >
                    <h3 class="mb-4 text-xl font-bold text-gray-900 dark:text-white">
                        {{ __('medical-events.cancel_modal.title') }}
                    </h3>

                    <p class="mb-6 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
                        {{ __('episodes.cancel_modal_description') }}
                    </p>

                    <form class="space-y-4">
                        <div>
                            <label for="cancellationReason" class="label-modal">
                                {{ __('medical-events.cancel_modal.reason_label') }} *
                            </label>

                            <select
                                class="input-modal"
                                wire:model="cancellationForm.cancellationReason"
                                name="cancellationReason"
                                id="cancellationReason"
                            >
                                <option value="" class="bg-white text-gray-900 dark:bg-gray-800 dark:text-white">
                                    {{ __('medical-events.cancel_modal.reason_placeholder') }}
                                </option>

                                @foreach (data_get($this->dictionaries, 'eHealth/cancellation_reasons', []) as $code => $label)
                                    <option
                                        value="{{ $code }}"
                                        class="bg-white text-gray-900 dark:bg-gray-800 dark:text-white"
                                        wire:key="episode-cancel-reason-{{ $code }}"
                                    >
                                        {{ $label }}
                                    </option>
                                @endforeach
                            </select>

                            @error('cancellationForm.cancellationReason')
                                <p class="text-error mt-1 text-xs">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="explanatoryLetter" class="label-modal">
                                {{ __('medical-events.cancel_modal.explanation_label') }}
                            </label>

                            <textarea
                                wire:model="cancellationForm.explanatoryLetter"
                                id="explanatoryLetter"
                                name="explanatoryLetter"
                                maxlength="255"
                                class="input-modal min-h-24 px-4 py-3 text-sm"
                                placeholder="{{ __('forms.write_comment_here') }}"
                            ></textarea>

                            @error('cancellationForm.explanatoryLetter')
                                <p class="text-error mt-1 text-xs">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="flex items-center justify-start gap-4 border-t border-gray-200 pt-4 dark:border-gray-700">
                            <button type="button" x-on:click="showCancellationModal = false" class="button-minor">
                                {{ __('forms.cancel') }}
                            </button>

                            <button
                                type="button"
                                wire:click="cancelSelectedEpisode"
                                wire:loading.attr="disabled"
                                wire:loading.class="opacity-50 cursor-not-allowed"
                                wire:target="cancelSelectedEpisode"
                                class="button-danger"
                            >
                                <span wire:loading.remove wire:target="cancelSelectedEpisode">
                                    {{ __('medical-events.cancel_modal.confirm_button') }}
                                </span>

                                <span wire:loading wire:target="cancelSelectedEpisode">
                                    {{ __('general.loading') }}
                                </span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </template>
</div>
