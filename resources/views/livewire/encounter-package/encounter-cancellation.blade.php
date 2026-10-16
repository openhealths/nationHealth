@php($formPath = $formPath ?? 'form')

<div x-data="{ showCancellationModal: $wire.entangle('showCancellationModal') }">
    <template x-teleport="body">
        <div
            x-show="showCancellationModal"
            x-cloak
            role="dialog"
            aria-modal="true"
            class="modal"
            @keydown.escape.prevent.stop="$wire.closeEncounterCancellationModal()"
        >
            <div
                x-transition.opacity
                class="fixed inset-0 bg-black/30"
                @click="$wire.closeEncounterCancellationModal()"
            ></div>

            <div class="modal-wrapper">
                <div
                    class="modal-content mx-auto w-full max-w-4xl bg-white text-gray-900 dark:bg-gray-800 dark:text-gray-100"
                    @click.stop
                    x-transition
                    x-trap.noscroll.inert="showCancellationModal"
                >
                    <h3 class="mb-4 text-xl font-bold text-gray-900 dark:text-white">
                        {{ __('medical-events.cancel_modal.title') }}
                    </h3>

                    <p class="mb-6 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
                        {{ $description ?? __('encounters.cancel_modal_description') }}
                    </p>

                    @isset($note)
                        <p class="-mt-2 mb-6 text-sm leading-relaxed text-amber-700 dark:text-amber-400">{{ $note }}</p>
                    @endisset

                    <form class="space-y-4">
                        <div>
                            <label for="encounterCancellationReason" class="label-modal">
                                {{ __('medical-events.cancel_modal.reason_label') }} *
                            </label>

                            <select
                                class="input-modal"
                                wire:model="{{ $formPath }}.cancellationReason"
                                name="encounterCancellationReason"
                                id="encounterCancellationReason"
                            >
                                <option value="" class="bg-white text-gray-900 dark:bg-gray-800 dark:text-white">
                                    {{ __('medical-events.cancel_modal.reason_placeholder') }}
                                </option>

                                @foreach (data_get($this->dictionaries, 'eHealth/cancellation_reasons', []) as $code => $label)
                                    <option
                                        value="{{ $code }}"
                                        class="bg-white text-gray-900 dark:bg-gray-800 dark:text-white"
                                        wire:key="encounter-cancel-reason-{{ $code }}"
                                    >
                                        {{ $label }}
                                    </option>
                                @endforeach
                            </select>

                            @error($formPath . '.cancellationReason')
                                <p class="text-error mt-1 text-xs">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="encounterExplanatoryLetter" class="label-modal">
                                {{ __('medical-events.cancel_modal.explanation_label') }} *
                            </label>

                            <textarea
                                wire:model="{{ $formPath }}.explanatoryLetter"
                                id="encounterExplanatoryLetter"
                                name="encounterExplanatoryLetter"
                                maxlength="255"
                                class="input-modal min-h-24 px-4 py-3 text-sm"
                                placeholder="{{ __('forms.write_comment_here') }}"
                            ></textarea>

                            @error($formPath . '.explanatoryLetter')
                                <p class="text-error mt-1 text-xs">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="flex items-center justify-start gap-4 border-t border-gray-200 pt-4 dark:border-gray-700">
                            <button type="button" wire:click="closeEncounterCancellationModal" class="button-minor">
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

                                <span wire:loading wire:target="proceedToSignature"> {{ __('forms.loading') }} </span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </template>

    <x-signature-modal method="cancelSelectedEncounter" :only-actions="['cancel_encounter']" />
</div>
