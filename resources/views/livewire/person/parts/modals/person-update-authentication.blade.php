<div x-data="{ showAuthModal: $wire.entangle('showConfirmationUpdateModal') }">
    <template x-teleport="body">
        <div
            x-cloak
            x-show="showAuthModal"
            style="display: none"
            @keydown.escape.prevent.stop="showAuthModal = false"
            role="dialog"
            aria-modal="true"
            class="modal"
        >
            <div x-transition.opacity class="fixed inset-0 bg-black/30"></div>
            <div x-transition @click="showAuthModal = false" class="modal-wrapper">
                <div @click.stop x-trap.noscroll.inert="showAuthModal" class="modal-content mx-auto w-full max-w-2xl">
                    <legend class="legend mb-8 text-2xl font-bold">{{ __('forms.authentication') }}</legend>

                    <div
                        class="form-row-3"
                        x-data="{
                            timer: 0,
                            interval: null,
                            init() {
                                this.$watch('showAuthModal', (isOpen) => {
                                    if (isOpen) this.startTimer();
                                });
                            },
                            startTimer() {
                                this.timer = 60;
                                clearInterval(this.interval);
                                this.interval = setInterval(() => {
                                    if (this.timer > 0) this.timer--;
                                }, 1000);
                            },
                            resendCode() {
                                if (this.timer === 0) {
                                    this.startTimer();
                                    $wire.resendApprovalSmsCode();
                                }
                            },
                        }"
                    >
                        <div class="form-group group">
                            <input
                                type="text"
                                wire:model="form.verificationCode"
                                inputmode="numeric"
                                name="verificationCode"
                                id="verificationCode"
                                class="peer input @error('form.verificationCode') input-error @enderror"
                                placeholder=" "
                                autocomplete="off"
                                maxlength="4"
                                x-mask="9999"
                            />
                            <label for="verificationCode" class="label">
                                {{ __('forms.confirmation_code_from_SMS') }}
                            </label>

                            @error('form.verificationCode')
                                <p class="text-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <button type="button" @click="resendCode()" :disabled="timer > 0" class="button-minor gap-2">
                            @icon('mail', 'w-4 h-4')
                            <span x-show="timer > 0">
                                {{ __('patients.resend_again_in_seconds') }}
                                <span x-text="timer"></span>
                                {{ __('patients.seconds_short') }}
                            </span>
                            <span x-show="timer === 0">{{ __('forms.send_again') }}</span>
                        </button>
                    </div>

                    <div class="mt-8 flex gap-3">
                        <button type="button" @click="showAuthModal = false" class="button-minor">
                            {{ __('forms.cancel') }}
                        </button>

                        <button
                            type="button"
                            wire:click="approvePersonalDataUpdate"
                            :disabled="($wire.form.verificationCode ?? '').length !== 4"
                            class="button-primary"
                        >
                            {{ __('forms.confirm') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>
