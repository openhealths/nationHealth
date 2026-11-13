{{-- Scrolling is locked with a class, not x-trap.noscroll: the signature modal opens in the same response as this one
     closes, and noscroll of one would restore the locked overflow saved by the other, leaving the page unscrollable --}}
<div
    x-data="{ showCancellationModal: $wire.entangle('showCancellationModal') }"
    x-effect="document.body.classList.toggle('overflow-hidden', showCancellationModal)"
    @open-specimen-cancellation.window="$wire.open($event.detail.id)"
>
    <livewire:components.x-message :key="time()" />

    <template x-teleport="body">
        <div
            x-show="showCancellationModal"
            x-cloak
            role="dialog"
            aria-modal="true"
            class="modal"
            @keydown.escape.prevent.stop="showCancellationModal = false"
        >
            <div
                x-transition.opacity
                class="fixed inset-0 bg-black/30"
                @click="showCancellationModal = false"
            ></div>

            <div class="modal-wrapper">
                <div
                    class="modal-content mx-auto w-full max-w-2xl bg-white text-gray-900 dark:bg-gray-800 dark:text-gray-100"
                    @click.stop
                    x-transition
                    x-trap.inert="showCancellationModal"
                >
                    <h3 class="mb-4 text-xl font-bold text-gray-900 dark:text-white">
                        {{ __('medical-events.cancel_modal.title') }}
                    </h3>

                    <p class="mb-6 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
                        {{ __('specimens.cancel_modal_description') }}
                    </p>

                    <form class="space-y-4">
                        <div>
                            <label for="cancellationReason" class="label-modal">
                                {{ __('medical-events.cancel_modal.reason_label') }} *
                            </label>

                            <select
                                class="input-modal"
                                wire:model="form.cancellationReason"
                                name="cancellationReason"
                                id="cancellationReason"
                            >
                                <option value="" class="bg-white text-gray-900 dark:bg-gray-800 dark:text-white">
                                    {{ __('medical-events.cancel_modal.reason_placeholder') }}
                                </option>

                                @foreach ($dictionaries['specimen_cancel_reasons'] ?? [] as $code => $label)
                                    <option
                                        value="{{ $code }}"
                                        class="bg-white text-gray-900 dark:bg-gray-800 dark:text-white"
                                        wire:key="specimen-cancel-reason-{{ $code }}"
                                    >
                                        {{ $label }}
                                    </option>
                                @endforeach
                            </select>

                            @error('form.cancellationReason')
                                <p class="text-error mt-1 text-xs">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="flex items-center justify-start gap-4 border-t border-gray-200 pt-4 dark:border-gray-700">
                            <button type="button" @click="showCancellationModal = false" class="button-minor">
                                {{ __('forms.cancel') }}
                            </button>

                            <button
                                type="button"
                                wire:click="proceedToSignature"
                                wire:loading.attr="disabled"
                                wire:loading.class="opacity-50 cursor-not-allowed"
                                wire:target="proceedToSignature"
                                class="button-danger"
                            >
                                <span wire:loading.remove wire:target="proceedToSignature">
                                    {{ __('medical-events.cancel_modal.confirm_button') }}
                                </span>

                                <span wire:loading wire:target="proceedToSignature">
                                    {{ __('general.loading') }}
                                </span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </template>

    <x-signature-modal method="cancel" />
</div>
