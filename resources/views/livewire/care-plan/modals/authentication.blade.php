<div
    x-data="{
        showAuthModal: $wire.entangle('showAuthModal'),
        showMethodSelectionModal: $wire.entangle('showMethodSelectionModal'),
    }"
>
    <template x-teleport="body">
        <div
            x-show="showAuthModal"
            style="display: none"
            @keydown.escape.prevent.stop="showAuthModal = false"
            role="dialog"
            aria-modal="true"
            class="modal"
        >
            <div
                x-transition.opacity
                class="fixed inset-0 bg-black/30 backdrop-blur-sm"
                @click="showAuthModal = false"
            ></div>
            <div class="modal-wrapper">
                <div
                    @click.stop
                    x-trap.noscroll.inert="showAuthModal"
                    class="modal-content mx-auto w-full max-w-4xl rounded-2xl bg-white p-6 shadow-xl sm:p-8 dark:bg-gray-800"
                >
                    <div>
                        <legend class="legend mb-6 text-2xl font-bold text-gray-900 dark:text-white">
                            {{ __('care-plan.auth_modal_title') }}
                        </legend>

                        <div class="mb-6">
                            <p class="text-sm text-gray-600 dark:text-gray-400">
                                {{ __('care-plan.sms_sent_to_number') }}
                                <strong class="ml-1 text-gray-900 dark:text-white">{{ $phoneNumber ?? '-' }}</strong>
                            </p>
                        </div>

                        <div class="pt-2">
                            <h3 class="mb-6 text-xl font-bold text-gray-900 dark:text-white">
                                {{ __('patients.code_sms') }}
                            </h3>

                            <div class="flex items-end gap-4" style="display: flex; align-items: flex-end">
                                <div
                                    class="form-group group !mb-0"
                                    style="flex: 0 1 320px; max-width: 320px; width: 100%"
                                >
                                    <input
                                        type="text"
                                        placeholder=" "
                                        class="peer input @error('verificationCode') input-error @enderror"
                                        wire:model="verificationCode"
                                        id="verificationCode"
                                        autocomplete="off"
                                        inputmode="numeric"
                                    />
                                    <label class="label" for="verificationCode">
                                        {{ __('forms.confirmation_code_from_SMS') }}
                                    </label>
                                    @error('verificationCode')
                                        <p class="text-error">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div
                                    x-data="{
                                        timer: 60,
                                        interval: null,
                                        init() {
                                            this.startTimer();
                                            this.$watch('$wire.showAuthModal', (value) => {
                                                if (value) this.startTimer();
                                            });
                                        },
                                        startTimer() {
                                            this.timer = 60;
                                            if (this.interval) clearInterval(this.interval);
                                            this.interval = setInterval(() => {
                                                if (this.timer > 0) {
                                                    this.timer--;
                                                } else {
                                                    clearInterval(this.interval);
                                                }
                                            }, 1000);
                                        },
                                        resetTimer() {
                                            if (this.timer === 0) {
                                                this.startTimer();
                                                $wire.resendSms();
                                            }
                                        },
                                    }"
                                    class="shrink-0"
                                >
                                    <button
                                        type="button"
                                        :disabled="timer > 0"
                                        @click="resetTimer()"
                                        class="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2.5 text-sm whitespace-nowrap transition-colors disabled:opacity-70 dark:border-gray-600 dark:bg-gray-700"
                                        :class="timer > 0
                                            ? 'cursor-not-allowed text-gray-400 dark:text-gray-400'
                                            : 'hover:bg-gray-50 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 cursor-pointer'"
                                    >
                                        @icon('mail', 'w-4 h-4 text-gray-600 dark:text-gray-300')
                                        <span>
                                            <span x-show="timer > 0"
                                                >{{ __('patients.resend_again_in_seconds') }}
                                                <span x-text="timer"></span> {{ __('patients.seconds_short') }}</span>
                                            <span x-show="timer === 0">{{ __('forms.send_again') }}</span>
                                        </span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="mt-12 flex items-center justify-end gap-4">
                            <button type="button" @click="showAuthModal = false" class="button-minor px-6 py-2.5">
                                {{ __('forms.cancel') ?? 'Скасувати' }}
                            </button>

                            <button type="button" wire:click="verify" class="button-primary px-6 py-2.5">
                                {{ __('forms.confirm') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>
