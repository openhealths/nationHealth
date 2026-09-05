<div x-data="{ showClosingModal: $wire.entangle('showClosingModal') }">
    <template x-teleport="body">
        <div
            x-show="showClosingModal"
            x-cloak
            role="dialog"
            aria-modal="true"
            class="modal"
            x-on:keydown.escape.prevent.stop="showClosingModal = false"
        >
            <div x-transition.opacity
                 class="fixed inset-0 bg-black/30"
                 x-on:click="showClosingModal = false"
            ></div>

            <div class="modal-wrapper">
                <div
                    class="modal-content w-full max-w-2xl mx-auto bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100"
                    x-on:click.stop
                    x-transition
                    x-trap.noscroll.inert="showClosingModal"
                >
                    <h3 class="mb-4 text-xl font-bold text-gray-900 dark:text-white">
                        {{ __('episodes.close_modal_title') }}
                    </h3>

                    <p class="mb-6 text-sm text-gray-600 dark:text-gray-300 leading-relaxed">
                        {{ __('episodes.close_modal_description') }}
                    </p>

                    <form class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="closingDate" class="label-modal">
                                    {{ __('episodes.close_date_label') }} *
                                </label>

                                <input
                                    wire:model="closingForm.closingDate"
                                    datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                                    type="text"
                                    name="closingDate"
                                    id="closingDate"
                                    class="datepicker-input input-modal"
                                    autocomplete="off"
                                >

                                @error('closingForm.closingDate')
                                <p class="text-error mt-1 text-xs">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="closingTime" class="label-modal">
                                    {{ __('episodes.close_time_label') }} *
                                </label>

                                <input
                                    wire:model="closingForm.closingTime"
                                    type="text"
                                    name="closingTime"
                                    id="closingTime"
                                    class="timepicker-uk input-modal"
                                    autocomplete="off"
                                >

                                @error('closingForm.closingTime')
                                <p class="text-error mt-1 text-xs">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        <div>
                            <label for="closingReason" class="label-modal">
                                {{ __('episodes.close_reason_label') }} *
                            </label>

                            <select
                                class="input-modal"
                                wire:model="closingForm.closingReason"
                                name="closingReason"
                                id="closingReason"
                            >
                                <option value="" class="bg-white text-gray-900 dark:bg-gray-800 dark:text-white">
                                    {{ __('episodes.close_reason_placeholder') }}
                                </option>

                                @foreach(data_get($this->dictionaries, 'eHealth/episode_closing_reasons', []) as $code => $label)
                                    <option
                                        value="{{ $code }}"
                                        class="bg-white text-gray-900 dark:bg-gray-800 dark:text-white"
                                        wire:key="episode-close-reason-{{ $code }}"
                                    >
                                        {{ $label }}
                                    </option>
                                @endforeach
                            </select>

                            @error('closingForm.closingReason')
                            <p class="text-error mt-1 text-xs">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="closingSummary" class="label-modal">
                                {{ __('episodes.close_summary_label') }}
                            </label>

                            <textarea
                                wire:model="closingForm.closingSummary"
                                id="closingSummary"
                                name="closingSummary"
                                maxlength="1000"
                                class="input-modal min-h-24 px-4 py-3 text-sm"
                                placeholder="{{ __('episodes.close_summary_placeholder') }}"
                            ></textarea>

                            @error('closingForm.closingSummary')
                            <p class="text-error mt-1 text-xs">{{ $message }}</p>
                            @enderror
                        </div>

                        <div
                            class="flex gap-4 justify-start items-center pt-4 border-t border-gray-200 dark:border-gray-700">
                            <button
                                type="button"
                                x-on:click="showClosingModal = false"
                                class="button-minor"
                            >
                                {{ __('forms.cancel') }}
                            </button>

                            <button
                                type="button"
                                wire:click="closeSelectedEpisode"
                                wire:loading.attr="disabled"
                                wire:loading.class="opacity-50 cursor-not-allowed"
                                wire:target="closeSelectedEpisode"
                                class="button-primary"
                            >
                                <span wire:loading.remove wire:target="closeSelectedEpisode">
                                    {{ __('episodes.close_confirm_button') }}
                                </span>

                                <span wire:loading wire:target="closeSelectedEpisode">
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
