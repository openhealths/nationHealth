<div x-data="{ open: false }"
     @open-episode-cancellation.window="open = true; $wire.set('cancellingEpisodeUuid', $event.detail.uuid, false); $wire.set('cancellationReason', '', false); $wire.set('explanatoryLetter', '', false);"
     @close-episode-cancellation.window="open = false;"
>
    <template x-teleport="body">
        <div x-show="open"
             x-cloak
             role="dialog"
             aria-modal="true"
             class="modal"
             @keydown.escape.prevent.stop="open = false"
        >
            <div x-transition.opacity
                 class="fixed inset-0 bg-black/30"
                 @click="open = false"
            ></div>

            <div class="modal-wrapper">
                <div class="modal-content w-full max-w-2xl mx-auto bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100"
                     @click.stop
                     x-transition
                     x-trap.noscroll.inert="open"
                >
                    <h3 class="mb-4 text-xl font-bold text-gray-900 dark:text-white">
                        {{ __('patients.messages.episode_cancel_modal_title') }}
                    </h3>

                    <p class="mb-6 text-sm text-gray-600 dark:text-gray-300 leading-relaxed">
                        {{ __('patients.messages.episode_cancel_modal_description') }}
                    </p>

                    <form class="space-y-4">
                        <div>
                            <label for="cancellationReason" class="label-modal">
                                {{ __('patients.messages.episode_cancel_reason_label') }} *
                            </label>

                            <select
                                class="input-modal"
                                wire:model="cancellationReason"
                                name="cancellationReason"
                                id="cancellationReason"
                            >
                                <option value="" class="bg-white text-gray-900 dark:bg-gray-800 dark:text-white">
                                    {{ __('patients.messages.episode_cancel_reason_placeholder') }}
                                </option>

                                @foreach(data_get($this->dictionaries, 'eHealth/cancellation_reasons', []) as $code => $label)
                                    <option value="{{ $code }}" class="bg-white text-gray-900 dark:bg-gray-800 dark:text-white" wire:key="episode-cancel-reason-{{ $code }}">
                                        {{ $label }}
                                    </option>
                                @endforeach
                            </select>

                            @error('cancellationReason')
                                <p class="text-error mt-1 text-xs">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="explanatoryLetter" class="label-modal">
                                {{ __('patients.messages.episode_cancel_explanation_label') }}
                            </label>

                            <textarea
                                wire:model="explanatoryLetter"
                                id="explanatoryLetter"
                                name="explanatoryLetter"
                                maxlength="255"
                                class="input-modal min-h-24 px-4 py-3 text-sm"
                                placeholder="{{ __('forms.write_comment_here') }}"
                            ></textarea>

                            @error('explanatoryLetter')
                                <p class="text-error mt-1 text-xs">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="flex gap-4 justify-start items-center pt-4 border-t border-gray-200 dark:border-gray-700">
                            <button type="button"
                                    @click="open = false"
                                    class="button-minor"
                            >
                                {{ __('forms.cancel') }}
                            </button>

                            <button type="button"
                                    wire:click="cancelSelectedEpisode"
                                    wire:loading.attr="disabled"
                                    wire:loading.class="opacity-50 cursor-not-allowed"
                                    wire:target="cancelSelectedEpisode"
                                    class="button-danger"
                            >
                                <span wire:loading.remove wire:target="cancelSelectedEpisode">
                                    {{ __('patients.messages.episode_cancel_confirm_button') }}
                                </span>

                                <span wire:loading wire:target="cancelSelectedEpisode">
                                    {{ __('forms.loading') ?? 'Завантаження...' }}
                                </span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </template>
</div>
